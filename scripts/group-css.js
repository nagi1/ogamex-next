/**
 * Produce a reviewable, area-oriented split of a legacy CSS bundle.
 *
 * This is the CSS counterpart of scripts/group-javascript.js and follows the same
 * plan: it preserves source order and bytes exactly, records the order in a
 * manifest, and never changes Vite inputs or production assets on its own. The
 * sibling validate-chunks.js script proves the split is byte-perfect.
 *
 * Unlike the JavaScript split, chunks are packed to a target size instead of one
 * per rule, and named after the area that dominates them rather than numbered.
 * Boundaries only ever fall where one area run ends and the next begins, so the
 * cascade is untouched. Regrouping the sheet by area instead would move rules
 * across each other and change which declaration wins for hundreds of selectors,
 * so the split deliberately stays in source order.
 *
 * Usage: node scripts/group-css.js [--target=120000]
 */

import * as fs from "node:fs";
import * as path from "node:path";
import postcss from "postcss";
import { displayPath, options, projectPath } from "./lib/asset-workflow.js";
import { areaVocabulary } from "./lib/css-areas.js";

const args = options({
  input: "resources/css/ingame/469500b3cd5158332fb20a56b14b2c.css",
  output: "tmp/ingame-css-chunks",
  plan: "tmp/ingame-css-plan.json",
  target: 120000,
  areas: "ingame",
});
const INPUT = projectPath(args.input, "input");
const OUTPUT_DIR = projectPath(args.output, "output");
const PLAN = projectPath(args.plan, "plan");
const MANIFEST = path.join(OUTPUT_DIR, "manifest.json");
const INDEX = path.join(OUTPUT_DIR, "index.css");

// OGame screen areas, most specific first.
const areas = areaVocabulary(args.areas);


const code = fs.readFileSync(INPUT, "utf8");
const root = postcss.parse(code, { from: INPUT });

function classify(selectors) {
  for (const [area, pattern] of areas) {
    if (selectors.some((selector) => pattern.test(selector))) return area;
  }
  return "common";
}

function slug(value) {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
}

// Comments are trivia, not chunks. Leaving them out of the grouping pass keeps a
// comment inside the chunk that follows it (the cursor-based write below slices
// from the previous node's end), so nothing is dropped or reordered.
const candidateNodes = root.nodes.filter((node) => node.type !== "comment");

const statements = candidateNodes.map((node) => ({
  area: classify(node.selectors ?? []),
  selector: (node.selector ?? node.name ?? node.type).trim().replace(/\s+/g, " "),
  classes: (node.selector?.match(/\.[A-Za-z][A-Za-z0-9_-]+/g) ?? []).map((token) => slug(token.slice(1))),
  start: node.source.start.offset,
  end: node.source.end.offset,
  startLine: node.source.start.line,
  endLine: node.source.end.line,
}));

// Maximal runs of one area. Chunk boundaries may only fall on a run edge, so a
// chunk mixes areas exactly as much as the original sheet interleaves them.
const runs = [];
for (const statement of statements) {
  const current = runs.at(-1);
  if (current && current.area === statement.area) {
    current.end = statement.end;
    current.endLine = statement.endLine;
    current.selectors.push(statement.selector);
    current.classes.push(...statement.classes);
    current.ruleCount++;
    continue;
  }

  runs.push({
    area: statement.area,
    start: statement.start,
    end: statement.end,
    startLine: statement.startLine,
    endLine: statement.endLine,
    selectors: [statement.selector],
    classes: [...statement.classes],
    ruleCount: 1,
  });
}

// Pack consecutive runs into a handful of chunks. A modern stylesheet ships a
// small set of files rather than one file per rule, so a chunk closes at an area
// edge once it reaches the target size. Slicing on run edges keeps source order,
// and therefore the cascade, untouched.
const targetBytes = Math.max(4096, Number(args.target) || 120000);

function packRuns(runs, target) {
  const slabs = [];
  let slab = null;

  for (const run of runs) {
    const runBytes = run.end - run.start;
    if (slab && slab.bytes + runBytes > target) {
      slabs.push(slab);
      slab = null;
    }
    if (!slab) slab = { bytes: 0, runs: [] };
    slab.runs.push(run);
    slab.bytes += runBytes;
  }
  if (slab) slabs.push(slab);

  // A short tail would become a near-empty file; fold it into its neighbour.
  if (slabs.length > 1 && slabs.at(-1).bytes < target / 4) {
    const tail = slabs.pop();
    for (const run of tail.runs) {
      slabs.at(-1).runs.push(run);
      slabs.at(-1).bytes += run.end - run.start;
    }
  }

  return slabs;
}

const slabs = packRuns(runs, targetBytes);

const usedPaths = new Set();
const chunks = slabs.map((slab) => {
  const first = slab.runs[0];
  const last = slab.runs.at(-1);
  const counts = new Map();
  const classCounts = new Map();
  const selectors = [];
  let ruleCount = 0;

  for (const run of slab.runs) {
    counts.set(run.area, (counts.get(run.area) ?? 0) + run.ruleCount);
    ruleCount += run.ruleCount;
    for (const token of run.classes) classCounts.set(token, (classCounts.get(token) ?? 0) + 1);
    if (selectors.length < 30) {
      selectors.push(...run.selectors.slice(0, 30 - selectors.length));
    }
  }

  // Name the chunk after the most specific area inside it; "common" is the
  // leftover bucket and never names a file on its own. Two chunks that share a
  // leading area are told apart by their next area or their busiest class.
  const ranked = [...counts.entries()].sort((a, b) => b[1] - a[1]).map(([area]) => area);
  const specific = ranked.filter((area) => area !== "common");
  const lead = specific[0] ?? "common";
  const busiestClass = [...classCounts.entries()].sort((a, b) => b[1] - a[1])[0]?.[0];
  const candidates = [
    lead,
    specific[1] ? lead + "-" + specific[1] : null,
    busiestClass ? lead + "-" + busiestClass : null,
    "common-" + lead,
  ].filter(Boolean);

  const module = candidates.find((candidate) => !usedPaths.has(candidate));
  usedPaths.add(module);

  return {
    area: lead,
    areas: ranked,
    module,
    path: module + ".css",
    start: first.start,
    end: last.end,
    startLine: first.startLine,
    endLine: last.endLine,
    ruleCount,
    selectors,
  };
});

fs.rmSync(OUTPUT_DIR, { recursive: true, force: true });
fs.mkdirSync(OUTPUT_DIR, { recursive: true });

let cursor = 0;
for (const chunk of chunks) {
  fs.writeFileSync(path.join(OUTPUT_DIR, chunk.path), code.slice(cursor, chunk.end));
  cursor = chunk.end;
}
if (cursor < code.length) {
  fs.appendFileSync(path.join(OUTPUT_DIR, chunks.at(-1).path), code.slice(cursor));
}

const manifest = {
  description: "Area-oriented, source-order-preserving in-game CSS split",
  source: displayPath(INPUT),
  targetBytes,
  chunks,
};
fs.writeFileSync(MANIFEST, JSON.stringify(manifest, null, 2));

// The ordered import list is the CSS counterpart of the JavaScript manifest lookup
// in vite.config.js: browsers and Vite resolve @import natively, so the build reads
// its order from here instead of a bespoke concatenation step.
const importList = [
  "/* Cascade order for the legacy in-game sheet. Generated by scripts/group-css.js. */",
  ...chunks.map((chunk) => '@import "' + chunk.path + '";'),
];
fs.writeFileSync(INDEX, importList.join("\n") + "\n");

const plan = {
  source: displayPath(INPUT),
  generatedAt: new Date().toISOString(),
  grouping: "CSS top-level rules classified by OGame screen area, packed into ~" + targetBytes + "-byte chunks at area edges",
  caveat: "This is a review aid. Chunks preserve source order, so a chunk carries its neighbouring areas too.",
  chunks: manifest.chunks,
};
fs.mkdirSync(path.dirname(PLAN), { recursive: true });
fs.writeFileSync(PLAN, JSON.stringify(plan, null, 2));

console.log("Generated " + chunks.length + " source-ordered chunks of about " + targetBytes + " bytes:");
for (const chunk of chunks) {
  console.log("  " + chunk.path.padEnd(28) + chunk.area.padEnd(12) + chunk.areas.slice(0, 4).join(", "));
}
console.log("Source bytes: " + code.length);
console.log("Review plan: " + PLAN);
console.log("Chunks: " + OUTPUT_DIR);
console.log("Import list: " + INDEX);
