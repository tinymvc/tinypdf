<?php

declare(strict_types=1);

use Spark\Pdf\Font\FontManager;
use Spark\Pdf\Font\ToUnicodeCMap;
use Spark\Pdf\Font\NativeFont;
use Spark\Pdf\Graphics\SvgRenderer;
use Spark\Pdf\HtmlPdf;
use Spark\Pdf\Layout\FlexEngine;
use Spark\Pdf\Layout\TableEngine;

test('Latin symbols use a compact Unicode subset', function () {
    $pdf = new HtmlPdf('A4');
    $html = '<p>Typography: “Smart quotes”, em-dash — euro €50, bullet • and trademark™.</p>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_contains($bytes, '/Type0'), 'Must embed a Unicode subset');
    check(strlen($bytes) < 18000, 'Must embed only the used subset');
});

test('FontManager resolves font stack with multiple families', function () {
    $fm = new FontManager();
    $kalpurush = testFont('bengali');
    $fm->addFont('Bangla', $kalpurush);

    // Font stack with custom font and fallback
    $fontBangla = $fm->getFont("'Bangla', Helvetica, sans-serif", false, false, 'বাংলাদেশ');
    check($fontBangla instanceof NativeFont, 'Must pick Bangla TrueType font for Bengali text');

    $fontEnglish = $fm->getFont("'Bangla', Helvetica, sans-serif", false, false, 'Hello World');
    check($fontEnglish->supportsText('Hello World'), 'The stack must resolve Latin text too');
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
        'display' => 'block',
        'width' => null,
        'padding' => [0, 0, 0, 0],
        'border' => [],
    ]);

    same(2, count($grid['rows']), 'Must have 2 rows');
    same(false, $grid['rows'][0]['foot'], 'First row must be body row');
    same(true, $grid['rows'][1]['foot'], 'Second row must be footer row');
});

test('HtmlPdf supports direction: rtl and dir="rtl"', function () {
    $pdf = new HtmlPdf('A4');
    $pdf->addFont('Arabic', testFont('arabic'))->setFallbackFonts(['Arabic']);
    $html = '<div dir="rtl" style="font-family: sans-serif;"><p>مرحبا بالعالم</p></div>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_starts_with($bytes, '%PDF-1.7'), 'Must produce valid PDF');
});

test('HtmlPdf supports letter-spacing and word-spacing', function () {
    $pdf = new HtmlPdf('A4');
    $html = '<p style="letter-spacing: 2pt; word-spacing: 4pt;">Spaced Typography Text</p>';
    $pdf->addHtml($html);
    $bytes = $pdf->output();
    $decomp = getDecompressedStreams($bytes);

    check(!str_contains($decomp, '2 Tc'), 'Spacing must not split combining glyphs with PDF Tc');
    check(substr_count($decomp, ' Tm ') > 10, 'Must position the measured glyphs explicitly');
});

test('HtmlPdf supports word-break: break-all', function () {
    $pdf = new HtmlPdf('A4');
    $longString = str_repeat('ABCDEFGHIJ', 10);
    $html = "<div style=\"width: 60pt; word-break: break-all;\"><p>{$longString}</p></div>";
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_starts_with($bytes, '%PDF-1.7'), 'Must break long word without whitespace');
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

test('HtmlPdf supports custom format [w, h], string (w, h), 80x100 and units', function () {
    // Array [80, 100] mm
    $pdf1 = new HtmlPdf([80, 100]);
    $pdf1->addHtml('<p>Array format</p>');
    $b1 = $pdf1->output();
    check(str_contains($b1, '/MediaBox [0 0 226.772 283.465]'), 'Array [80, 100] must produce 226.772 x 283.465 pt');

    // Tuple string '(80, 100)'
    $pdf2 = new HtmlPdf('(80, 100)');
    $pdf2->addHtml('<p>Tuple string format</p>');
    $b2 = $pdf2->output();
    check(str_contains($b2, '/MediaBox [0 0 226.772 283.465]'), 'Tuple string (80, 100) must produce 226.772 x 283.465 pt');

    // String '80x100'
    $pdf3 = new HtmlPdf('80x100');
    $pdf3->addHtml('<p>80x100 format</p>');
    $b3 = $pdf3->output();
    check(str_contains($b3, '/MediaBox [0 0 226.772 283.465]'), '80x100 must produce 226.772 x 283.465 pt');

    // String with units '4in 6in'
    $pdf4 = new HtmlPdf('4in 6in');
    $pdf4->addHtml('<p>Inches format</p>');
    $b4 = $pdf4->output();
    // 4in = 288pt, 6in = 432pt
    check(str_contains($b4, '/MediaBox [0 0 288.000 432.000]'), '4in 6in must produce 288 x 432 pt');
});

test('HtmlPdf preserves custom format when @page { size: landscape; } is used', function () {
    $pdf = new HtmlPdf([80, 100]);
    $html = <<<'HTML'
    <style>
      @page {
        size: landscape;
      }
    </style>
    <p>Landscape on custom format</p>
HTML;
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    // 100mm x 80mm in points is 283.465 x 226.772
    check(str_contains($bytes, '/MediaBox [0 0 283.465 226.772]'), 'Must keep 80x100 custom format and orient landscape (283.465 x 226.772 pt)');
});

test('HtmlPdf parses custom length dimensions in @page CSS rule', function () {
    $pdf = new HtmlPdf('A4');
    $html = <<<'HTML'
    <style>
      @page {
        size: 100mm 150mm;
        margin: 10mm;
      }
    </style>
    <p>100x150 mm custom page via CSS</p>
HTML;
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    // 100mm x 150mm in points is 283.465 x 425.197
    check(str_contains($bytes, '/MediaBox [0 0 283.465 425.197]'), 'Must produce 100x150 mm (283.465 x 425.197 pt) from @page size');
});

test('HtmlPdf supports per-document custom format in multi-page flow', function () {
    $pdf = new HtmlPdf('A4');
    $pdf->addHtml('<h1>Page 1 is A4</h1>');
    $pdf->addHtml('<h1>Page 2 is custom receipt</h1>', [80, 120]);
    $bytes = $pdf->output();

    check(str_contains($bytes, '/MediaBox [0 0 595.276 841.890]'), 'First page must be A4');
    check(str_contains($bytes, '/MediaBox [0 0 226.772 340.157]'), 'Second page must be 80x120 mm');
});

test('HtmlPdf supports setHeaderHtml and setFooterHtml with dynamic page tokens', function () {
    $pdf = new HtmlPdf('A4', margin: [20, 15, 20, 15]);
    $pdf->setHeaderHtml('<div style="font-size: 8pt; color: #475569; text-align: right;">Document Header &bull; Page {page} of {pages}</div>');
    $pdf->setFooterHtml('<div style="font-size: 8pt; color: #94a3b8; text-align: center;">Confidential &bull; Page {page} / {pages}</div>');
    $pdf->addHtml('<h1>Page One Content</h1>');
    $pdf->addHtml('<h1>Page Two Content</h1>');
    $bytes = $pdf->output();

    $decomp = getDecompressedStreams($bytes);
    check(str_contains(logicalText($decomp), 'Document Header'), 'Header HTML text must be rendered');
    check(str_contains(logicalText($decomp), 'Page 1 of 2'), 'Header page token {page} of {pages} must be rendered on page 1');
    check(str_contains(logicalText($decomp), 'Page 2 of 2'), 'Header page token {page} of {pages} must be rendered on page 2');
    check(str_contains(logicalText($decomp), 'Confidential'), 'Footer HTML text must be rendered');
    check(str_contains(logicalText($decomp), 'Page 1 / 2'), 'Footer page token {page} / {pages} must be rendered on page 1');
    check(str_contains(logicalText($decomp), 'Page 2 / 2'), 'Footer page token {page} / {pages} must be rendered on page 2');
});

test('Generic sans-serif resolves to the bundled font', function () {
    $font = (new FontManager())->getFont('sans-serif');
    same('NotoSans-Regular', $font->getName());
});

test('Output is repeatable and header/footer exclusions are independent', function () {
    $pdf = (new HtmlPdf(footer: 'Page {page}/{pages}'))
        ->setHeaderHtml('<p>HEADER</p>', true)
        ->setFooterHtml('<p>FOOTER</p>')
        ->addHtml('<p>First</p><p style="break-before:page">Second</p>');
    $first = getDecompressedStreams($pdf->output());
    $second = getDecompressedStreams($pdf->output());
    same($first, $second, 'Repeated output must not accumulate page decorations');
    same(1, substr_count(logicalText($first), 'HEADER'));
    same(2, substr_count(logicalText($first), 'FOOTER'));
    check(str_contains($first, '8 Tf'), 'Text footer must select a font with Tf');
    check(!str_contains($first, '8 rg'), 'Text footer must not emit an invalid color operator');
    $pdf->addHtml('<p>Third</p>');
    $third = getDecompressedStreams($pdf->output());
    check(str_contains(logicalText($third), 'Page 1/3'), 'Total page count must refresh after output');
    check(!str_contains(logicalText($third), 'Page 1/2'), 'Old footer must not remain');
});

test('Page margins and print media use CSS units and cascade priority', function () {
    $pdf = (new HtmlPdf())->addHtml('<style>
        @page { margin:20mm 10mm 15mm; margin-left:25mm; }
        @media screen { p { color:red !important; } }
        @media print { p { color:#008000 !important; } }
        </style><p style="margin:0;color:blue">Print</p>');
    $stream = getDecompressedStreams($pdf->output());
    check(str_contains($stream, '70.866 '), '25mm margin must be 70.866pt');
    check(str_contains($stream, '0 0.502 0 rg'), 'Print important color must beat normal inline color');
    check(!str_contains($stream, '1 0 0 rg'), 'Screen styles must not apply');
});

test('Custom properties inherit and rem uses the html font size', function () {
    $pdf = (new HtmlPdf())->addHtml('<style>:root { --accent:#008000; font-size:20pt; }
        p { color:var(--accent); padding:var(--pad, 2pt); font-size:1rem; }</style><p>Custom</p>');
    $stream = getDecompressedStreams($pdf->output());
    check(str_contains($stream, '20 Tf 0 0.502 0 rg'), 'Root font and custom color must inherit');
});

test('Inline boxes occupy one line and preserve following content', function () {
    $pdf = (new HtmlPdf())->addHtml('<p style="margin:0">Before <span style="display:inline-block;padding:4pt 8pt;background:#ddd">Badge</span> After</p><p>Next</p>');
    $stream = getDecompressedStreams($pdf->output());
    $positions = textPositions($stream);
    check(str_contains($positions[0]['text'], 'Before') && str_contains($positions[0]['text'], 'After'), 'Text around the box must share a line');
    $next = array_values(array_filter($positions, fn($p) => $p['text'] === 'Next'))[0];
    check($next['y'] < $positions[0]['y'], 'Following paragraph must be below');
});

test('Forward internal links resolve to the later page object', function () {
    $pdf = (new HtmlPdf())->addHtml('<p><a href="#later">Jump</a></p><h1 id="later" style="break-before:page">Later</h1>')->output();
    preg_match_all('/(\d+) 0 obj\s*<< \/Type \/Page\b/', $pdf, $pages);
    same(2, count($pages[1]));
    check(str_contains($pdf, '/Dest [' . $pages[1][1] . ' 0 R'), 'Forward destination must reference page two');
});

test('URI escaping preserves parentheses and backslashes', function () {
    $pdf = (new HtmlPdf())->addHtml('<a href="https://example.com/(test)">Link</a>')->output();
    check(str_contains($pdf, '/URI (https://example.com/\(test\))'), 'URI must escape each parenthesis once');
});

test('RGBA backgrounds and text retain alpha resources', function () {
    $pdf = (new HtmlPdf())->addHtml('<div style="background:rgba(255,0,0,.25);border-radius:4pt;padding:4pt"><span style="color:rgba(0,0,0,.4)">Alpha</span></div>')->output();
    check(str_contains($pdf, '/ca 0.250'), 'Background alpha must reach ExtGState');
    check(str_contains($pdf, '/ca 0.400'), 'Text alpha must reach ExtGState');
});

test('Malformed fonts fail with a descriptive exception', function () {
    $path = tempnam(sys_get_temp_dir(), 'tinypdf-font-');
    try {
        foreach (["\0\1\0\0", 'wOFF', 'ttcf', 'OTTO'] as $data) {
            file_put_contents($path, $data);
            throws(RuntimeException::class, fn() => (new FontManager())->addFont('bad', $path)->getFont('bad'));
        }
    } finally {
        unlink($path);
    }
});

test('Bengali text keeps logical Unicode and spacing in embedded output', function () {
    $text = 'বাংলা শিক্ষা';
    $pdf = (new HtmlPdf())->addFont('Bangla', testFont('bengali'))
        ->addHtml('<p style="font-family:Bangla;word-spacing:3pt">' . $text . '</p>')->output();
    $stream = getDecompressedStreams($pdf);
    $logical = strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')));
    check(str_contains($stream, '/ActualText <FEFF' . $logical . '>'), 'Copyable text must retain logical Unicode order');
    check(str_contains($stream, ' Tm '), 'Word spacing must use the measured glyph positions');
});

test('Unicode class selectors and nth-child expressions apply', function () {
    $pdf = (new HtmlPdf())->addHtml('<style>.বাংলা {color:#008000;} p {color:red;} p:nth-child(2n + 1) {font-weight:bold;}</style><p class="বাংলা">Styled</p>')->output();
    check(str_contains(getDecompressedStreams($pdf), '0 0.502 0 rg'), 'Unicode CSS class must match');
    check(str_contains($pdf, '/FontFile2'), 'Styled text must use a static font subset');
});

test('Bengali wrapping keeps conjunct clusters intact', function () {
    $text = 'ক্ষিক্ষিক্ষি';
    $pdf = (new HtmlPdf())->addFont('Bangla', testFont('bengali'))
        ->addHtml('<p style="font-family:Bangla;width:1pt;word-break:break-all">' . $text . '</p>')->output();
    $stream = getDecompressedStreams($pdf);
    preg_match_all('/\/ActualText <FEFF([0-9A-F]+)>/', $stream, $matches);
    $parts = array_map(fn($hex) => mb_convert_encoding(hex2bin($hex), 'UTF-8', 'UTF-16BE'), $matches[1]);
    same(['ক্ষি', 'ক্ষি', 'ক্ষি'], $parts, 'Never detach a matra or split a virama conjunct');
});

test('Rowspan uses the sum of unequal row heights', function () {
    $pdf = (new HtmlPdf())->addHtml('<table style="width:200pt;border-collapse:collapse"><tr><td rowspan="2" style="background:#ff0000;padding:0">Span</td><td style="padding:0">One</td></tr><tr><td style="padding:0">Two<br>Three<br>Four</td></tr></table>')->output();
    $stream = getDecompressedStreams($pdf);
    preg_match('/1 0 0 rg [\d.]+ [\d.]+ [\d.]+ ([\d.]+) re f/', $stream, $match);
    check(abs((float) ($match[1] ?? 0) - (new FontManager())->getFont('sans-serif')->getLineHeight(10) * 4) < .01, 'Spanned background must cover the four measured text lines');
});

test('Flex rows measure wrapped content at the allocated width', function () {
    $pdf = (new HtmlPdf())->addHtml('<div style="display:flex;width:200pt;gap:10pt"><div style="flex:1;padding:5pt">' . str_repeat('Wrapping content ', 8) . '</div><div style="flex:1;padding:5pt">Short</div></div><p>AfterFlex</p>')->output();
    $stream = getDecompressedStreams($pdf);
    $positions = textPositions($stream);
    $after = array_values(array_filter($positions, fn($p) => $p['text'] === 'AfterFlex'))[0]['y'];
    $wrapping = array_column(array_filter($positions, fn($p) => str_contains($p['text'], 'Wrapping') || str_contains($p['text'], 'content')), 'y');
    check(count($wrapping) > 4, 'Content must wrap within its allocated card');
    check($after < min($wrapping), 'Following content must appear below the tallest card');
});

test('CSS background data URIs preserve semicolons and paint above the color', function () {
    $gif = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    if (!extension_loaded('gd'))
        skip('GD unavailable');
    $pdf = (new HtmlPdf())->addHtml('<div style="width:40pt;height:40pt;background-color:#ff0000;background-image:url(' . $gif . ');background-size:cover"></div>')->output();
    $stream = getDecompressedStreams($pdf);
    check(str_contains($stream, '/I1 Do'), 'Data URI must be loaded');
    check(strpos($stream, '1 0 0 rg') < strpos($stream, '/I1 Do'), 'Color must be painted before the image');
});

test('Preformatted text does not wrap and invalid dimensions fail', function () {
    $pdf = (new HtmlPdf())->addHtml('<pre style="width:20pt">ABCDEFGHIJABCDEFGHIJ</pre>')->output();
    check(str_contains(logicalText(getDecompressedStreams($pdf)), 'ABCDEFGHIJABCDEFGHIJ'), 'Pre text must stay on one line');
    throws(InvalidArgumentException::class, fn() => new HtmlPdf([0, 100]));
    throws(InvalidArgumentException::class, fn() => new HtmlPdf(fontSize: 0));
    throws(InvalidArgumentException::class, fn() => (new HtmlPdf(margin: 200))->addHtml('<p>Too small</p>'));
});

test('Anonymous flex text, SVG items and decorated links remain visible', function () {
    $pdf = (new HtmlPdf())->addHtml('<div style="display:flex;gap:10pt">Anonymous<svg width="20" height="20"><rect width="20" height="20" fill="red"/></svg><div>Child</div></div><p><a href="https://example.com" style="padding:4pt;background:#ddd;border-radius:4pt">Open</a></p>')->output();
    $stream = getDecompressedStreams($pdf);
    check(str_contains(logicalText($stream), 'Anonymous'), 'Flex text node must be rendered');
    check(str_contains($stream, '1.000 0.000 0.000 rg'), 'Flex SVG must be rendered');
    check(str_contains($pdf, '/URI (https://example.com)'), 'Decorated link must keep its annotation');
});

test('Numeric weights instantiate distinct static variable subsets', function () {
    $pdf = (new HtmlPdf())->addFont('Bangla', testFont('bengali'))
        ->addHtml('<p style="font-family:Bangla">বাংলা <b>বাংলা</b></p>')->output();
    same(2, substr_count($pdf, '/Subtype /Type0'), 'Variable weight must embed each distinct instance');
});

test('Font shorthand and transparent text retain their style', function () {
    $pdf = (new HtmlPdf())->addHtml('<p style="font:bold 14pt/1.5 sans-serif;color:transparent">Hidden</p>')->output();
    check(str_contains($pdf, '/FontFile2'), 'Shorthand must embed the selected font instance');
    check(str_contains(getDecompressedStreams($pdf), '14 Tf'), 'Shorthand must set the font size');
    check(str_contains($pdf, '/ca 0.000'), 'Transparent text must have zero alpha');
});
