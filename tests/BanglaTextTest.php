<?php

declare(strict_types=1);

use Spark\Pdf\HtmlPdf;

test('HtmlPdf renders Bengali HTML with custom registered font', function () {
    $pdf = new HtmlPdf('A4');
    $fontPath = testFont('bengali');
    $pdf->addFont('Bangla', $fontPath)->setFallbackFonts(['Bangla']);
    $html = '<h1 style="color:#0f766e">বাংলাদেশ আমার অহংকার</h1><p>যুক্তবর্ণ ও শিক্ষার আলো ছড়িয়ে দাও সর্বত্র।</p>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_starts_with($bytes, '%PDF-1.7'), 'Must start with PDF header');
    check(str_contains($bytes, '/Type0'), 'Must contain Type0 composite font for Bengali');
    check(str_contains($bytes, '/CIDFontType2'), 'Must contain CIDFontType2 descendant font');
    check(str_contains($bytes, '/ToUnicode'), 'Must contain ToUnicode CMap');
    check(str_ends_with(trim($bytes), '%%EOF'), 'Must end with %%EOF');
});

test('HtmlPdf registers custom font via CSS @font-face', function () {
    $pdf = new HtmlPdf('A4');
    $fontPath = testFont('bengali');
    $html = <<<HTML
    <style>
      @font-face {
        font-family: 'MyCustomBangla';
        src: url('$fontPath');
      }
      body { font-family: 'MyCustomBangla'; }
    </style>
    <h1>স্বাগতম</h1>
HTML;
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_contains($bytes, '/Type0'), 'Must register and embed font from @font-face');
});

test('HtmlPdf subsets the bundled Latin font', function () {
    $pdf = new HtmlPdf('A4');
    $html = '<h1>Invoice #101</h1><p>Customer: John Doe, New York, USA. Amount: $100.00</p>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_contains($bytes, '/Type0'), 'Latin text must use the shared Unicode pipeline');
    check(str_contains($bytes, '/FontFile2'), 'Must embed the selected outlines');
    check(strlen($bytes) < 18000, 'Latin subsets must remain compact');
});

test('HtmlPdf supports Chinese custom font and line wrapping', function () {
    $pdf = new HtmlPdf('A4');
    $fontPath = testFont('cjk');
    $pdf->addFont('Chinese', $fontPath);
    $html = '<h1 style="font-family: Chinese;">你好，世界</h1><p style="font-family: Chinese;">这是一个非常快速的HTML到PDF生成器。</p>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_contains($bytes, '/Type0'), 'Must embed Chinese CID font');
});
