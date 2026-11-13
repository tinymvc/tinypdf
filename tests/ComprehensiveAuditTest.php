<?php

declare(strict_types=1);

use Spark\Pdf\Font\ArabicShaper;
use Spark\Pdf\Font\BengaliShaper;
use Spark\Pdf\Font\FontManager;
use Spark\Pdf\Font\ToUnicodeCMap;
use Spark\Pdf\Font\TrueTypeFont;
use Spark\Pdf\Graphics\SvgRenderer;
use Spark\Pdf\HtmlPdf;
use Spark\Pdf\Layout\FlexEngine;
use Spark\Pdf\Layout\TableEngine;

function getDecompressedStreams(string $pdf): string
{
    $out = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches)) {
        foreach ($matches[1] as $stream) {
            $decomp = @gzuncompress($stream);
            if ($decomp !== false) {
                $out .= $decomp . "\n";
            }
        }
    }
    return $out;
}

test('English text with Windows-1252 symbols uses CoreFont with zero bloat', function () {
    $pdf = new HtmlPdf('A4');
    $html = '<p>Typography: “Smart quotes”, em-dash — euro €50, bullet • and trademark™.</p>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(!str_contains($bytes, '/Type0'), 'Must not embed Type0 font for Windows-1252 symbols');
    check(strlen($bytes) < 3000, 'Must produce microscopic PDF (< 3KB)');
});

test('ArabicShaper connects letters through Harakat diacritics', function () {
    // كَتَبَ (Kaf + Fatha + Teh + Fatha + Beh + Fatha)
    $textWithHarakat = "ك\u{064E}ت\u{064E}ب\u{064E}";
    $shaped = ArabicShaper::shape($textWithHarakat);

    // Initial Kaf (0xFEDB), Medial Teh (0xFE98), Final Beh (0xFE90)
    check(str_contains($shaped, mb_chr(0xFEDB)), 'Kaf must be Initial form despite Fatha');
    check(str_contains($shaped, mb_chr(0xFE98)), 'Teh must be Medial form despite Fatha');
    check(str_contains($shaped, mb_chr(0xFE90)), 'Beh must be Final form despite Fatha');
});

test('ArabicShaper preserves LTR order for numbers in RTL runs', function () {
    $text = 'تقرير 2024';
    $shaped = ArabicShaper::shape($text);

    // "2024" must not be reversed into "4202"
    check(str_contains($shaped, '2024'), 'Western digits must remain LTR');

    $arabicDigits = "تقرير \u{0662}\u{0660}\u{0662}\u{0664}"; // 2024 in Eastern Arabic-Indic
    $shapedAr = ArabicShaper::shape($arabicDigits);
    check(str_contains($shapedAr, "\u{0662}\u{0660}\u{0662}\u{0664}"), 'Arabic-Indic digits must remain LTR');
});

test('BengaliShaper reorders Devanagari pre-base vowel i', function () {
    // Devanagari कि = क (0x0915) + ि (0x093F) -> ि + क
    $shaped = BengaliShaper::shape("क\u{093F}");
    same("\u{093F}", $shaped[0], 'Devanagari pre-base vowel must come first');
    same('क', $shaped[1], 'Consonant follows pre-base vowel');
});

test('FontManager resolves font stack with multiple families', function () {
    $fm = new FontManager();
    $kalpurush = 'C:/Windows/Fonts/kalpurush.ttf';
    if (!is_file($kalpurush)) {
        skip('Kalpurush font not installed');
    }
    $fm->addFont('Bangla', $kalpurush);

    // Font stack with custom font and fallback
    $fontBangla = $fm->getFont("'Bangla', Helvetica, sans-serif", false, false, 'বাংলাদেশ');
    check($fontBangla instanceof TrueTypeFont, 'Must pick Bangla TrueType font for Bengali text');

    $fontEnglish = $fm->getFont("'Bangla', Helvetica, sans-serif", false, false, 'Hello World');
    check($fontBangla->supportsText('Hello World'), 'Kalpurush also supports Latin characters');
});

test('ToUnicodeCMap handles astral plane codepoints with surrogate pairs', function () {
    // Codepoint U+1F600 (Grinning Face Emoji) > 0xFFFF
    $cMap = ToUnicodeCMap::build([1 => 0x1F600]);
    check(str_contains($cMap, '<0001> <D83DDE00>'), 'Must produce UTF-16BE surrogate pair D83DDE00');
});

test('FlexEngine layoutColumn does not add extra trailing gap', function () {
    $items = [
        ['el' => new DOMElement('div'), 's' => ['width' => null], 'naturalW' => 100.0, 'naturalH' => 50.0],
        ['el' => new DOMElement('div'), 's' => ['width' => null], 'naturalW' => 100.0, 'naturalH' => 50.0],
    ];
    $layout = FlexEngine::layout($items, 0, 0, 200, [
        'flex-direction' => 'column',
        'gap' => 20.0,
    ]);

    // Total height must be 50 + 20 + 50 = 120 (NOT 140 with trailing gap)
    same(120.0, $layout['totalH'], 'Total height must only include gap between items');
});

test('TableEngine orders tfoot at bottom even if declared before tbody', function () {
    $doc = new DOMDocument();
    $doc->loadHTML('<table><tfoot><tr><td>Footer</td></tr></tfoot><tbody><tr><td>Body</td></tr></tbody></table>');
    $table = $doc->getElementsByTagName('table')->item(0);

    $grid = TableEngine::buildGrid($table, [], fn($el, $parent) => [
        'display' => 'block', 'width' => null, 'padding' => [0,0,0,0], 'border' => [],
    ]);

    same(2, count($grid['rows']), 'Must have 2 rows');
    same(false, $grid['rows'][0]['foot'], 'First row must be body row');
    same(true, $grid['rows'][1]['foot'], 'Second row must be footer row');
});

test('HtmlPdf supports direction: rtl and dir="rtl"', function () {
    $pdf = new HtmlPdf('A4');
    $html = '<div dir="rtl" style="font-family: sans-serif;"><p>مرحبا بالعالم</p></div>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_starts_with($bytes, '%PDF-1.4'), 'Must produce valid PDF');
});

test('HtmlPdf supports letter-spacing and word-spacing', function () {
    $pdf = new HtmlPdf('A4');
    $html = '<p style="letter-spacing: 2pt; word-spacing: 4pt;">Spaced Typography Text</p>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();
    $decomp = getDecompressedStreams($bytes);

    check(str_contains($decomp, '2 Tc'), 'Must emit 2 Tc character spacing operator');
    check(str_contains($decomp, '4 Tw'), 'Must emit 4 Tw word spacing operator');
});

test('HtmlPdf supports word-break: break-all', function () {
    $pdf = new HtmlPdf('A4');
    $longString = str_repeat('ABCDEFGHIJ', 10);
    $html = "<div style=\"width: 60pt; word-break: break-all;\"><p>{$longString}</p></div>";
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_starts_with($bytes, '%PDF-1.4'), 'Must break long word without whitespace');
});

test('HtmlPdf supports @page CSS rule for margins and orientation', function () {
    $pdf = new HtmlPdf('A4');
    $html = <<<'HTML'
    <style>
      @page {
        size: A4 landscape;
        margin: 20mm;
      }
    </style>
    <h1>Landscape Document via @page</h1>
HTML;
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    // Landscape A4 width is 841.89 pt
    check(str_contains($bytes, '/MediaBox [0 0 841.890 595.276]'), 'MediaBox must reflect landscape orientation from @page');
});

test('HtmlPdf generates automatic bookmarks for h1-h6 and manual bookmarks', function () {
    $pdf = new HtmlPdf('A4');
    $html = <<<'HTML'
    <h1>Chapter 1: Getting Started</h1>
    <p>Introduction to the library.</p>
    <h2>Section 1.1: Installation</h2>
    <p>Install via composer.</p>
HTML;
    $pdf->addHtml($html);
    $pdf->addBookmark('Manual Appendix', 1);
    $bytes = $pdf->output();

    $utf16Hex = fn(string $s) => strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8')));

    check(str_contains($bytes, '/Type /Outlines'), 'Must contain PDF Outlines dictionary');
    check(str_contains($bytes, $utf16Hex('Chapter 1: Getting Started')), 'Must contain Chapter 1 outline');
    check(str_contains($bytes, $utf16Hex('Section 1.1: Installation')), 'Must contain Section 1.1 outline');
    check(str_contains($bytes, $utf16Hex('Manual Appendix')), 'Must contain manual bookmark');
});

test('HtmlPdf supports internal page jump links', function () {
    $pdf = new HtmlPdf('A4');
    $html = <<<'HTML'
    <p><a href="#target-heading">Jump to Heading</a></p>
    <div style="height: 1000pt;"><p>Spacer content</p></div>
    <h2 id="target-heading">Target Heading</h2>
HTML;
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_contains($bytes, '/Dest ['), 'Must contain internal destination jump link');
});

test('SvgRenderer supports matrix transform', function () {
    $svg = '<svg width="100" height="100"><rect x="10" y="10" width="20" height="20" transform="matrix(1, 0, 0, 1, 30, 40)" /></svg>';
    $res = SvgRenderer::render($svg, 0, 0, 100, 100, 800);

    check(str_contains($res['ops'], '1.00000 0.00000 0.00000 1.00000 30.000 40.000 cm'), 'Must emit matrix cm transform');
});

test('HtmlPdf scopes opacity so alpha does not leak across elements', function () {
    $pdf = new HtmlPdf('A4');
    $html = <<<'HTML'
    <p style="opacity: 0.5;">Semi transparent text</p>
    <p>Opaque normal text</p>
HTML;
    $pdf->addHtml($html);
    $bytes = $pdf->output();
    $decomp = getDecompressedStreams($bytes);

    // Check that ExtGState is restored with Q
    check(str_contains($decomp, "/GS1 gs\n"), 'Opacity gs must be emitted');
    check(str_contains($decomp, "Q\n"), 'ExtGState must be restored with Q');
});
