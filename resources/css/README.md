# In-game and out-game stylesheets

Two Vite inputs, one per audience:

| Entry | Loaded by | Output |
| --- | --- | --- |
| `ingame.css` | `@vite(['resources/css/ingame.css', …])` in `resources/views/ingame/layouts/main.blade.php` | `public/build/assets/ingame-*.css` |
| `outgame.css` | `@vite(['resources/css/outgame.css', …])` in `resources/views/outgame/layouts/` | `public/build/assets/outgame-*.css` |

Both entries are ordered `@import` lists and Vite inlines them into a single file,
so **the order is the cascade**: a sheet imported later wins an equal-specificity
conflict against one imported earlier.

## What is what

- `ingame/modules/*.css` — hand-written, modern, ours. This is where in-game CSS
  should live; `sprites.css`, `highscore.css` and `messages.css` already override
  the legacy sheet.
- `ingame/469500b3cd5158332fb20a56b14b2c.css`, `outgame/175d13751348e190f1ff2a51466c5f.css`
  — the legacy OGame sheets. They are the **source of truth** for the `chunks/`
  folders next to them.
- `ingame/chunks/`, `outgame/chunks/` — generated, source-ordered splits of those
  two sheets. They are what the entries import, but **they are not where you
  edit**: a chunk is a slice of its sheet, so change the sheet and run the group
  script.
- `ingame/jquery.ui.css`, `ingame/select2.css`, the out-game Fancybox and
  validation-engine sheets — vendor. Leave them as they shipped.
- `*/deprecated/` — unused sheets kept for reference (IE fixups, an older export of
  the legacy sheet, a rejected rewrite). Nothing loads them.
- `ingame/02base.css`, `ingame/22b955f43c237ad23d644e8e52272a.css` — small legacy
  sheets that are still live.

## Working here

```bash
npm run assets:check        # verify every split is intact
npm run ingame:css:group    # after editing the in-game sheet
npm run outgame:css:group   # after editing the out-game sheet
npm run build               # the shipped assets are committed, so rebuild after edits
```

Full rules, measurements and traps — including why these sheets cannot be grouped
by area — are in [docs/legacy-asset-tooling.md](../../docs/legacy-asset-tooling.md).
