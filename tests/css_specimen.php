<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Spark\Pdf\HtmlPdf;

$pdf = new HtmlPdf(margin: 14, fontSize: 10);
foreach (['notosansbengali', 'notonaskharabic', 'notocoloremoji'] as $family) {
    $fonts = array_values(array_filter(glob(__DIR__ . '/fonts/' . $family . '/*.ttf'), static fn(string $path): bool => !str_contains($path, 'Italic')));
    if ($fonts)
        $pdf->addFont($family, $fonts[0]);
}
$pdf->setFallbackFonts(['notosansbengali', 'notonaskharabic', 'notocoloremoji']);
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 200 100"><rect width="200" height="100" fill="#dbeafe"/><circle cx="100" cy="50" r="40" fill="#2563eb"/><rect x="10" y="10" width="20" height="80" fill="#f97316"/></svg>';
$image = 'data:image/svg+xml;base64,' . base64_encode($svg);
$cards = '';
foreach (['contain', 'cover', 'none', 'scale-down'] as $fit)
    $cards .= '<div class="card"><img src="' . $image . '" style="object-fit:' . $fit . '"><p>' . $fit . '</p></div>';
$html = <<<'HTML'
<style>
body { color:#14243a; font-family:sans-serif; }
h1 { font-size:22pt; margin:0 0 6pt; }
h2 { font-size:13pt; margin:14pt 0 6pt; }
p { margin:5pt 0; }
.cards { display:flex; flex-wrap:wrap; gap:10pt; }
.card { flex:0 0 calc(25% - 10pt); }
.card img { width:84pt; height:64pt; padding:4pt; border:2pt solid #2563eb; background:#eff6ff; border-radius:7pt; }
.card p { text-align:center; }
[data-kind="note"] { padding:8pt; border:3pt double #2563eb; }
table { width:100%; table-layout:fixed; border-collapse:collapse; caption-side:bottom; }
td, th { border:1pt solid #94a3b8; padding:7pt; }
th { background:#e2e8f0; text-align:left; }
caption { padding:5pt; font-size:9pt; color:#475569; }
li:nth-child(even) { color:#2563eb; }
</style>
<h1>TinyPDF 1.2.2</h1>
<p>PHP HTML/CSS layout · native multilingual text</p>
<div data-kind="note">বাংলা ভাষায় নির্ভুল নথি — مرحبا بالعالم — 😀 👍🏽</div>
<h2>Image boxes and fitting</h2>
HTML;
$html .= '<div class="cards">' . $cards . '</div>';
$html .= <<<'HTML'
<h2>Flexible rows, ordering and column sizes</h2>
<div style="display:flex;flex-wrap:wrap;gap:8pt;width:330pt">
<div style="flex:0 0 150pt;padding:7pt;box-sizing:border-box;order:2;background:#dbeafe">Second in order</div>
<div style="flex:0 0 150pt;padding:7pt;box-sizing:border-box;order:1;background:#dcfce7">First in order</div>
<div style="flex:0 0 150pt;padding:7pt;box-sizing:border-box;order:3;background:#ffedd5">Wrapped row</div>
</div>
<h2>Fixed columns and a bottom caption</h2>
<table><caption>Column widths remain stable across rows.</caption><colgroup><col style="width:45%"><col span="2"></colgroup><thead><tr><th>Description</th><th>Quantity</th><th>Amount</th></tr></thead><tbody><tr><td>Document generation</td><td>2</td><td>$40.00</td></tr><tr><td>Native multilingual shaping</td><td>1</td><td>Included</td></tr></tbody></table>
<h2>List numbering, visibility and clipping</h2>
<ol reversed type="I" style="list-style-position:inside;margin:0;padding:0"><li>Reversed list</li><li value="8">Explicit item value</li><li>Numbering continues</li></ol>
<p>Before <span style="visibility:hidden">HIDDEN_TEXT</span> after the reserved space.</p>
<div style="height:25pt;overflow:hidden;border:1pt solid #94a3b8;padding:5pt;width:260pt">Visible clipped box<br>Second visible line<br>This third line is outside the clip.</div>
<p style="position:relative;left:12pt">Relative offset; following flow remains intact.</p>
HTML;
$pdf->addHtml($html);
$pdf->save($argv[1] ?? dirname(__DIR__) . '/output/pdf/tinypdf-1.2.2-css.pdf');
