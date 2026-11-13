# TinyPDF

Fast, dependency-free Modern **HTML to PDF** generator for PHP 8.2+.
Comprehensive multilingual support for **Bangla (বাংলা)**, Chinese (中文), Japanese (日本語), Arabic (العربية), and any custom TrueType/OpenType font, featuring **Flexbox**, **background images**, table **`rowspan`**, pure vector **SVG**, **clickable links**, and **inline badges**.

```bash
composer require tinymvc/tinypdf
```

**Zero external dependencies.** Requires only PHP 8.2+ and standard PHP extensions: `ext-dom`, `ext-mbstring`, `ext-zlib` (plus `ext-gd` for raster PNG/GIF/WebP images).

---

## Highlights

- ⚡ **Zero Bloat for English/USA Documents**: English/Latin documents use standard built-in PDF core fonts with **0 KB font embedding overhead**, generating microscopic PDFs (~2 KB) in milliseconds.
- 🌐 **Global Multilingual Support**: Supports custom `.ttf`, `.otf`, and `.ttc` fonts for **Bangla (বাংলা)**, Chinese (中文), Japanese (日本語), Korean (한국어), Arabic (العربية), Hindi, Cyrillic, and more.
- 🇧🇩 **Bangla & Complex Script Shaping**: Built-in syllable analysis, pre-base vowel reordering (`ি`, `ে`, `ৈ`), two-part vowel splitting (`ো`, `ৌ`), Reph (`র্`), and conjunct ligatures (যুক্তবর্ণ - `ক্ত`, `ক্ষ`, `জ্ঞ`, `শ্চ`, `হ্ম`...).
- 🀄 **CJK Line Breaking**: Native Unicode line breaking for Chinese, Japanese, and Korean text at character boundaries without requiring whitespace.
- 🎨 **CSS `@font-face` & `$pdf->addFont()`**: Register custom fonts either directly in PHP or via standard CSS `@font-face` declarations in `<style>` blocks.
- 📐 **Modern CSS Flexbox**: `display: flex` with `flex-direction: row | column`, `justify-content: space-between | center | flex-start | flex-end | space-around`, `align-items`, `gap`, and `flex: 1` / `flex-grow`.
- 🖼️ **Background Images**: Full `background-image: url(...)` support with `background-size: cover | contain | <length>`, `background-repeat`, `background-position`, and `border-radius` clipping.
- 📊 **Advanced Tables**: Full support for `<td rowspan="...">` and `<td colspan="...">`, repeating `<thead>` across multi-page tables, and border collapsing.
- 🎨 **Pure Vector SVG**: Crisp, infinite-resolution vector `<svg>` tags and `.svg` files directly rendered into native PDF vector operators (`path`, `rect`, `circle`, `ellipse`, `polygon`, `transforms`).
- 🔗 **Clickable Hyperlinks & Bookmarks**: Real PDF `/Link` annotations for `<a href="...">` links and automatic PDF document outlines/bookmarks.
- 🏷️ **Inline Box Model & Badges**: Inline elements (`<span>`) support `background`, `padding`, `border`, and `border-radius: 9999px` for status badges and pill tags.
- 💧 **Real Alpha Transparency**: ExtGState (`/ca` and `/CA`) support for genuine alpha blending with `opacity` and `rgba(...)`.

---

## Quick Start (English / Standard)

For standard English / Latin documents, TinyPDF uses built-in core fonts by default with zero font file bloat:

```php
use Spark\Pdf\HtmlPdf;

$pdf = new HtmlPdf('A4', margin: 12);
$pdf->setTitle('Invoice #42')->addHtml('
    <h1 style="color:#0f766e">Hello TinyPDF</h1>
    <p>Fast HTML to PDF with <b>zero dependencies</b> and microscopic file size.</p>
');

$pdf->save('invoice.pdf');          // ~2 KB PDF!
// $pdf->download('invoice.pdf');   // send to browser
```

---

## Custom Fonts for Bangla, Chinese, Arabic & Other Languages

You can register custom TrueType (`.ttf`), OpenType (`.otf`), or TrueType Collection (`.ttc`) fonts in two simple ways:

### 1. Registering via PHP API (`addFont`)

```php
use Spark\Pdf\HtmlPdf;

$pdf = new HtmlPdf('A4', margin: 15);

// Register custom fonts
$pdf->addFont('Bangla', 'path/to/kalpurush.ttf');
$pdf->addFont('Chinese', 'path/to/simsun.ttc');
$pdf->addFont('Arabic', 'path/to/amiri.ttf');

$html = <<<'HTML'
<!-- Bangla with automated syllable & conjunct shaping -->
<div style="font-family: Bangla; background: #f0fdf4; padding: 12px; border-radius: 8px;">
    <h2 style="color: #166534; margin: 0 0 6px 0;">বাংলাদেশ আমার অহংকার</h2>
    <p style="margin: 0;">যুক্তবর্ণ ও শিক্ষার আলো ছড়িয়ে দিতে TinyPDF এখন সম্পূর্ণ আধুনিক।</p>
</div>

<!-- Chinese with automated character boundary line-wrapping -->
<div style="font-family: Chinese; margin-top: 15px;">
    <h3>你好，世界！</h3>
    <p>这是一个非常快速、现代且支持多语言的HTML到PDF生成器。</p>
</div>
HTML;

$pdf->addHtml($html)->save('multilingual.pdf');
```

### 2. Registering via CSS `@font-face`

You can also use standard CSS `@font-face` right inside your HTML template:

```html
<style>
  @font-face {
    font-family: 'SolaimanLipi';
    src: url('fonts/SolaimanLipi.ttf');
  }
  @font-face {
    font-family: 'NotoSansSC';
    src: url('fonts/NotoSansSC.ttf');
  }
  body {
    font-family: 'NotoSansSC', 'SolaimanLipi', Helvetica, sans-serif;
  }
</style>

<h1>Custom Font via @font-face</h1>
<p>TinyPDF automatically parses and embeds your fonts.</p>
```

---

## Modern Invoice with Flexbox & Table Rowspan

```php
use Spark\Pdf\HtmlPdf;

$html = <<<'HTML'
<style>
  body { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; color: #334155; }
  .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 16px; }
  .logo { font-size: 18pt; font-weight: bold; color: #0f766e; }
  .badge { background: #dcfce7; color: #15803d; padding: 4px 10px; border-radius: 9999px; font-weight: bold; font-size: 9pt; }
  table.items { width: 100%; border-collapse: collapse; margin-top: 15px; }
  table.items th { background: #0f766e; color: #ffffff; padding: 8px; text-align: left; }
  table.items td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
  .right { text-align: right; }
  .center { text-align: center; }
</style>

<div class="header">
  <div>
    <span class="logo">Acme Systems Ltd</span>
    <div style="color: #64748b; font-size: 9pt;">New York, NY, USA</div>
  </div>
  <div class="right">
    <div style="font-size: 12pt; font-weight: bold;">INVOICE #1042</div>
    <div style="margin-top: 5px;"><span class="badge">PAID IN FULL</span></div>
  </div>
</div>

<table class="items">
  <thead>
    <tr>
      <th>Category</th>
      <th>Description</th>
      <th class="center">Qty</th>
      <th class="right">Price</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td rowspan="2" style="background: #f8fafc; font-weight: bold; vertical-align: top;">Cloud Hosting</td>
      <td>TinyPDF Enterprise Server (1 Year)</td>
      <td class="center">1</td>
      <td class="right">$1,200.00</td>
    </tr>
    <tr>
      <td>Automated Backup & SSL Security</td>
      <td class="center">1</td>
      <td class="right">$300.00</td>
    </tr>
    <tr>
      <td style="background: #f8fafc; font-weight: bold;">Support</td>
      <td>24/7 Priority Support & Maintenance</td>
      <td class="center">1</td>
      <td class="right">$500.00</td>
    </tr>
    <tr style="background: #f1f5f9; font-weight: bold;">
      <td colspan="3" class="right">Total:</td>
      <td class="right" style="color: #0f766e; font-size: 11pt;">$2,000.00</td>
    </tr>
  </tbody>
</table>
HTML;

$pdf = new HtmlPdf('A4', margin: [12, 12, 18, 12], footer: 'Page {page} of {pages}');
$pdf->addHtml($html)->save('invoice.pdf');
```

---

## API Reference

```php
new HtmlPdf(
    string|array $format = 'A4',      // 'A3', 'A4', 'A5', 'A6', 'Letter', 'Legal', 'Card' or [width, height] in mm
    string $orientation = 'P',        // 'P' (portrait) or 'L' (landscape)
    float|array $margin = 10,         // mm: one value, [vertical, horizontal] or [top, right, bottom, left]
    float $fontSize = 10,             // default font size in pt
    string $fontFamily = 'helvetica', // default font family
    ?string $basePath = null,         // base directory for relative images and fonts
    bool $allowRemote = false,        // allow http(s) images (trusted HTML only)
    ?string $footer = null,           // footer text; {page} and {pages} are replaced
);
```

### Methods

| Method | Description |
|---|---|
| `addHtml(string $html, $format = null, ?string $orientation = null)` | Renders HTML starting on a new page. Chaining returns `$this`. |
| `addFont(string $family, string $path, bool $bold = false, bool $italic = false)` | Registers a custom TrueType/OpenType (`.ttf`, `.otf`, `.ttc`) font family. |
| `setBackgroundImage(string $src, float $opacity = 1.0)` | Sets a full-bleed page background image or watermark. |
| `setTitle(string $title)` | Sets the PDF Title metadata. |
| `setAuthor(string $author)` | Sets the PDF Author metadata. |
| `setSubject(string $subject)` | Sets the PDF Subject metadata. |
| `setFooter(?string $footer)` | Sets footer text on every page (`{page}` and `{pages}` replaced). |
| `setHeaderHtml(?string $html)` | Sets dynamic HTML header on every page. |
| `setFooterHtml(?string $html)` | Sets dynamic HTML footer on every page. |
| `setBasePath(?string $path)` | Base directory for relative image and font paths. |
| `output(): string` | Compiles and returns raw PDF binary string. |
| `save(string $path)` | Writes the generated PDF to a file. |
| `download(string $filename = 'document.pdf', bool $inline = false)` | Sends appropriate headers and PDF stream to the browser. |

---

## Supported HTML & CSS

| Area | Features Supported |
|---|---|
| **Typography** | `color`, `font-family`, `font-size`, `font-weight`, `font-style`, `text-align` (incl. justify), `text-decoration`, `text-transform`, `line-height`, `white-space: pre` |
| **Multilingual** | **Bangla (বাংলা)**, Chinese (中文), Japanese (日本語), Korean (한국어), Arabic (العربية), Hindi, Cyrillic, and any custom `.ttf`/`.otf`/`.ttc` font |
| **Layout** | **Flexbox** (`display: flex`, `flex-direction`, `justify-content`, `align-items`, `gap`, `flex: 1`), `display: inline-block`, `position: relative/absolute` (`top/right/bottom/left`), `page-break-before/after/inside` |
| **Tables** | `table`, `thead`, `tbody`, `tfoot`, `tr`, `td`, `th`, **`rowspan`**, **`colspan`**, auto/percent widths, `border-collapse`, `cellpadding`, `cellspacing` |
| **Graphics & Images** | `<img>` (JPEG, PNG with transparency, WebP, GIF, Data URIs), **`<svg>` elements**, **`.svg` images**, `border-radius` (circles, rounded photo frames) |
| **Backgrounds** | `background-color`, **`background-image: url(...)`**, `background-repeat`, `background-position`, **`background-size: cover / contain`**, document watermarks |
| **Interactive** | **`<a href="...">` clickable hyperlinks**, internal anchor jumps, PDF document outlines / bookmarks (`<h1>`-`<h6>`) |
| **Styling & Box Model** | `margin` (incl. `auto`), `padding`, `border` (solid/dashed/dotted), `border-radius`, `box-sizing: border-box`, `opacity: 0.0-1.0`, `rgba(...)` with ExtGState |
| **Units** | `px`, `pt`, `mm`, `cm`, `in`, `em`, `rem`, `%` |

---

## Running Tests

```bash
composer test
# or
php tests/run.php
```

---

## License

MIT License. Free for commercial and open-source use.
