# TinyPDF 1.1.0

A small PHP HTML-to-PDF library for invoices, cards, reports and controlled document templates. PHP handles layout and PDF assembly; one persistent native helper handles multilingual text. Output is PDF 1.7 with embedded static font subsets and logical Unicode text recovery.

TinyPDF implements a document-oriented subset of HTML/CSS. Browser-level layout, mathematical typesetting and PDF/UA certification remain outside its scope. Correct script rendering requires suitable registered fonts.

## Install

```bash
composer require tinymvc/tinypdf:^1.1
```

PHP 8.2+ requires `dom`, `mbstring`, `json`, `zlib`, `ctype`, and enabled `proc_open()`. Install `gd` for ordinary PNG/GIF/WebP images; color glyphs do not need GD.

Install `tinypdf-text` and its native libraries before generating documents.

Source build, with the native dependencies installed:

```bash
cmake -S native -B native/build -DCMAKE_BUILD_TYPE=Release
cmake --build native/build --parallel
php bin/tinypdf-check.php
```

See [native build and installation](native/README.md) for dependency versions, platform recipes and binary packaging. Set `TextOptions::helperPath` or `TINYPDF_TEXT_HELPER` to an installed helper. A source checkout also finds `native/build/tinypdf-text` automatically. Generation performs no downloads.

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

Each `addHtml()` starts a new page. Repeated calls to `output()` rebuild page decorations without duplicating them. Pages may be added after output. Default Latin text uses the bundled OFL Noto Sans subset. Fonts are further subsetted to the used glyphs when output is assembled.

The [invoice example](examples/invoice.php) demonstrates typography, CSS custom properties, padded cards, flex layout, pill badges, tables, and page numbering:

```bash
composer install
php examples/invoice.php invoice.pdf
```

## Fonts and languages

Register the exact fonts your deployment will use. Font coverage and shaping are separate requirements: a font can contain a character without supporting its correct contextual appearance in this renderer.

```php
$pdf = new HtmlPdf(fontFamily: 'Bangla, Helvetica', margin: 15);
$pdf->addFont('Bangla', '/path/to/Bengali-Regular.ttf');
$pdf->addFont('Bangla', '/path/to/Bengali-Bold.ttf', bold: true);
$pdf->addHtml('<p style="font-size:16pt;line-height:normal">আমি বাংলা ভাষায় কথা বলি।</p>');
$pdf->save('bangla.pdf');
```

Local CSS font registration is also supported:

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

Set `basePath` to resolve relative image and font paths. Register exact deployment fonts, including separate italic faces where needed. Generic/core family names resolve to the bundled Noto Sans; register explicit serif/monospace families if their metrics are required. Variable faces derive axes from CSS weight/stretch/style/optical sizing; explicit `font-variation-settings` takes precedence.

Fallback is deterministic: the declared CSS font stack, followed by `setFallbackFonts()` in order, then the bundled Latin font. TinyPDF never searches host system-font directories. A grapheme/conjunct/emoji sequence remains together. Uncovered visible characters cause an exception listing Unicode values and attempted families.

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

Optional, checksummed OFL Noto font packs can be installed during deployment:

```bash
php bin/install-fonts.php notosansbengali notosansdevanagari notonaskharabic notosansjp
```

This writes `fonts/<pack>/` inside the package. Register the installed paths explicitly; downloading a pack does not change fallback selection. The pack manifest is versioned as 1.1.0 and includes source URLs, SHA-256 checksums and license files. Copy installed packs to an application-owned location when Composer deployments replace the package directory.

TTF, OpenType CFF/CFF2, TTC face selection, WOFF/WOFF2 and variable instances are supported by the native font backend. TrueType subsets use explicit CID-to-GID maps. Cubic outlines are normalized to static CID-keyed CFF. Fonts with embedding/subsetting restrictions fail explicitly.

HarfBuzz performs OpenType shaping; ICU supplies Unicode 17 properties, graphemes and locale-sensitive line breaks; FriBidi resolves paragraph and line bidi. Original text stays in logical order; only positioned glyphs are reordered for painting.

Supported typography includes inherited `lang`, `dir="ltr|rtl|auto"`, `bdi`, `bdo`, `unicode-bidi`, OpenType features, numeric weights, variation settings, Unicode wrapping, safe cluster spacing, `writing-mode:horizontal-tb|vertical-rl|vertical-lr`, and `text-orientation:mixed|upright|sideways`. Supply a height for a vertical text box to define its inline extent. PDF `ToUnicode` and `ActualText` preserve logical source sequences; regression tests verify extraction in MuPDF, PDFium and Poppler.

COLR/CPAL, CBDT/CBLC, sbix and OpenType-SVG glyphs use transparent 600-DPI images by default, with invisible selectable companion text. Ordinary text stays vector. Rasterizing a bitmap font at 600 DPI does not invent detail beyond its original bitmap resolution.

## HTML and CSS compatibility

| Area | Implemented behavior | Limits |
|---|---|---|
| HTML | Headings, paragraphs, sections, lists, inline text, links, tables, images, inline SVG | No JavaScript, canvas, interactive forms, or external stylesheets |
| Cascade | Embedded and inline CSS, class/id/tag selectors, Unicode class names, descendant/child selectors, `:root`, `:first-child`, `:last-child`, `:nth-child`, `!important` | No attribute/sibling selectors, pseudo-elements, selector functions, or CSS nesting |
| Print CSS | `@page` size and margins; plain `@media print`, `all`, and `only print`; `<style media="print">` | No conditional media features, named pages, margin boxes, or `@supports` |
| Theme values | Inherited custom properties, `var()` and fallback values | No registered custom properties or `calc()` |
| Typography | Font family/size/weight/style, common `font` shorthand, alignment, underline/strike, transforms, spacing, `line-height` | Native shaping, cluster fallback and variable axes; suitable registered fonts are required |
| Wrapping | Unicode and dictionary line breaks, `pre`, `pre-wrap`, `pre-line`, `nowrap`, `word-break`, `overflow-wrap`, locale-specific `line-break` | Normal unbreakable words may overflow; automatic hyphenation is unsupported |
| Boxes | Margins, padding, width/height, min/max width, min-height, border-box, solid/dashed/dotted borders, a uniform border radius | No floats, grid, shadows, gradients, clipping/scrolling overflow, or arbitrary transforms; max-height applies to images |
| Flex | Single row/column, grow ratios, shrinking, alignment, justification, and gap; wrapping content measured at allocated widths | No flex-wrap, full flex-basis/min-content algorithm, or fragmentation of a flex item across pages; keep flex groups shorter than a page |
| Inline boxes | Padded badges and inline-blocks with backgrounds, borders, and baseline alignment | Decorated spans are treated as indivisible boxes; decorations do not fragment across lines |
| Tables | Auto/explicit column widths, colspan/rowspan, ordered sections, repeating thead, unequal row heights | Rows and connected rowspan groups should fit on a page; no splitting oversized rows, repeating tfoot, or complete collapsed-border conflict resolution |
| Pagination | Automatic text/table page breaks, `break-before/after`, basic `break-inside:avoid`, headers and footers | No widows/orphans; reserve sufficient margins for headers/footers |
| Positioning | Basic absolute positioning; relative elements establish containing blocks | Relative offsets are not applied. Fixed elements do not repeat on every page; use header/footer APIs instead |
| Color | Hex, common named colors, RGB/HSL, background/text alpha, basic element opacity | No full CSS compositing groups or blend modes; opacity on nested groups is approximate |
| Images | JPEG, PNG alpha, GIF, WebP, data URIs, SVG images, image max-width/max-height, rounded image clipping | No animation, object-fit, or automatic image downsampling |
| Backgrounds | Images, cover/contain, repeat, position, rounded clipping, page watermarks | Single image layer; no gradients or multiple backgrounds |
| SVG | Lines, polygons, rectangles, circles/ellipses, cubic/quadratic/arc paths, basic transforms and viewBox | No SVG text, gradients, filters, masks, use/defs, full styling, or complete preserveAspectRatio modes |
| PDF features | External links, internal block anchors, flat heading bookmarks, metadata, compressed streams | No PDF/A, PDF/UA, tagged accessibility, signatures, encryption, or fillable forms |

Lengths accept `px`, `pt`, `mm`, `cm`, `in`, `em`, `rem`, and percentages where the layout property supports them. One CSS pixel is 0.75 PDF points. Numeric constructor margins and custom page sizes use **millimetres**. Unknown CSS declarations are generally ignored.

## API

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

Formats: `A3`, `A4`, `A5`, `A6`, `Letter`, `Legal`, `Card`, or custom `[width, height]` in mm. Strings such as `80x120` or `4in 6in` are accepted. Margins take one number, `[vertical, horizontal]`, or `[top, right, bottom, left]`. Orientation is `P` or `L`.

| Method | Purpose |
|---|---|
| `addHtml($html, $format = null, $orientation = null)` | Render a document or fragment on a new page |
| `addFont($family, $path, $bold = false, $italic = false, $faceIndex = 0, $variations = [])` | Register a local font face |
| `setFallbackFonts($families)` | Set the ordered registered fallback families |
| `setTitle($title)`, `setAuthor($author)`, `setSubject($subject)` | Set Unicode PDF metadata |
| `setBasePath($path)` | Resolve relative resource paths |
| `setFooter($text)` | Add centered text with `{page}` and `{pages}` |
| `setHeaderHtml($html, $excludeFirstPage = false)` | Add HTML in the reserved top margin |
| `setFooterHtml($html, $excludeFirstPage = false)` | Add HTML in the reserved bottom margin; exclusion is independent of the header |
| `setBackgroundImage($source, $opacity = 1.0)` | Add a full-page image to subsequently rendered pages |
| `addBookmark($title, $level = 1, $page = null, $y = null)` | Add a flat outline entry; page is zero-based, y is in PDF points from the bottom; level is currently not used for nesting |
| `output(): string` | Return PDF bytes |
| `save($path): void` | Write PDF bytes to a file |
| `download($filename = 'document.pdf', $inline = false): void` | Send PDF headers and bytes; call before other HTTP output |

Configuration methods and `addHtml()` return the same instance for chaining. Header/footer HTML uses its own embedded styles. Avoid links and bookmarks in header/footer HTML; only its drawing operations are retained.

## Resource trust and deployment

Use **trusted templates and trusted font files**. TinyPDF is not a sandbox for arbitrary uploaded HTML. Local image and CSS font paths can read files accessible to PHP. `basePath` resolves paths; it does not restrict them to a directory. Escape untrusted text with `htmlspecialchars()` before inserting it into templates.

Remote images are disabled by default. `allowRemote: true` permits HTTP(S) requests and redirects, including destinations on the server's network; it does not provide SSRF filtering. Keep it disabled for user-controlled resources, or fetch and validate resources separately. Other PHP stream-wrapper image URLs are rejected. CSS font sources are local files only.

Bound document size, image dimensions, and execution/memory limits in the application. Test representative documents under your production PHP configuration. Missing/unreadable images are omitted; PNG/GIF/WebP require GD. For predictable release output, use explicit font files and verify the rendered PDF rather than relying only on successful generation.

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

All language fixtures are pinned and required; tests do not silently skip missing fonts. CI covers PHP 8.2–8.5, Linux x64/ARM64, macOS Intel/Apple Silicon, and Windows x64.

For visual validation, render your own multilingual/multipage templates in the readers your users rely on. The tests compare shaping with direct HarfBuzz output and exercise Unicode conformance and PDF rendering; they do not establish universal browser fidelity or certify every font and reader combination.

## License

[MIT](LICENSE), copyright Shahin Moyshan.
