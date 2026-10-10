# Deprecated out-game stylesheets

Nothing in this folder is loaded by the build. Both files are Internet Explorer
fixups for a browser nobody runs any more, moved out of `resources/css/outgame/`
so the live tree says what it means.

| File | What it was for |
| --- | --- |
| `8203e97695e64d89c5ed0efca2dd55.css` | IE 8 and below |
| `9253d1dbc4e5de58100a2db4b3b5b1.css` | IE 7 and below |

The comment that used to sit in `../outgame.css`, kept verbatim:

```html
/*
Old IE stylesheets, probably not needed anymore. Keeping them here for reference.
TODO: Remove these at a later time if they are not needed anymore.

<!--[if lt IE 8]>
<link rel="stylesheet" type="text/css" href="css/outgame/8203e97695e64d89c5ed0efca2dd55.css" />
<![endif]-->
<!--[if lt IE 7]>
<link rel="stylesheet" type="text/css" href="css/outgame/9253d1dbc4e5de58100a2db4b3b5b1.css" />
<![endif]-->
 */
```

Background and the rules for the live tree: [docs/legacy-asset-tooling.md](../../../docs/legacy-asset-tooling.md).
