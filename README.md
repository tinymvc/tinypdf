# TinyPDF

A compact PHP HTML-to-PDF library for invoices, ID cards, reports and other controlled document templates.

PHP handles layout and PDF assembly. A single persistent native helper handles multilingual text shaping. Output is PDF 1.7 with embedded static font subsets and logical Unicode text recovery.

**Version 1.1.0** · PHP 8.2+ · MIT License

## Highlights

- **Document-oriented HTML/CSS** — typography, tables, flex rows/columns, badges, borders, images, inline SVG and print CSS.
- **Real multilingual text** — HarfBuzz shaping, ICU line breaking, FriBidi bidi, plus vertical writing modes.
- **Searchable output** — logical-order text is preserved via `ToUnicode` and `ActualText`.
- **Color glyph support** — COLR/CPAL, CBDT/CBLC, sbix and OpenType-SVG render as transparent 600-DPI images with invisible selectable text.
- **Deterministic fonts** — no system font lookup, no downloads during generation.

TinyPDF implements a document-oriented subset of HTML/CSS. Browser-level layout, mathematical typesetting and PDF/UA certification are out of scope. Correct script rendering requires suitable registered fonts.

## Requirements

- PHP 8.2+ with `dom`, `mbstring`, `json`, `zlib`, `ctype`, and `proc_open()` enabled
- `gd` for PNG, GIF and WebP images (not needed for color glyphs)
- The `tinypdf-text` native helper and its libraries

## Installation

```bash
composer require tinymvc/tinypdf
php vendor/bin/tinypdf-install.php
```

Run both commands from your application's project root. The installer builds `tinypdf-text` inside the package and verifies it with a generated PDF. No directory changes or helper-path configuration are needed. Rerun it after updating TinyPDF or installing the project on another machine.

Install CMake, a C++ compiler, pkg-config and the native development libraries first. The installer uses those dependencies; it does not download them or modify system packages. On Windows, run it in an MSYS2 UCRT64 shell with Ninja installed.

To check an existing installation, run `php vendor/bin/tinypdf-check.php` from the project root.

See [native build and installation](native/README.md) for dependency versions, platform recipes and binary packaging.

Point TinyPDF at an installed helper with `TextOptions::helperPath` or the `TINYPDF_TEXT_HELPER` environment variable. A source checkout automatically finds `native/build/tinypdf-text`.

## Quick start

```php
require 'vendor/autoload.php';

use Spark\Pdf\HtmlPdf;

$pdf = new HtmlPdf('A4', margin: 15, footer: 'Page {page} of {pages}');
$pdf->setTitle('Invoice #42')->addHtml('
    <h1 style="color:#0f766e">Invoice #42</h1>
    <p>Thank you for your order.</p>
');

$pdf->save('invoice.pdf');
// $bytes = $pdf->output();
// $pdf->download('invoice.pdf');
```

- Each `addHtml()` call starts a new page.
- Repeated `output()` calls rebuild page decorations without duplicating them, and pages may be added afterwards.
- Default Latin text uses a bundled OFL Noto Sans subset. Fonts are subsetted again to the glyphs actually used.

The [invoice example](examples/invoice.php) demonstrates typography, CSS custom properties, padded cards, flex layout, pill badges, tables and page numbering:

```bash
composer install
php examples/invoice.php invoice.pdf
```

## Fonts and languages

Register the exact fonts your deployment will use. Glyph coverage and shaping support are separate requirements: a font may contain a character without supporting its correct contextual form in this renderer.

### Registering fonts

```php
$pdf = new HtmlPdf(fontFamily: 'Bangla, Helvetica', margin: 15);
$pdf->addFont('Bangla', '/path/to/Bengali-Regular.ttf');
$pdf->addFont('Bangla', '/path/to/Bengali-Bold.ttf', bold: true);
$pdf->addHtml('<p style="font-size:16pt;line-height:normal">আমি বাংলা ভাষায় কথা বলি।</p>');
$pdf->save('bangla.pdf');
```

Local fonts can also be registered from CSS:

```html
<style>
  @font-face {
    font-family: 'Bangla';
    src: url('fonts/Bengali-Regular.ttf');
    font-weight: 400;
  }
  body { font-family: 'Bangla', Helvetica, sans-serif; }
</style>
```

Notes:

- Set `basePath` to resolve relative image and font paths.
- Register separate italic faces where needed.
- Generic and core family names resolve to the bundled Noto Sans. Register explicit serif or monospace families if you need their metrics.
- Variable fonts derive axes from CSS weight, stretch, style and optical sizing. Explicit `font-variation-settings` takes precedence.

### Fallback fonts

Fallback is deterministic, in this order:

1. The declared CSS font stack
2. Families from `setFallbackFonts()`, in order
3. The bundled Latin font

TinyPDF never searches host system-font directories. A grapheme, conjunct or emoji sequence stays together, and any uncovered visible character raises an exception listing the Unicode values and the families attempted.

```php
use Spark\Pdf\TextOptions;

$pdf = new HtmlPdf(textOptions: new TextOptions(
    helperPath: '/opt/tinypdf/bin/tinypdf-text',
    defaultLanguage: 'und',
    helperTimeout: 30.0,
    colorGlyphDpi: 600,
));

$pdf->addFont('Bangla', '/opt/fonts/NotoSansBengali.ttf');
$pdf->addFont('Arabic', '/opt/fonts/NotoNaskhArabic.ttf');
$pdf->addFont('Japanese', '/opt/fonts/NotoSansJP.ttf');
$pdf->setFallbackFonts(['Bangla', 'Arabic', 'Japanese']);

$pdf->addHtml('<h1 lang="bn">বাংলা ভাষায় নির্ভুল নথি তৈরি</h1>');
```

### Optional font packs

Checksummed OFL Noto font packs can be installed during deployment:

```bash
php bin/install-fonts.php notosansbengali notosansdevanagari notonaskharabic notosansjp
```

Packs are written to `fonts/<pack>/` inside the package. Register the installed paths explicitly; installing a pack does not change fallback selection. The manifest (version 1.1.0) lists source URLs, SHA-256 checksums and license files. If Composer deployments replace the package directory, copy the packs to an application-owned location.

### Supported font formats

TTF, OpenType CFF/CFF2, TTC face selection, WOFF/WOFF2 and variable instances. TrueType subsets use explicit CID-to-GID maps, and cubic outlines are normalized to static CID-keyed CFF. Fonts with embedding or subsetting restrictions fail explicitly.

### Text engine

- **Shaping:** HarfBuzz (OpenType)
- **Unicode:** ICU (Unicode 17 properties, graphemes, locale-sensitive line breaks)
- **Bidi:** FriBidi (paragraph and line resolution)

Source text stays in logical order; only positioned glyphs are reordered for painting.

Supported typography includes inherited `lang`, `dir="ltr|rtl|auto"`, `bdi`, `bdo`, `unicode-bidi`, OpenType features, numeric weights, variation settings, Unicode wrapping, safe cluster spacing, `writing-mode: horizontal-tb | vertical-rl | vertical-lr`, and `text-orientation: mixed | upright | sideways`. Give a vertical text box a height to define its inline extent.

PDF `ToUnicode` and `ActualText` preserve logical source sequences. Regression tests verify extraction and search in MuPDF, PDFium, Poppler and Apple PDFKit.

### Color glyphs

COLR/CPAL, CBDT/CBLC, sbix and OpenType-SVG glyphs are rendered as transparent 600-DPI images by default, with invisible selectable companion text. Ordinary text stays vector. Rasterizing a bitmap font at 600 DPI does not add detail beyond its original resolution.

## HTML and CSS compatibility

| Area | Implemented | Limits |
|---|---|---|
| HTML | Headings, paragraphs, sections, lists (Roman/alphabetic, reversed, start/value, inside/outside), inline text, links, tables, images, inline SVG | No JavaScript, canvas or interactive forms |
| Cascade | Embedded, inline and linked CSS; recursive `@import`; class/id/tag/attribute selectors; descendant/child/sibling combinators; structural child/type selectors, `:is()`, `:where()`, `:not()`, `:lang()`; `!important` | No pseudo-elements, `:has()` or CSS nesting; remote stylesheets require `allowRemote` |
| Print CSS | `@page` size and margins; plain `@media print`, `all` and `only print`; `<style media="print">` | No conditional media features, named pages, margin boxes or `@supports` |
| Theme values | Inherited custom properties, `var()` with fallbacks; numeric `calc()`, `min()`, `max()`, `clamp()` lengths | No registered custom properties or full CSS typed arithmetic |
| Typography | Font family, size, weight and style; common `font` shorthand; alignment; underline and strike; transforms; spacing; `line-height` | Requires suitable registered fonts |
| Wrapping | Unicode and dictionary line breaks; `pre`, `pre-wrap`, `pre-line`, `nowrap`; `word-break`; `overflow-wrap`; locale-specific `line-break` | Unbreakable words may overflow; no automatic hyphenation |
| Boxes | Margins, padding, min/max dimensions, definite-parent percentage heights, aspect ratios, border-box; solid/dashed/dotted/double and shaded borders; uniform radius; visibility, overflow hidden/clip, display contents | No floats, grid, shadows, gradients, scrolling overflow or arbitrary transforms; shaded border corners are square |
| Flex | Rows/columns and reverse directions, wrapping, grow/shrink/basis, order, alignment, justification, row/column gaps; content remeasured at allocated widths | No full browser min-content/freezing algorithm or baseline alignment; flex groups cannot span pages, so keep them shorter than a page |
| Inline boxes | Padded badges, inline-blocks and inline-flex with backgrounds, borders and baseline alignment | Decorated spans are indivisible and do not fragment across lines |
| Tables | Auto/fixed layout, column/group spans, `colspan`/`rowspan`, top/bottom captions, ordered sections, repeating `thead`, unequal row heights | Rows and connected rowspan groups must fit on a page; no splitting of oversized rows, no repeating `tfoot`, incomplete collapsed-border conflict resolution |
| Pagination | Automatic text and table breaks, `break-before/after`, basic `break-inside: avoid`, headers and footers | No widows or orphans; reserve enough margin for headers and footers |
| Positioning | Basic absolute positioning; block relative offsets and containing blocks | Inline relative offsets are unsupported; fixed elements do not repeat per page (use the header/footer APIs) |
| Color | Hex, common named colors, RGB/HSL, background and text alpha, basic element opacity | No compositing groups or blend modes; opacity on nested groups is approximate |
| Images | JPEG, PNG alpha, GIF, WebP, data URIs, SVG images, borders/padding/backgrounds, min/max dimensions, aspect ratios, `object-fit`/`object-position`, missing-image alt text, rounded clipping | No animation, `srcset`/`picture` selection or automatic downsampling |
| Backgrounds | Images, shorthand position/size, `cover`/`contain`, repeat, edge offsets, rounded clipping, page watermarks | Single image layer; no gradients or multiple backgrounds |
| SVG | Lines, polygons, rectangles, circles, ellipses, cubic/quadratic/arc paths, basic transforms, `viewBox` | No text, gradients, filters, masks, `use`/`defs`, full styling or complete `preserveAspectRatio` |
| PDF features | External links, internal block anchors, flat heading bookmarks, metadata, compressed streams | No PDF/A, PDF/UA, tagging, signatures, encryption or fillable forms |

**Units.** Lengths accept `px`, `pt`, `mm`, `cm`, `in`, `pc`, `q`, `em`, `rem`, `vw`, `vh`, `vmin`, `vmax` and percentages where the property supports them. One CSS pixel equals 0.75 PDF points. Numeric constructor margins and custom page sizes are in **millimetres**.

Unknown CSS declarations are generally ignored.

## API

### Constructor

```php
new HtmlPdf(
    string|array $format = 'A4',
    string $orientation = 'P',
    float|array $margin = 10,
    float $fontSize = 10,
    string $fontFamily = 'helvetica',
    ?string $basePath = null,
    bool $allowRemote = false,
    ?string $footer = null,
    ?TextOptions $textOptions = null,
);
```

- **Formats:** `A3`, `A4`, `A5`, `A6`, `Letter`, `Legal`, `Card`, a custom `[width, height]` in mm, or strings such as `80x120` and `4in 6in`.
- **Margins:** one number, `[vertical, horizontal]`, or `[top, right, bottom, left]`.
- **Orientation:** `P` (portrait) or `L` (landscape).

### Methods

| Method | Purpose |
|---|---|
| `addHtml($html, $format = null, $orientation = null)` | Render a document or fragment on a new page |
| `addFont($family, $path, $bold = false, $italic = false, $faceIndex = 0, $variations = [])` | Register a local font face |
| `setFallbackFonts($families)` | Set the ordered fallback families (must be registered) |
| `setTitle($title)`, `setAuthor($author)`, `setSubject($subject)` | Set Unicode PDF metadata |
| `setBasePath($path)` | Resolve relative resource paths |
| `setFooter($text)` | Add centered text with `{page}` and `{pages}` |
| `setHeaderHtml($html, $excludeFirstPage = false)` | Add HTML in the reserved top margin |
| `setFooterHtml($html, $excludeFirstPage = false)` | Add HTML in the reserved bottom margin; first-page exclusion is independent of the header |
| `setBackgroundImage($source, $opacity = 1.0)` | Add a full-page image to pages rendered afterwards |
| `addBookmark($title, $level = 1, $page = null, $y = null)` | Add a flat outline entry. `page` is zero-based, `y` is in PDF points from the bottom, and `level` does not yet affect nesting |
| `output(): string` | Return the PDF bytes |
| `save($path): void` | Write the PDF to a file |
| `download($filename = 'document.pdf', $inline = false): void` | Send PDF headers and bytes; call before any other HTTP output |

Configuration methods and `addHtml()` return the same instance, so calls can be chained. Header and footer HTML uses its own embedded styles. Avoid links and bookmarks there, since only drawing operations are retained.

## Security and deployment

TinyPDF is built for **trusted templates and trusted font files**. It is not a sandbox for arbitrary uploaded HTML.

- **Local paths:** image and CSS font paths can read any file PHP can access. `basePath` resolves paths but does not restrict them to a directory.
- **Untrusted text:** escape it with `htmlspecialchars()` before inserting it into templates.
- **Remote images:** disabled by default. `allowRemote: true` permits HTTP(S) requests and redirects, including to the server's internal network, with no SSRF filtering. Keep it off for user-controlled resources, or fetch and validate them separately.
- **Stream wrappers:** other PHP stream-wrapper image URLs are rejected. CSS font sources are local files only.
- **Resource limits:** bound document size, image dimensions, execution time and memory in your application.
- **Missing images:** unreadable or missing images are omitted. PNG, GIF and WebP require GD.

For predictable output, use explicit font files, test representative documents under your production PHP configuration, and verify the rendered PDF rather than relying on successful generation alone.

## Validation

With the helper built and Poppler on `PATH`:

```bash
composer install
composer validate --strict
python -m pip install -r tests/requirements.txt
php bin/install-fonts.php --tests --unicode
python tests/prepare_formats.py
composer test
python tests/reference.py
python tests/unicode_conformance.py
python tests/rendering.py
```

All language fixtures are pinned and required; tests never silently skip missing fonts. CI covers PHP 8.2–8.5 on Linux x64/ARM64, macOS Intel/Apple Silicon and Windows x64.

The tests compare shaping with direct HarfBuzz output and exercise Unicode conformance and PDF rendering. They do not establish universal browser fidelity or certify every font and reader combination, so render your own multilingual and multipage templates in the readers your users rely on.

## License

[MIT](LICENSE), copyright Shahin Moyshan.
