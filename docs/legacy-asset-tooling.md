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

If you are working in the asset tree rather than reading this end to end,
`resources/css/README.md` and `resources/js/README.md` are the short versions, and
`npm run assets:check` tells you whether the splits are still intact.

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

## How the in-game bundle is assembled

`resources/css/ingame.css` is the Vite input, and the chunked sheet is only one
slot in an ordered list of ten imports. Vite inlines all of them into a single
`assets/ingame-*.css`, in this order:

| # | File | What it is |
| --- | --- | --- |
| 1 | `ingame/jquery.ui.css` | vendor |
| 2 | `ingame/select2.css` | vendor |
| 3 | `ingame/modules/fleets.css` | ours, hand-written |
| 4 | `ingame/modules/objects.css` | ours, hand-written |
| 5 | `ingame/22b955f43c237ad23d644e8e52272a.css` | legacy, small |
| 6 | `ingame/chunks/index.css` | **the split legacy sheet (10 chunks)** |
| 7 | `ingame/02base.css` | legacy, small |
| 8 | `ingame/modules/sprites.css` | ours, hand-written |
| 9 | `ingame/modules/highscore.css` | ours, hand-written |
| 10 | `ingame/modules/messages.css` | ours, hand-written |

Position decides who wins when two files touch the same selector. Measured against
the legacy sheet:

| File | Position | Selectors also in the sheet | Shared (selector, property) pairs | …where the value differs |
| --- | --- | --- | --- | --- |
| `jquery.ui.css` | before | 330 | 584 | 36 — the sheet wins |
| `modules/fleets.css` | before | 515 | 1151 | 5 — the sheet wins |
| `22b955f43…css` | before | 3 | 7 | 0 |
| `select2.css` | before | 0 | 0 | 0 |
| `modules/objects.css` | before | 0 | 0 | 0 |
| `02base.css` | after | 360 | 691 | 31 — `02base` wins |
| `modules/sprites.css` | after | 182 | 235 | 35 — `sprites` wins |
| `modules/highscore.css` | after | 1 | 0 | 0 |
| `modules/messages.css` | after | 2 | 2 | 0 |

So the hand-written `modules/*.css` files are already replacing the legacy sheet in
about 66 places, and that works precisely because `sprites`, `highscore` and
`messages` are imported **after** it. `fleets.css` and `objects.css` come **before**
it, which has a consequence for the migration: a rule copied into them still loses
to the sheet's copy, so they only take effect once the sheet's version is deleted in
the same change. Copy into a file that loads after the sheet if you want the two to
coexist for a while.

`select2.css` and `modules/objects.css` share no selectors with the sheet — they are
new UI, not replacements.

Modules are a separate story: `Modules/*` are packages with their own front-end
builds landing in `public/modules/<module>/build/` (git-ignored), and they add
nothing to these two entries, so chunking never interacts with them.

The split does duplicate the sheet's bytes on disk — the in-game sheet plus its
chunks is about 2.4 MB in the repository. Only the chunks are ever served; the
sheet exists so there is one ordered source to edit, diff and regenerate from.

## Unused CSS in `resources/css`

Nothing below is referenced by either entry or by any view. It was moved into
`deprecated/` folders so the live tree says what it means — the files themselves are
kept for reference:

| File | Size | Why it is unused |
| --- | --- | --- |
| `ingame/deprecated/990d5d349ed6e981658ff4e2e3444c.css` | 1.1 MB | never imported — an older export of the legacy sheet |
| `ingame/deprecated/base1.css` | 281 KB | rejected style rewrite; import was commented out |
| `ingame/deprecated/base2.css` | 265 KB | same |
| `ingame/deprecated/app.css` | 120 KB | unused experiment that used to sit in `ingame/modules/` |
| `ingame/deprecated/{ltie10,ie8}/…` | 2.6 KB | IE-only sheets kept for reference |
| `outgame/deprecated/8203e976…`, `outgame/deprecated/9253d1db…` | 4.8 KB | IE-only sheets |

That is roughly 1.8 MB of the 3.2 MB under `resources/css`. Each `deprecated/`
folder has a README recording the comments that used to sit in the entries, so
nothing was lost when the entries were reduced to their import lists.

One broken reference predates all of this:
`resources/views/ingame/alliance/info.blade.php` lines 9 and 180 link
`asset('css/ingame.css')` and `asset('js/ingame.js')`, but `public/` has no `css/`
or `js/` directory and nginx defines no alias for them (the built assets live in
`public/build/`). That page renders from its inline `<style>` and never receives the
bundle.

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

```bash
npm run assets:check     # every split at once: JS, in-game CSS, out-game CSS
```

| Command | What it does |
| --- | --- |
| `npm run assets:check` | runs the three validators below; the one command to run before committing asset work |
| `npm run ingame:validate` | check the in-game JS split |
| `npm run ingame:css:validate` / `ingame:css:group` | check / refresh the in-game CSS split |
| `npm run outgame:css:validate` / `outgame:css:group` | check / refresh the out-game CSS split |
| `npm run ingame:group` | refresh the in-game JS split |
| `npm run asset:group` / `asset:validate` | generic JS grouper and validator (tmp output, for a new file) |
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
