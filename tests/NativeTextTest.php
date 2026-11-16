<?php

declare(strict_types=1);

use Spark\Pdf\Font\FontManager;
use Spark\Pdf\HtmlPdf;
use Spark\Pdf\Text\NativeHelper;
use Spark\Pdf\TextOptions;

function nativeStyle(array $overrides = []): array
{
    return $overrides + ['family' => 'test', 'size' => 16.0, 'bold' => false, 'italic' => false, 'wrap' => true];
}

function nativeItems(string $text, array $style): array
{
    return [['t' => 'w', 'x' => $text, 'w' => 0.0, 's' => $style]];
}

test('Bengali document title remains logical and searchable', function () {
    $title = 'বাংলা ভাষায় নির্ভুল নথি তৈরি';
    $pdf = (new HtmlPdf())->addFont('Bangla', testFont('bengali'))->setFallbackFonts(['Bangla'])
        ->addHtml('<h1 lang="bn">' . $title . '</h1>')->output();
    same($title, logicalText(getDecompressedStreams($pdf)));
    check(str_contains($pdf, '/CIDToGIDMap'), 'Document CIDs must map explicitly through the subset');
});

test('Mixed script fallback chooses fonts by clusters', function () {
    $manager = (new FontManager())->addFont('bn', testFont('bengali'))->addFont('hi', testFont('notosansdevanagari'))
        ->addFont('zh', testFont('cjk'))->setFallbackFonts(['bn', 'hi', 'zh']);
    $text = 'Hello বাংলা हिन्दी 中文';
    $style = nativeStyle(['family' => 'sans-serif']);
    $lines = $manager->layout(nativeItems($text, $style), 1000, $style);
    same($text, $lines[0]['text']);
    check(count(array_unique(array_map(fn($item) => $item['shaped']->data['font'], $lines[0]['items']))) >= 4, 'Must use the required fallback fonts in one paragraph');
});

test('Compatible styled spans preserve Bengali conjuncts and Arabic joining', function () {
    foreach ([['bengali', 'ক্ষি', 'ক', '্ষি', 'ltr'], ['arabic', 'مرحبا', 'مر', 'حبا', 'rtl']] as [$pack, $text, $left, $right, $direction]) {
        $manager = (new FontManager())->addFont('test', testFont($pack));
        $style = nativeStyle(['direction' => $direction]);
        $plain = $manager->layout(nativeItems($text, $style), 1000, $style);
        $split = $manager->layout([...nativeItems($left, $style), ...nativeItems($right, ['color' => [1, 0, 0]] + $style)], 1000, $style);
        $glyphs = static function ($lines): array {
            $out = [];
            foreach ($lines[0]['items'] as $item) foreach ($item['shaped']->data['glyphs'] as $g) $out[] = [$g['gid'], $g['ax'], $g['ay'], $g['dx'], $g['dy'], $g['cluster']];
            return $out;
        };
        same($glyphs($plain), $glyphs($split));
        same($plain[0]['w'], $split[0]['w']);
    }
});

test('Narrow wrapping preserves graphemes across supported Indic scripts', function () {
    foreach ([['bengali', 'ক্ষি'], ['notosansdevanagari', 'क्षि'], ['notosansmalayalam', 'ക്തി']] as [$pack, $cluster]) {
        $manager = (new FontManager())->addFont('test', testFont($pack));
        $style = nativeStyle(['wordBreak' => 'break-all']);
        $lines = $manager->layout(nativeItems(str_repeat($cluster, 3), $style), 1, $style);
        same([$cluster, $cluster, $cluster], array_column($lines, 'text'));
    }
});

test('Unicode word breaks, nowrap, nonbreaking spaces and hard breaks are distinct', function () {
    $manager = new FontManager();
    $style = nativeStyle(['family' => 'sans-serif']);
    same(1, count($manager->layout(nativeItems("hello\u{00A0}world", $style), 10, $style)));
    same(1, count($manager->layout(nativeItems('unbreakableword', $style), 10, $style)));
    $anywhere = ['overflowWrap' => 'anywhere'] + $style;
    check(count($manager->layout(nativeItems('unbreakableword', $anywhere), 40, $anywhere)) > 2, 'Emergency wrapping must be opt-in');
    $nowrap = ['wrap' => false] + $style;
    same(1, count($manager->layout(nativeItems('one two three', $nowrap), 10, $nowrap)));
    same(['one', '', 'three'], array_column($manager->layout(nativeItems("one\n\nthree", $style), 100, $style), 'text'));
});

test('Thai dictionary breaking and CJK punctuation use ICU opportunities', function () {
    $manager = (new FontManager())->addFont('test', testFont('notosansthai'));
    $style = nativeStyle(['lang' => 'th']);
    $lines = $manager->layout(nativeItems('ภาษาไทยยินดีต้อนรับ', $style), 60, $style);
    check(count($lines) > 1, 'Thai words must wrap without ASCII spaces');
    $manager = (new FontManager())->addFont('test', testFont('notosansjp'));
    $style = nativeStyle(['lang' => 'ja', 'lineBreak' => 'strict']);
    foreach ($manager->layout(nativeItems('日本語「こんにちは」、世界。', $style), 50, $style) as $line) {
        check(!preg_match('/^[」、。]/u', $line['text']), 'Closing punctuation must not start a line');
    }
});

test('Variable fonts honor explicit axes over numeric weight', function () {
    $manager = (new FontManager())->addFont('test', testFont('notosans'));
    $light = nativeStyle(['weight' => 100]);
    $heavy = nativeStyle(['weight' => 900]);
    $override = ['variations' => ['wght' => 100]] + $heavy;
    $a = $manager->layout(nativeItems('Variable width', $light), 1000, $light);
    $b = $manager->layout(nativeItems('Variable width', $heavy), 1000, $heavy);
    $c = $manager->layout(nativeItems('Variable width', $override), 1000, $override);
    check(abs($a[0]['w'] - $b[0]['w']) > 1, 'Weight must change the shaped instance');
    same($a[0]['w'], $c[0]['w']);
    $condensed = $manager->layout(nativeItems('Variable width', ['stretch' => 75] + $light), 1000, $light);
    check($condensed[0]['w'] < $a[0]['w'], 'The width axis must affect the shaped instance');
});

test('Failures are explicit for missing helpers fonts malformed text and invalid options', function () {
    throws(RuntimeException::class, fn() => (new HtmlPdf(textOptions: new TextOptions(helperPath: '/missing/tinypdf-text')))->addHtml('<p>Hello</p>'));
    throws(InvalidArgumentException::class, fn() => (new HtmlPdf())->addHtml("<p>\xC0\xAF</p>"));
    throws(RuntimeException::class, fn() => (new HtmlPdf())->addHtml('<p>বাংলা</p>'));
    throws(InvalidArgumentException::class, fn() => new TextOptions(helperTimeout: 0));
    throws(InvalidArgumentException::class, fn() => new TextOptions(colorGlyphDpi: 0));
    throws(InvalidArgumentException::class, fn() => (new HtmlPdf())->addFont('bad', '/missing.ttf'));
    throws(RuntimeException::class, fn() => (new NativeHelper())->request('unknown-operation'));
});

test('Subsetting survives repeated output and newly added glyphs', function () {
    $pdf = (new HtmlPdf())->addFont('bn', testFont('bengali'))->setFallbackFonts(['bn'])->addHtml('<p>প্রাক্তন</p>');
    $first = getDecompressedStreams($pdf->output());
    same($first, getDecompressedStreams($pdf->output()));
    $pdf->addHtml('<p>শিক্ষার্থীদের পুনর্মিলনী ২০২৪</p>');
    $second = $pdf->output();
    check(str_contains($second, '/Count 2'), 'New pages must be retained');
    check(str_contains(logicalText(getDecompressedStreams($second)), 'প্রাক্তন'), 'Existing glyphs and source mappings must survive');
    check(str_contains(logicalText(getDecompressedStreams($second)), 'শিক্ষার্থীদের পুনর্মিলনী ২০২৪'), 'New glyphs must be added to the next subset: ' . logicalText(getDecompressedStreams($second)));
});

test('Vertical Japanese and color emoji retain logical Unicode', function () {
    $pdf = (new HtmlPdf())->addFont('ja', testFont('notosansjp'))->addFont('emoji', testFont('notocoloremoji'))
        ->addHtml('<div lang="ja" style="writing-mode:vertical-rl;font-family:ja;height:100pt;width:100pt">日本語 TinyPDF 縦書き</div><p style="font-family:emoji">👨‍👩‍👧‍👦 🇧🇩 👍🏽</p>')->output();
    $text = logicalText(getDecompressedStreams($pdf));
    check(str_contains(str_replace("\n", '', $text), '日本語 TinyPDF'), 'Vertical text must retain its source order');
    check(str_contains($text, '👨‍👩‍👧‍👦 🇧🇩 👍🏽'), 'Emoji sequences must remain selectable');
    check(str_contains($pdf, '/Subtype /Image') && str_contains($pdf, '/SMask'), 'Color glyphs must use transparent rasters');
});

test('Vertical table cells and positioned boxes preserve supplementary Han', function () {
    $pdf = (new HtmlPdf())->addFont('ja', testFont('notosansjp'))->addHtml(
        '<table style="width:100%"><tr><td style="height:150pt;width:180pt;writing-mode:vertical-lr;font-family:ja">日本語 TinyPDF 𠮷</td>'
        . '<td>Horizontal cell</td></tr></table><div style="position:absolute;top:220pt;left:30pt;width:120pt;height:120pt;writing-mode:vertical-rl;font-family:ja">日本語の縦書き</div>'
    )->output();
    $logical = str_replace("\n", '', logicalText(getDecompressedStreams($pdf)));
    check(str_contains($logical, '日本語 TinyPDF 𠮷') && str_contains($logical, 'Horizontal cell'), 'Both table orientations must retain text');
    check(str_contains($logical, '日本語の縦書き'), 'Positioned vertical text must retain its source');
    check(str_contains($pdf, '/Count 1'), 'The fixed boxes must fit on one page');
});

test('Vertical column overflow paginates without losing source text', function () {
    $text = str_repeat('日本語', 40);
    $pdf = (new HtmlPdf())->addFont('ja', testFont('notosansjp'))->addHtml(
        '<div style="writing-mode:vertical-rl;font-family:ja;width:50pt;height:60pt">' . $text . '</div>'
    )->output();
    same($text, str_replace("\n", '', logicalText(getDecompressedStreams($pdf))));
    preg_match('/\/Type \/Pages \/Kids \[.*?\] \/Count (\d+)/s', $pdf, $pages);
    check((int) ($pages[1] ?? 0) > 1, 'Overflowing columns must continue on another page');
});
