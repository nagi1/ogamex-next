/**
 * Re-indent a minified legacy JavaScript file without changing a single token.
 *
 * The out-game scripts are minified onto one line, which makes them unreviewable.
 * This is the JavaScript counterpart of beautify-css.js: terser re-prints the file
 * with real formatting, and the result is written only if its parse tree matches the
 * input's, so no identifier, literal, operator or statement can change. Comparing
 * trees rather than text deliberately allows what the parser already normalises —
 * an inserted semicolon where ASI applied, or a literal spelled differently at the
 * same value — while any real edit is rejected.
 *
 * Usage:
 *   node scripts/beautify-js.js --input=resources/js/outgame/x.js
 *   node scripts/beautify-js.js --input=resources/js/outgame/x.js --output=tmp/x.js
 */

import * as fs from "node:fs";
import * as acorn from "acorn";
import { minify } from "terser";
import { displayPath, options, projectPath } from "./lib/asset-workflow.js";

const args = options({});
const INPUT = projectPath(args.input, "input");
const OUTPUT = projectPath(args.output ?? args.input, "output");

if (!args.input) {
  throw new Error("beautify-js needs --input=<file> [--output=<file>]");
}

const PARSE = {
  ecmaVersion: 2020,
  sourceType: "script",
  allowReturnOutsideFunction: true,
  allowAwaitOutsideFunction: true,
  allowHashBang: true,
};

// Parse tree with every location, comment and raw literal spelling dropped. Two
// files with equal signatures differ only in how they are written down. Empty
// statements go too: a `;` after a function declaration is a no-op the printer is
// free to drop, and it cannot change behaviour.
function normalize(value) {
  if (Array.isArray(value)) {
    return value
      .map(normalize)
      .filter((child) => !child || child.type !== "EmptyStatement");
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

function signature(code) {
  return JSON.stringify(normalize(acorn.parse(code, PARSE)));
}

const source = fs.readFileSync(INPUT, "utf8");
const result = await minify(source, {
  compress: false,
  mangle: false,
  format: { beautify: true, comments: "all", indent_level: 4, semicolons: true },
});

if (result.error) {
  throw result.error;
}

const beautified = result.code.endsWith("\n") ? result.code : result.code + "\n";

if (signature(source) !== signature(beautified)) {
  throw new Error("Refusing to write: the parse tree changed, so this is not formatting-only.");
}

fs.writeFileSync(OUTPUT, beautified);
console.log(
  displayPath(INPUT) +
    ": " +
    source.length.toLocaleString() +
    " -> " +
    beautified.length.toLocaleString() +
    " bytes, parse tree identical",
);
