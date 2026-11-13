<?php

declare(strict_types=1);

use Spark\Pdf\HtmlPdf;

test('HtmlPdf generates invoice with footer', function () {
    $pdf = new HtmlPdf('A4', margin: 12, footer: 'Page {page} of {pages}');
    $html = <<<'HTML'
    <h1 style="color:#dc2626">INVOICE #101</h1>
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #1f2937; color: #fff;">
                <th>Item</th>
                <th>Price</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Consulting</td><td>$500.00</td></tr>
            <tr><td>Development</td><td>$1,500.00</td></tr>
        </tbody>
    </table>
HTML;
    $pdf->setTitle('Invoice #101')->setAuthor('Acme Corp')->addHtml($html);
    $bytes = $pdf->output();

    check(str_starts_with($bytes, '%PDF-1.4'), 'Must start with PDF header');
    check(str_contains($bytes, '/Title'), 'Must contain title');
    check(str_contains($bytes, '/Author'), 'Must contain author');

    preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $m);
    $decompressed = gzuncompress($m[1]);
    check(str_contains($decompressed, 'Page 1 of 1'), 'Must contain footer text in decompressed content stream');
});

test('HtmlPdf generates ID card front and back', function () {
    $front = '<div style="width: 85mm; height: 54mm; background: #1e3a8a; color: #fff; padding: 10mm;">Student Card Front</div>';
    $back = '<div style="width: 85mm; height: 54mm; background: #f3f4f6; color: #111; padding: 10mm;">Student Card Back</div>';

    $card = new HtmlPdf('Card', margin: 0);
    $card->addHtml($front)->addHtml($back);
    $bytes = $card->output();

    check(str_starts_with($bytes, '%PDF-1.4'), 'Must generate valid PDF');
    check(str_contains($bytes, '/Count 2'), 'Must have 2 pages');
});
