# TinyPDF 1.1.0 verification and migration

This is the implementation and local verification record for 1.1.0, dated 2026-11-16. It is not a published cross-platform release certification. The version is 1.1.0 as requested, despite the new native deployment requirement.

## Migration

1. Install the `tinypdf-text` helper and its shared libraries using [the native build instructions](../native/README.md). Enable `proc_open()` in the PHP worker configuration.
2. Run `php bin/tinypdf-check.php /absolute/path/to/tinypdf-text` under the same PHP configuration used by the application. Configure `TextOptions::helperPath` or `TINYPDF_TEXT_HELPER`.
3. Install and register the exact fonts required by the template. `php bin/install-fonts.php notosansbengali` installs the pinned Bengali pack; it does not register fallback automatically. Use `addFont()` and `setFallbackFonts()`.
4. Regenerate representative templates and review line lengths, alignment, page counts and font styling. Generic Helvetica/Times/Courier names now use the bundled Noto Sans subset unless explicitly registered. Register serif and monospace faces when needed.
5. Deploy the PHP package, helper and font files together. Generation is offline. Earlier tags remain available for applications that cannot yet install a native helper.

Leading constructor arguments and existing public `HtmlPdf` methods remain available. New trailing arguments and `setFallbackFonts()` are documented in [README](../README.md). PDF output changes to 1.7. Missing helpers, unsupported fonts and uncovered visible characters throw exceptions; there is no legacy shaper fallback. Do not expect byte-identical PDFs, identical wrapping or identical page counts after migration.

## Local results

Environment: macOS ARM64, PHP 8.4.27, HarfBuzz 14.2.1, ICU 78.3 / Unicode 17, FriBidi 1.0.16, Cairo 1.18.4, FreeType pkg-config version 26.6.20, librsvg 2.58.5. Core engine source archives and font inputs have pinned checksums.

| Gate | Observed result |
|---|---|
| PHP integration and failures | 82 passed, 0 failed, 0 skipped |
| Direct HarfBuzz reference | 31 cases matched glyph IDs, UTF-8 clusters, both advances and both offsets exactly |
| Unicode graphemes | 766 cases passed |
| Unicode line breaks | 19,338 cases passed |
| Unicode bidi characters | 91,707 cases passed |
| Unicode bidi classes/base directions | 770,241 cases passed |
| PDF readers | Four specimen pages rendered with Poppler, MuPDF 1.26.7 and PDFium 153.0.7999.0; static subsets and CID mappings validated |
| Exact HAAHS title | Preserved in `ActualText`; exact automated extraction passed in Poppler, MuPDF and PDFium |
| Missing process execution | Diagnostic fails clearly with `proc_open()` disabled |
| Composer | `composer validate --strict` passed |

The bidi pipeline uses FriBidi with ICU's Unicode properties. Testing exposed override/isolate failures in ICU 78.3's bidi resolver; the replacement passes all pinned bidi cases without allowlisted failures. ICU still performs grapheme and line boundary analysis, including dictionary breaking.

### Language and typography matrix

| Area | Fixtures exercised |
|---|---|
| Bengali | `HAAHS প্রাক্তন শিক্ষার্থীদের পুনর্মিলনী ২০২৪`, প্র, ক্ত, reph, conjuncts, vowel marks, ZWJ/ZWNJ, compatible styled spans |
| Other Indic scripts | Devanagari/Hindi, Tamil, Telugu, Malayalam, Kannada, Gujarati, Gurmukhi/Punjabi, Sinhala, Odia |
| RTL | Arabic, Persian, Urdu/Nastaliq, Hebrew; marks, mixed Latin/numbers, brackets, isolates, overrides and styled spans |
| CJK | Simplified/traditional Chinese, Japanese, supplementary Han, Korean, Hangul/Jamo, regional font selection, punctuation and vertical Japanese |
| Latin and related | French, Vietnamese, composed/decomposed marks, ligatures, Greek and Cyrillic |
| Additional scripts | Thai, Lao, Khmer, Myanmar and Tibetan |
| Fonts | TTF, name-keyed CFF, CFF2 normalization, WOFF, WOFF2, TTC face 1, numeric variable weight/stretch and explicit axes |
| Color | CFF/CFF2/glyf COLR v1, SVG, compressed SVG, sbix, CBDT/CBLC; emoji families, flags, modifiers and variation selectors |
| Integration | Tables, headers/footers, flex, links, pagination, repeated output, adding pages after output, deterministic cluster fallback |

Coverage is specific to the pinned fonts and fixtures. Vertical text boxes, mixed upright/sideways text, column pagination, a vertical table cell and a positioned vertical box have been exercised. A full browser orthogonal-flow algorithm is not implemented. More nested vertical/horizontal combinations, additional variable axes and application templates still need broader visual fixtures before claiming comprehensive certification.

### Extraction limits observed

`ToUnicode` maps document CIDs to original source sequences; line-level `ActualText` retains logical Unicode, including ligatures and supplementary characters. The same PDF can nevertheless extract differently in readers:

- PDFium's tested `ActualText` path drops supplementary emoji characters, while MuPDF extracts the complete emoji sequence. Replacing the invisible companion outline did not resolve this reader behavior.
- MuPDF's tested extraction reorders/splits some Urdu/Nastaliq and mixed RTL spans and can duplicate a suffix in some Indic rows. PDFium recovers the Urdu fixture in logical order.
- Poppler may add bidi formatting controls and choose visual ordering for RTL output.

These observations are recorded by `tests/rendering.py`; they are not silently counted as successful exact extraction. Automated exact HAAHS extraction is a passing gate. Manual search/copy checks in Acrobat, Preview and a browser PDFium UI remain open. Do not advertise universal copy/paste or PDF/UA compliance.

## Running the checks

After building the helper and installing Composer dependencies:

```sh
cmake --build native/build --target tinypdf-test-helper --parallel
php bin/install-fonts.php --tests --unicode
python3 -m venv .venv
. .venv/bin/activate
pip install -r tests/requirements.txt
python tests/prepare_formats.py
composer test
python tests/reference.py
python tests/unicode_conformance.py
python tests/rendering.py
php tests/benchmark.php > tests/performance.json
```

Use the pinned `hb-shape` on `PATH` for reference checks. Rendering requires Poppler `pdftoppm` and `pdftotext`; `PDFTOTEXT` can override the latter path. Python 3.12 is used in CI. Fonts, licenses and Unicode data are checked against their manifests; missing fixtures fail instead of skipping tests. Unicode test data is supplied under the [Unicode data license](https://www.unicode.org/license.txt).

The reviewed PNGs in `tests/reference/` were inspected for missing glyphs, spacing, clipping, orientation and color output. Rendering checks compare all three engines and use a tighter MuPDF reference threshold. These are visual regression references, not independent linguistic certification. Direct HarfBuzz comparisons provide the independent shaping reference. Browser comparisons using identical fonts/features have not been certified.

Generated specimens and rendering results go to `output/pdf/`. Replacing a reference image requires inspecting the new PDF and reviewing why it changed; do not regenerate references merely to make a failing test pass.

## Performance baseline

One local run, using a fresh helper/document for each scenario and then calling `output()` again on that same document:

| Scenario | Initial generation | Repeat output | Peak PHP memory | Helper requests, including repeat | PDF size |
|---|---:|---:|---:|---:|---:|
| HAAHS/mixed-script card | 73.63 ms | 18.84 ms | 4 MiB | 35 | 33,967 bytes |
| Four-page mixed-script invoice | 71.35 ms | 18.94 ms | 4 MiB | 35 | 39,430 bytes |
| 100 repeated mixed-script pages | 140.99 ms | 23.19 ms | 6 MiB | 35 | 214,977 bytes |

The operating-system file cache was warm. The 100 pages reuse identical content and demonstrate paragraph caching, not 100 distinct pages. PHP peak memory excludes native helper memory. This baseline is descriptive; timings depend on the host and no arbitrary cross-platform performance threshold is enforced yet.

## Release status

The source, deployment diagnostic, checksummed font installer, native source build, relocatable packaging and CI workflows are present. Local macOS ARM64 generation and packaging have been exercised. Linux x64/ARM64, macOS Intel and Windows x64 jobs are defined but have not run in this session. Linux candidates target glibc 2.39; other environments require source builds.

Before public release, run every target job, inspect its artifacts and dependency license/source inventory, and complete manual viewer checks. The build pins HarfBuzz/ICU/FriBidi; host graphics/transitive libraries are recorded in package manifests but are not bit-reproducibly pinned. No public binaries, Git tag or Composer release were published by this implementation. The alumni application's repository/vendor files were not changed, per the request to focus on TinyPDF.
