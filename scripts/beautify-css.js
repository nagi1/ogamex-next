/**
 * Re-indent a minified legacy CSS file without touching a single token.
 *
 * The legacy out-game sheets are minified onto one line, which makes them
 * unreviewable and impossible to split into readable chunks. This is the CSS
 * counterpart of the JavaScript split's beautify phase: it rewrites only whitespace
 * (indentation, line breaks, the space inside selector lists) and refuses to write
 * unless the parsed structure is identical to the input, so it cannot change a
 * selector, a property or a value.
 *
 * Usage:
 *   node scripts/beautify-css.js --input=resources/css/outgame/x.css
 *   node scripts/beautify-css.js --input=resources/css/outgame/x.css --output=tmp/x.css
 */

import * as fs from "node:fs";
import postcss from "postcss";
import { displayPath, options, projectPath } from "./lib/asset-workflow.js";

const args = options({});
const INPUT = projectPath(args.input, "input");
const OUTPUT = projectPath(args.output ?? args.input, "output");
const INDENT = "    ";

if (!args.input) {
  throw new Error("beautify-css needs --input=<file> [--output=<file>]");
}

function pad(depth) {
  return INDENT.repeat(depth);
}

// Rewrites raws only. Selector lists get one selector per line; every other token
// is left exactly as parsed.
function reindent(container, depth) {
  container.nodes.forEach((node, index) => {
    const first = index === 0 && depth === 0;
    node.raws.before = first ? "" : (depth === 0 ? "\n\n" : "\n") + pad(depth);

    if (node.type === "decl") {
      node.raws.between = ": ";
      if (node.raws.important) node.raws.important = " !important";
      return;
    }

    if (node.type === "comment") {
      node.raws.left = " ";
      node.raws.right = " ";
      return;
    }

    if (node.type === "rule") {
      node.selector = node.selectors.map((selector) => selector.trim()).join(",\n" + pad(depth));
      node.raws.between = " ";
      node.raws.after = "\n" + pad(depth);
      node.raws.semicolon = true;
      reindent(node, depth + 1);
      return;
    }

    if (node.type === "atrule") {
      node.raws.afterName = " ";
      node.raws.semicolon = false;
      if (node.nodes) {
        node.raws.between = " ";
        node.raws.after = "\n" + pad(depth);
        reindent(node, depth + 1);
      } else {
        node.raws.between = "";
        node.raws.after = "";
      }
    }
  });
}

// Structure without any whitespace: two files with equal dumps differ only in
// formatting, which is what makes this safe on a checked-in asset.
function structure(root) {
  const collapse = (value) => String(value).replace(/\s+/g, " ").trim();
  const walk = (container) =>
    container.nodes.map((node) => {
      if (node.type === "rule") {
        return ["rule", node.selectors.map(collapse).join(","), walk(node)];
      }
      if (node.type === "decl") {
        return ["decl", node.prop, collapse(node.value), node.important === true];
      }
      if (node.type === "comment") {
        return ["comment", collapse(node.text)];
      }
      if (node.type === "atrule") {
        return ["atrule", node.name, collapse(node.params ?? ""), node.nodes ? walk(node) : []];
      }
      return [node.type];
    });

  return JSON.stringify(walk(root));
}

const original = fs.readFileSync(INPUT, "utf8");
const root = postcss.parse(original, { from: INPUT });
reindent(root, 0);
const beautified = root.toString();

if (structure(postcss.parse(original, { from: INPUT })) !== structure(postcss.parse(beautified, { from: INPUT }))) {
  throw new Error("Refusing to write: beautified CSS is not structurally identical to " + displayPath(INPUT));
}

fs.writeFileSync(OUTPUT, beautified);
console.log(displayPath(INPUT) + ": " + original.length.toLocaleString() + " -> " + beautified.length.toLocaleString() + " bytes");
console.log("Wrote " + displayPath(OUTPUT) + " (" + beautified.split("\n").length.toLocaleString() + " lines)");
