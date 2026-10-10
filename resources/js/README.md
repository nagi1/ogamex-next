# In-game and out-game scripts

Both bundles are plain concatenations of classic scripts (no ES modules), assembled
by `vite.config.js` from the `ingameScripts` and `outgameScripts` arrays and served
at `resources/js/ingame.js` and `resources/js/outgame.js`. Load order is
significant — later files may use globals earlier ones define.

| Folder | What is there |
| --- | --- |
| `ingame/chunks/<area>/` | 283 files — the legacy 1.4 MB `e7c74974620fa35b197315ebdbb8c2.js` split by feature, spliced into `ingameScripts` from `chunks/manifest.json` |
| `ingame/*.js`, `ingame/vendor/` | vendor and hand-written scripts listed explicitly in `ingameScripts` |
| `outgame/chunks/` | our five out-game scripts under readable names |
| `outgame/*.js` | seven vendor libraries, plus the original hash-named copies of those five scripts, kept as reference |

## Reading order

- The **manifest** `ingame/chunks/manifest.json` is the source of order for the
  in-game split; `vite.config.js` reads it.
- `outgameScripts` in `vite.config.js` is the only place that knows the out-game
  load order. Nothing validates it, so after changing it keep a copy of the built
  bundle and run `node scripts/compare-bundles.js <before> <after>`.

## Working here

```bash
npm run assets:check        # verify the in-game split reassembles byte for byte
npm run ingame:group        # re-derive the split into tmp for review
npm run build               # the shipped assets are committed, so rebuild after edits
```

Chunking gives readable files, not modern code: the in-game chunks are still
pre-ES6 with jQuery globals. Extracting a behaviour into a real ES module is the
migration, one area at a time. Rules, measurements and traps are in
[docs/legacy-asset-tooling.md](../../docs/legacy-asset-tooling.md).
