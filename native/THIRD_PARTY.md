# Native dependencies

TinyPDF 1.1.0 uses dynamically linked libraries. The text engines are pinned by `sources.lock.json`; Cairo, FreeType, librsvg and transitive image/platform libraries are supplied by the build host. Every packaged library's checksum and the detected direct dependency versions are recorded in `manifest.json`. Rebuild against the same dependency packages to reproduce that environment.

| Component | License | Upstream source |
|---|---|---|
| HarfBuzz | MIT-style | https://github.com/harfbuzz/harfbuzz |
| ICU and Unicode data | Unicode-3.0 | https://github.com/unicode-org/icu |
| FriBidi | LGPL-2.1-or-later | https://github.com/fribidi/fribidi |
| FreeType | FreeType License (or GPL-2.0) | https://gitlab.freedesktop.org/freetype/freetype |
| Cairo | LGPL-2.1 or MPL-1.1 | https://gitlab.freedesktop.org/cairo/cairo |
| librsvg | LGPL-2.1-or-later | https://gitlab.gnome.org/GNOME/librsvg |
| libpng | libpng-2.0 | https://github.com/pnggroup/libpng |
| nlohmann/json 3.12.0 | MIT | https://github.com/nlohmann/json/tree/v3.12.0 |
| Noto fonts | SIL Open Font License 1.1 | See each pinned font pack's OFL/LICENSE file |

The JSON header's full license is in `vendor/json.LICENSE`. Font redistribution must retain its accompanying license. Font source URLs and SHA-256 hashes are in `tests/fonts/manifest.json`.

`package.py` copies dependency license files from native/prefix and supported package-manager locations. Before distributing an archive, audit its `licenses/` against **every** library in `manifest.json`, retain the corresponding source URLs/versions and satisfy each library's source/relinking requirements. The source-built engines remain replaceable shared libraries. ICU's data library is included by dependency traversal.

The source recipes and CI definition are not evidence that all target archives have been built, licensed, or tested. Only distribute artifacts after the target's verification job and license audit pass.
