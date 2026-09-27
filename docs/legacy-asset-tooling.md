# Legacy asset tooling

How the legacy OGame front-end assets are stored, split, verified and edited.

The four bundles (in-game/out-game × CSS/JS) used to be opaque: one 1.1 MB CSS
sheet, one 1.4 MB JavaScript file, and twelve hash-named out-game scripts with no
record of which were ours and which were vendor. They are now split into named,
readable files, with the original files kept next to the split for reference and a
script per bundle that proves nothing changed. This document is the map, the rules
and the traps.

Nothing here rewrites the game. The code is still the original legacy code; what
changed is how it is stored and reviewed.

## The map

| Bundle | Source of truth | Split | Order lives in | Check |
| --- | --- | --- | --- | --- |
| In-game CSS | `resources/css/ingame/469500b3cd5158332fb20a56b14b2c.css` (50,804 lines) | 10 chunks in `resources/css/ingame/chunks/` | generated `chunks/index.css` + `chunks/manifest.json` | `npm run ingame:css:validate` |
| Out-game CSS | `resources/css/outgame/175d13751348e190f1ff2a51466c5f.css` (1,993 lines) | 6 chunks in `resources/css/outgame/chunks/` | generated `chunks/index.css` + `chunks/manifest.json` | `npm run outgame:css:validate` |
| In-game JS | `resources/js/ingame/e7c74974620fa35b197315ebdbb8c2.js` | 283 chunks in `resources/js/ingame/chunks/<area>/` | `chunks/manifest.json`, spliced into `ingameScripts` | `npm run ingame:validate` |
| Out-game JS | `resources/js/outgame/*.js` (7 vendor + 5 project) | 5 project files in `resources/js/outgame/chunks/` | `outgameScripts` in `vite.config.js` | `scripts/compare-bundles.js` |

The CSS entries pull the split in through one generated line each:

- `resources/css/ingame.css` line 10 — `@import "ingame/chunks/index.css";`, in the
  slot the monolithic sheet used to occupy.
- `resources/css/outgame.css` line 8 — `@import "outgame/chunks/index.css";`, same.

## The one rule: order is load-bearing

CSS has no module system. The winner between two equal-specificity rules is
whoever comes last, and this sheet leans on that everywhere — the in-game sheet
overrides itself constantly:

| Measure (in-game sheet, 7,714 rules / 30,128 declarations) | Value |
| --- | --- |
| Distinct selectors | 9,987 |
| Selectors declared more than once | 896 |
| `(selector, property)` pairs declared more than once | 2,022 |
| …of those where the later value differs (a real override) | 1,116 |
| Declarations that never win (a later one replaces them) | 2,666 (~9%) |
| `!important` declarations | 499 |
| Self-override pairs whose rules sit in two different chunk files | 91 of 200 sampled |

The usual shape is a reset at the top of the sheet and later rules that restyle
what it set: `body { font-size: 100% }` loses to `12px`, `em { font-style:
inherit }` to `italic`, `h3 { font-weight: inherit }` to `400`, `ol { margin: 0 }`
to `20px 0 20px 20px`.

So:

- **Never reorder, sort or regroup these rules.** Regrouping the in-game sheet by
  area — one `fleet.css`, one `galaxy.css` — moves rules across each other and
  changes which declaration wins for **hundreds** of selector/property pairs
  (`div.anythingSlider` vs `div.detail_screen` on `display`, `body`/`h3`/`em`
  against the reset). It is the one change that cannot be verified without
  clicking through every screen.
- **A chunk name describes where a chunk starts, not everything inside it.** A
  chunk is a contiguous slice, so it carries its neighbouring areas too. The
  manifest's `areas` array is the honest answer to "what is in this file".
- **Expect a selector to appear more than once.** Nearly half of the repeated
  selectors straddle two chunk files, so grep before editing — the rule in front
  of you may not be the one that wins.
- **The `2,666` losing declarations are dead weight.** They are the cheapest thing
  to delete while migrating, but only where the cascade says so.

## In-game CSS

    # refresh the committed split (deterministic; rewrites identical files)
    npm run ingame:css:group
    # byte-identical reassembly + per-chunk parse + import-list drift check
    npm run ingame:css:validate

`469500b3cd5158332fb20a56b14b2c.css` is the source of truth. It was normalised
with `beautify-css.js` (4-space indentation, one declaration per line, one
selector per line, blank line between rules) because it had arrived hand-formatted
with mixed tabs, spaces and squashed one-liners. Comment bodies are left exactly
as written, so 29 tab-indented lines and 29 squashed one-liners survive inside
comments, and long unbreakable values (gradients, data-URI SVG filters) stay long.

**Formatting is invisible to the build.** What ships is minified, so the same
`assets/ingame-C9U8U3hi.css` (sha256 `ac39350c…`) is emitted before and after.

**Edit the sheet, not the chunk.** A rule in `chunks/galaxy.css` is really a slice
of the sheet. Editing a chunk directly fails `ingame:css:validate` (reassembly no
longer matches the sheet) and the next `ingame:css:group` overwrites it. Find the
rule by grepping `chunks/`, change it in the sheet, regenerate.

Chunks are packed to `--target=120000` bytes, and a boundary only ever falls where
one area run ends and the next begins, so packing never reorders anything. Ten
chunks come out: `base`, `alliance`, `lifeforms`, `galaxy`, `jquery-ui`,
`lifeforms-icons`, `lifeforms-tables`, `resources`, `research`, `marketplace`,
each roughly 40–130 KB.

## Out-game CSS

    npm run outgame:css:group
    npm run outgame:css:validate

The sheet arrived minified onto one line, so it was beautified first with the same
tool:

    npm run asset:beautify-css -- --input=resources/css/outgame/175d13751348e190f1ff2a51466c5f.css

Then split with the out-game area vocabulary and a smaller
`--target=8000`: `base`, `content`, `validation`, `fancybox`, `servers`,
`universe-filter`, 228–407 lines each. Same guarantees as in-game, and the same
byte-identical `assets/outgame-Bd4b_3cr.css`.

This sheet is far calmer than the in-game one — 298 rules, 13 repeated selectors,
17 repeated pairs, 25 `!important`, and none of its self-overrides crosses a chunk
boundary — so it is much closer to something you can edit directly.

**Vendor sheets stay untouched**: Fancybox 3 (`f18de6ef…`), the Fancybox 1 theme
(`0d1c3e71…`), the validation engine (`d22ddc4…`) and the IE-only hacks
(`8203e976…`, `9253d1db…`). Do not tidy them: reformatting or reordering a
third-party file is the same unverifiable cascade risk, in a file nobody owns.

## In-game JavaScript

    npm run ingame:group      # regenerate the split into tmp for review
    npm run ingame:validate   # reassemble + syntax + minified comparison

`e7c74974620fa35b197315ebdbb8c2.js` (1.4 MB of pre-ES6 OGame code) is split into
283 files under `resources/js/ingame/chunks/<area>/` across twelve areas — core
(67), ui (55), fleet (38), galaxy (27), messages (17), alliance (16), empire (16),
lifeforms (10), marketplace (10), resources (10), events (9), combat (8). Files are
named after the symbol they contain (`fleet/fleetdispatcher.js`,
`galaxy/showgalaxy.js`), 143 of them are under 2 KB, and the manifest records the
source order that `vite.config.js` splices in where the monolith used to sit
(after `timerhandler.js`, before `messages-pagination.js`).

The split is order-preserving by construction: `ingame:validate` reassembles the
chunks and reports `NO DIFFERENCES — reassembly is byte-perfect!` plus
`MINIFIED MATCH`. That is the proof the bundle executes the same statements in the
same sequence.

## Out-game JavaScript

The bundle is twelve files, and only five were ours. Seven are vendor libraries
and keep their shipped filenames: jQuery 3.2.1, jQuery UI 1.12.1, jQuery UI Touch
Punch, jQuery Easing 1.3 (still eval-packed), the `event.frame` plugin, Fancybox
and the validation engine.

| Chunk | Source | Responsibility |
| --- | --- | --- |
| `bootstrap.js` | `6b1759b4d8ae0aeb3b4f566299ad46.js` | declares the `ogame` namespace |
| `login.js` | `6871e1cb7f618a30edcba23801e23c.js` | login, password and registration helpers |
| `universe-filter.js` | `0136dd84cb21c44f18865ec6f6b10a.js` | universe filter and characteristics table |
| `javascript-available.js` | `60cd95d4ce5cb91a86861f433773d1.js` | legacy JavaScript availability flag |
| `interface.js` | `b55eb79922e157d28e811c7452ab10.js` | menu tabs, login toggle, language switcher, page behaviour |

`login.js` and `interface.js` shipped minified onto one line and were re-printed
first:

    npm run asset:beautify-js -- --input=<minified file>

The **originals stay in `resources/js/outgame/` exactly as they shipped** — the
same idea as the in-game monolith next to its chunks. They are the reference to
diff against and nothing loads them; only `chunks/` is in `outgameScripts`.

There is no manifest here on purpose: twelve files with a fixed load order are
clearer as the `outgameScripts` array, which is the only place that has to know it.
**That order is hand-maintained and nothing validates it** — see the gotcha below.

## What the checks actually prove

| Command | Proves |
| --- | --- |
| `npm run ingame:css:validate` / `outgame:css:validate` | chunks concatenate back to the sheet byte for byte; every chunk parses and round-trips standalone; `index.css` still matches the manifest |
| `npm run ingame:validate` | in-game JS reassembly is byte-perfect, syntax-valid, and identical after re-minifying |
| `node scripts/compare-bundles.js <before> <after>` | two legacy JS bundles parse to the same program (order included), so a reorganisation changed no behaviour |
| `npm run build` | the shipped bundles. In-game JS and both CSS bundles are byte-identical to before the work; the out-game JS bundle differs only in formatting |

## Commands

| Command | What it does |
| --- | --- |
| `npm run ingame:css:group` / `:validate` | refresh / check the in-game CSS split |
| `npm run outgame:css:group` / `:validate` | refresh / check the out-game CSS split |
| `npm run ingame:group` / `:validate` | explore / check the in-game JS split |
| `npm run asset:group` / `asset:validate` | generic JS grouper and validator (tmp output) |
| `npm run asset:group-css` / `asset:validate-css` | generic CSS grouper and validator (tmp output) |
| `npm run asset:beautify-css` | whitespace-only CSS re-indent, refuses non-equivalent output |
| `npm run asset:beautify-js` | terser re-print guarded by parse-tree equality |

Scripts live in `scripts/`: `group-css.js`, `group-javascript.js`,
`validate-chunks.js`, `beautify-css.js`, `beautify-js.js`, `compare-bundles.js`,
with the shared area vocabularies in `lib/css-areas.js` and path/CLI helpers in
`lib/asset-workflow.js`. Generated output goes to `tmp/` (git-ignored) unless the
command targets a committed directory.

## Gotchas

- **`postcss` node end offsets are exclusive.** Slice with
  `code.slice(cursor, node.source.end.offset)`; adding `+1` shifts every boundary
  one character into the next node, which still reassembles byte-for-byte but
  produces chunks that fail to parse once Vite inlines them (the build dies with
  `Unknown word /`).
- **Manifest `start`/`end` are rule offsets, not file spans.** The gaps between
  them hold comments and blank lines, and belong to the chunk that follows, so the
  written files tile the source exactly even though the offsets are not adjacent.
- **The beautifiers refuse to write when they would change code.** `beautify-css`
  compares parsed structure; `beautify-js` compares parse trees, deliberately
  tolerating an inserted ASI semicolon or a literal spelled differently at the same
  value. If either throws, the change is not formatting.
- **Reformatting JS changes shipped bytes, reformatting CSS does not.** The legacy
  JS bundles are plain concatenations (not minified), the CSS is minified by Vite.
- **Changing chunk granularity can legitimately change the minified CSS.** With a
  much finer split the minifier stopped merging some adjacent duplicate rules and
  dropped one instead (78 bytes). Byte-identity holds for the current `--target`;
  after changing it, diff the built bundle again.
- **The two script arrays are hand-maintained.** `ingameScripts` and
  `outgameScripts` in `vite.config.js` carry the vendor order and nothing checks
  it, so keep the previous bundle and run `compare-bundles.js` after touching
  them — a reorder shows up as a parse-tree difference.
- **Vendor files are never "tidied".** They are third-party, and reordering them
  is the same unverifiable cascade risk in a place nobody owns.

## Migration path (what "modern" means here)

The files are modern in shape; the code inside is not. In-game JS is pre-ES6 with
jQuery globals and no modules, in-game CSS is ID-based with deep descendant
selectors, vendor prefixes and no custom properties, and these bundles ship
unminified because they are concatenations.

Turning that into modern code is a migration, not a reformat, and it goes one area
at a time:

1. Pick an area (say fleet). Move its rules out of the chunk and into
   `resources/css/ingame/modules/fleets.css`, which is already the hand-written
   modern home for in-game CSS.
2. Delete the declarations the cascade proves are dead (see the table above).
3. Click through the affected screens — this is the step chunking could not do for
   you, because a move changes which rule wins.
4. Repeat. The sheet shrinks until it is gone.

The same idea applies to JS: extract a behaviour into a real ES module, add it to
the Vite inputs, and delete its chunk. Until then, the split is what makes the
legacy code reviewable.

## Reuse for other large assets

    npm run asset:group -- --input=resources/js/<file>.js --output=tmp/js-chunks --plan=tmp/js-plan.json
    npm run asset:validate -- --input=resources/js/<file>.js --chunks=tmp/js-chunks
    npm run asset:group-css -- --input=resources/css/<file>.css --target=120000 --areas=outgame

All paths must stay inside the project. `validate-chunks.js` infers the asset type
from the extension (`--type=css` to be explicit), and `group-css.js` takes
`--target` (bytes per chunk) and `--areas=ingame|outgame`. Use the generated plan
to pick one understandable subsystem for a small reviewed migration, and do not
change runtime Vite inputs until it has been browser-tested.
