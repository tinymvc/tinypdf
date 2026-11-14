# TinyPDF

A pure-PHP HTML-to-PDF library for invoices, reports, receipts, and other controlled document templates. Requires PHP 8.2+; no browser, subprocess, JavaScript runtime, or Composer runtime packages.

TinyPDF implements a **document-oriented subset of HTML and CSS**, not a browser engine. Review the compatibility limits below before choosing it for a template. Arbitrary websites, complete OpenType shaping, and browser-identical rendering are outside its current scope.

## Install

```bash
composer require tinymvc/tinypdf
```

Required extensions: `dom`, `mbstring`, `zlib`, and `ctype`. Install `gd` for PNG, GIF, and WebP images. JPEG and inline SVG do not require GD. Composer checks the required extensions during installation.

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

Each `addHtml()` starts a new page. Repeated calls to `output()` rebuild page decorations without duplicating them. Pages may be added after output. Default Latin text uses the PDF core fonts without embedding a font file.

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

Set `basePath` to resolve relative image and font paths. Register separate bold/italic files when accurate variants matter. Missing variants reuse the available face; they do not synthesize new outlines. Automatic fallback searches a small set of common Windows, Linux, and macOS font paths. Explicit registration is more reproducible. If no font covers a text run, generation throws a `RuntimeException` instead of silently replacing its characters with question marks.

Supported font containers have **TrueType outlines**: TTF, TrueType-flavored OTF, WOFF 1, and the first face of a TTC. CFF/CFF2 OpenType, WOFF2, variable-font axes, and color emoji are unsupported. Unsupported or malformed fonts fail explicitly. Fonts are embedded in full, not subsetted; large CJK fonts can significantly increase file size and memory use. Ensure your fonts permit embedding.

Language coverage:

- Latin: core fonts cover Windows-1252; register a suitable font for additional characters.
- Bengali and Devanagari: basic vowel reordering and selected GSUB ligatures. Wrapping preserves combining sequences and Bengali/Devanagari virama clusters. Normal line height uses the selected font's ascent, descent, and line gap.
- Arabic: basic presentation-form joining and bidirectional runs, including digits and combining marks. Use an appropriate font and `dir="rtl"`. Mixed styled RTL runs, embeddings/isolates, and full Unicode bidi behavior are not implemented.
- CJK: embedded-font glyphs and character-boundary wrapping. Full language-specific punctuation and line-breaking rules are not implemented.

**Complex-script limitation:** the engine does not implement full OpenType GSUB contextual processing or GPOS mark positioning. Some Bengali conjuncts, reph, vowel/mark combinations, Arabic harakat, and other Indic scripts will differ from a browser. Validate the actual text and font visually. Embedded text includes logical Unicode `ActualText` for readers that support it; extraction behavior varies by PDF reader. The OpenType [Bengali shaping specification](https://learn.microsoft.com/en-us/typography/script-development/bengali) describes the broader behavior that is not fully implemented here.

## HTML and CSS compatibility

| Area | Implemented behavior | Limits |
|---|---|---|
| HTML | Headings, paragraphs, sections, lists, inline text, links, tables, images, inline SVG | No JavaScript, canvas, interactive forms, or external stylesheets |
| Cascade | Embedded and inline CSS, class/id/tag selectors, Unicode class names, descendant/child selectors, `:root`, `:first-child`, `:last-child`, `:nth-child`, `!important` | No attribute/sibling selectors, pseudo-elements, selector functions, or CSS nesting |
| Print CSS | `@page` size and margins; plain `@media print`, `all`, and `only print`; `<style media="print">` | No conditional media features, named pages, margin boxes, or `@supports` |
| Theme values | Inherited custom properties, `var()` and fallback values | No registered custom properties or `calc()` |
| Typography | Font family/size/weight/style, common `font` shorthand, alignment, underline/strike, transforms, spacing, `line-height` | No full typographic shaping or kerning; use explicit fonts for reliable results |
| Wrapping | Normal flow, `pre`, `pre-wrap`, `nowrap`, long-word breaking, CJK breaks | Overlong normal words may be broken to fit; `pre-line`, hyphenation, and full Unicode line breaking are unsupported |
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
);
```

Formats: `A3`, `A4`, `A5`, `A6`, `Letter`, `Legal`, `Card`, or custom `[width, height]` in mm. Strings such as `80x120` or `4in 6in` are accepted. Margins take one number, `[vertical, horizontal]`, or `[top, right, bottom, left]`. Orientation is `P` or `L`.

| Method | Purpose |
|---|---|
| `addHtml($html, $format = null, $orientation = null)` | Render a document or fragment on a new page |
| `addFont($family, $path, $bold = false, $italic = false)` | Register a local font face |
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

```bash
composer install
composer validate --strict
composer test
php tests/run.php BanglaText
php examples/invoice.php invoice.pdf
```

Tests search common host font paths. Set `TINYPDF_BENGALI_FONT` and `TINYPDF_CJK_FONT` to choose fonts explicitly. Set `TINYPDF_REQUIRE_FONTS=1` to fail instead of skipping font tests. The CI workflow checks PHP 8.2–8.5 with fonts installed; its results must pass before tagging a release.

For visual validation, render the invoice and your own multilingual/multipage templates in a PDF reader or with Poppler. Automated tests verify rendering operators and document structure, but do not establish universal browser fidelity or certify complex-script shaping.

## License

[MIT](LICENSE), copyright Shahin Moyshan.
