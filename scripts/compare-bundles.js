/**
 * Prove that two legacy JavaScript bundles are the same program.
 *
 * The legacy bundles are served as plain concatenations, so reformatting a source
 * file changes the shipped bytes even though nothing about the code changed. This
 * compares the parse trees of two bundles and reports the first real difference,
 * which is what makes a reorganisation reviewable without a browser.
 *
 * Usage: node scripts/compare-bundles.js <before.js> <after.js>
 */

import * as fs from "node:fs";
import * as acorn from "acorn";
import { displayPath, projectPath } from "./lib/asset-workflow.js";

const PARSE = {
  ecmaVersion: 2020,
  sourceType: "script",
  allowReturnOutsideFunction: true,
  allowAwaitOutsideFunction: true,
  allowHashBang: true,
};

// Locations, comments and raw literal spelling are dropped, as are empty
// statements: a `;` after a function declaration is a no-op. Anything else that
// differs is a real change in behaviour.
function normalize(value) {
  if (Array.isArray(value)) {
    return value.map(normalize).filter((child) => !child || child.type !== "EmptyStatement");
  }

  if (value instanceof RegExp) {
    return value.toString();
  }

  if (value && typeof value === "object") {
    const copy = {};
    for (const [key, entry] of Object.entries(value)) {
      if (key === "start" || key === "end" || key === "loc" || key === "range" || key === "raw") continue;
      copy[key] = normalize(entry);
    }
    return copy;
  }

  return value;
}

function signature(file) {
  return JSON.stringify(normalize(acorn.parse(fs.readFileSync(file, "utf8"), PARSE)));
}

const before = projectPath(process.argv[2], "before");
const after = projectPath(process.argv[3], "after");

const beforeSignature = signature(before);
const afterSignature = signature(after);

if (beforeSignature === afterSignature) {
  console.log(displayPath(before) + " and " + displayPath(after) + " parse to the same program.");
  process.exit(0);
}

console.log("Parse trees differ:");
for (let index = 0; index < Math.max(beforeSignature.length, afterSignature.length); index++) {
  if (beforeSignature[index] !== afterSignature[index]) {
    console.log("  before: ..." + beforeSignature.slice(Math.max(0, index - 120), index + 160));
    console.log("  after:  ..." + afterSignature.slice(Math.max(0, index - 120), index + 160));
    break;
  }
}

process.exitCode = 1;
