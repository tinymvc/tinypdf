<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Spark\Pdf\HtmlPdf;

$pdf = new HtmlPdf(margin: 12);
$html = '<h1>Font containers and static instances</h1>';
foreach (['Latin.otf', 'Latin-CFF2.otf', 'Latin.woff', 'Latin.woff2', 'Latin.ttc'] as $i => $file) {
    $pdf->addFont('format' . $i, __DIR__ . '/fonts/formats/' . $file, faceIndex: $file === 'Latin.ttc' ? 1 : 0);
    $html .= '<p>' . $file . '</p><p style="font-family:format' . $i . ';font-size:22pt">Cubic office café CFF2 0123456789</p>';
}
$pdf->addHtml($html);
$html = '<h1>Color glyph formats — 600 DPI</h1>';
foreach (['cff_colr_1.otf', 'cff2_colr_1.otf', 'glyf_colr_1.ttf', 'picosvg.ttf', 'picosvgz.ttf', 'sbix.ttf'] as $i => $file) {
    $pdf->addFont('color' . $i, __DIR__ . '/fonts/color-formats/noto_handwriting-' . $file);
    $html .= '<div>' . $file . ' <span style="font-family:color' . $i . ';font-size:32pt">✍🏽</span></div>';
}
$pdf->addFont('bitmap', __DIR__ . '/fonts/cbdt/NotoColorEmoji.ttf');
$html .= '<p>CBDT/CBLC <span style="font-family:bitmap;font-size:32pt">😀 👨‍👩‍👧‍👦 🇧🇩 👍🏽</span></p>';
$pdf->addHtml($html)->save($argv[1] ?? dirname(__DIR__) . '/output/pdf/tinypdf-1.1.0-fonts.pdf');
