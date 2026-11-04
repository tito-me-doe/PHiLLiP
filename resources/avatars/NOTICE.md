# Avatar assets

- `bodies/noto-*.svg` are derived from [Noto Emoji](https://github.com/googlefonts/noto-emoji) (`2D/svg`), © Google, licensed under the
  Apache License 2.0 (`LICENSE-noto-emoji`). They were stripped to plain shapes, had their ids namespaced and were fitted into the avatar
  frame by `tools/avatars/import-noto.py`; the shapes themselves are unchanged.
- The shipped characters' bodies and faces (`bodies/<name>.svg`, `faces/*.svg`) are original to PHiLLiP.

`avatars.json` records each body's source and license; `Agent\Avatar::credits()` lists them.
