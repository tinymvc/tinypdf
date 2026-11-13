<?php

declare(strict_types=1);

use Spark\Pdf\Font\BengaliShaper;
use Spark\Pdf\HtmlPdf;

test('BengaliShaper detects Bengali characters', function () {
    check(BengaliShaper::containsBengali('বাংলাদেশ'), 'Should detect Bengali text');
    check(BengaliShaper::containsBengali('Hello বাংলা World'), 'Should detect mixed Bengali text');
    check(!BengaliShaper::containsBengali('Hello World 123'), 'Should not detect English text');
});

test('BengaliShaper reorders pre-base vowel signs', function () {
    // কি = ক (0995) + ি (09BF) -> visually ি + ক
    $shaped = BengaliShaper::shape('কি');
    same(BengaliShaper::SIGN_I, $shaped[0], 'Pre-base i-kar should be placed first');
    same('ক', $shaped[1], 'Consonant follows pre-base i-kar');

    // দে = দ (09A6) + ে (09C7) -> visually ে + দ
    $shapedE = BengaliShaper::shape('দে');
    same(BengaliShaper::SIGN_E, $shapedE[0], 'Pre-base e-kar should be placed first');
    same('দ', $shapedE[1], 'Consonant follows pre-base e-kar');
});

test('BengaliShaper decomposes two-part vowels', function () {
    // কো = ক + ো -> ে + ক + া
    $shapedO = BengaliShaper::shape('কো');
    same(BengaliShaper::SIGN_E, $shapedO[0], 'Pre-base part of o-kar is e-kar');
    same('ক', $shapedO[1], 'Consonant in middle');
    same(BengaliShaper::SIGN_AA, $shapedO[2], 'Post-base part of o-kar is aa-kar');
});

test('HtmlPdf renders Bengali HTML with custom registered font', function () {
    $pdf = new HtmlPdf('A4');
    $fontPath = 'C:/Windows/Fonts/kalpurush.ttf';
    if (!is_file($fontPath)) {
        skip('Kalpurush font not installed on host');
    }
    $pdf->addFont('Bangla', $fontPath);
    $html = '<h1 style="color:#0f766e">বাংলাদেশ আমার অহংকার</h1><p>যুক্তবর্ণ ও শিক্ষার আলো ছড়িয়ে দাও সর্বত্র।</p>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_starts_with($bytes, '%PDF-1.4'), 'Must start with PDF header');
    check(str_contains($bytes, '/Type0'), 'Must contain Type0 composite font for Bengali');
    check(str_contains($bytes, '/CIDFontType2'), 'Must contain CIDFontType2 descendant font');
    check(str_contains($bytes, '/ToUnicode'), 'Must contain ToUnicode CMap');
    check(str_ends_with(trim($bytes), '%%EOF'), 'Must end with %%EOF');
});

test('HtmlPdf registers custom font via CSS @font-face', function () {
    $pdf = new HtmlPdf('A4');
    $fontPath = 'C:/Windows/Fonts/kalpurush.ttf';
    if (!is_file($fontPath)) {
        skip('Kalpurush font not installed on host');
    }
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

test('HtmlPdf keeps English-only documents small with zero custom font bloat', function () {
    $pdf = new HtmlPdf('A4');
    $html = '<h1>Invoice #101</h1><p>Customer: John Doe, New York, USA. Amount: $100.00</p>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(!str_contains($bytes, '/Type0'), 'English document must not embed Type0 font');
    check(!str_contains($bytes, '/FontFile2'), 'English document must not embed FontFile2');
    check(strlen($bytes) < 3000, 'English document must stay tiny (< 3KB)');
});

test('HtmlPdf supports Chinese custom font and line wrapping', function () {
    $pdf = new HtmlPdf('A4');
    $fontPath = is_file('C:/Windows/Fonts/msyh.ttc') ? 'C:/Windows/Fonts/msyh.ttc' : (is_file('C:/Windows/Fonts/simsun.ttc') ? 'C:/Windows/Fonts/simsun.ttc' : null);
    if (!$fontPath) {
        skip('Chinese font not installed on host');
    }
    $pdf->addFont('Chinese', $fontPath);
    $html = '<h1 style="font-family: Chinese;">你好，世界</h1><p style="font-family: Chinese;">这是一个非常快速的HTML到PDF生成器。</p>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_contains($bytes, '/Type0'), 'Must embed Chinese CID font');
});
