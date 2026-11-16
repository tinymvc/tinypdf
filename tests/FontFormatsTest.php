<?php

declare(strict_types=1);

use Spark\Pdf\HtmlPdf;
use Spark\Pdf\Text\NativeHelper;

foreach (['Latin.otf', 'Latin-CFF2.otf', 'Latin.woff', 'Latin.woff2', 'Latin.ttc'] as $fixture) {
    test("Native font container $fixture embeds static outlines", function () use ($fixture) {
        $path = __DIR__ . '/fonts/formats/' . $fixture;
        check(is_file($path), 'Run python tests/prepare_formats.py to prepare required font fixtures.');
        $pdf = (new HtmlPdf())->addFont('format', $path, faceIndex: $fixture === 'Latin.ttc' ? 1 : 0)
            ->addHtml('<p style="font-family:format">Cubic office café CFF2 0123456789</p>')->output();
        $cff = str_ends_with($fixture, '.otf');
        check(str_contains($pdf, $cff ? '/CIDFontType0' : '/CIDFontType2'), 'Correct descendant type is required');
        check(str_contains($pdf, $cff ? '/CIDFontType0C' : '/FontFile2'), 'Font stream must match the outline format');
        same('Cubic office café CFF2 0123456789', logicalText(getDecompressedStreams($pdf)));
    });
}

foreach (['cff_colr_1.otf', 'cff2_colr_1.otf', 'glyf_colr_1.ttf', 'picosvg.ttf', 'picosvgz.ttf', 'sbix.ttf'] as $format) {
    test("Color format $format produces nonempty transparent glyphs", function () use ($format) {
        $path = __DIR__ . '/fonts/color-formats/noto_handwriting-' . $format;
        check(is_file($path), 'Missing pinned color font fixture.');
        $helper = new NativeHelper();
        $font = $helper->request('font', ['path' => $path]);
        $text = '✍🏽';
        $layout = $helper->request('layout', ['text' => $text, 'spans' => [
            ['start' => 0, 'end' => strlen($text), 'item' => 0, 'style' => ['fonts' => [$font['font']], 'size' => 24]],
        ]]);
        $glyph = $layout['lines'][0]['items'][0]['glyphs'][0]['gid'];
        $image = $helper->request('color', ['font' => $font['font'], 'gid' => $glyph, 'size' => 24]);
        check($image !== null && $image['width'] > 100 && $image['height'] > 100, '600 DPI glyph raster must be present');
        check(trim(base64_decode($image['alpha']), "\0") !== '', 'The raster must not be entirely transparent');
        $pdf = (new HtmlPdf())->addFont('color', $path)->addHtml('<p style="font-family:color">✍🏽</p>')->output();
        same('✍🏽', logicalText(getDecompressedStreams($pdf)));
        check(str_contains(getDecompressedStreams($pdf), '3 Tr'), 'Selectable companion text must be invisible');
    });
}

test('CBDT bitmap emoji can be shaped rasterized and subsetted', function () {
    $path = __DIR__ . '/fonts/cbdt/NotoColorEmoji.ttf';
    $pdf = (new HtmlPdf())->addFont('bitmap', $path)->addHtml('<p style="font-family:bitmap">😀 👨‍👩‍👧‍👦 🇧🇩 👍🏽</p>')->output();
    check(str_contains($pdf, '/SMask'), 'Bitmap emoji require transparent image resources');
    same('😀 👨‍👩‍👧‍👦 🇧🇩 👍🏽', logicalText(getDecompressedStreams($pdf)));
});


test('Unicode font paths work on every platform', function () {
    $base = tempnam(sys_get_temp_dir(), 'tinypdf-font-');
    $path = $base . '-বাংলা-日本語.ttf';
    rename($base, $path);
    try {
        copy(testFont('bengali'), $path);
        $pdf = (new HtmlPdf())->addFont('unicode-path', $path)
            ->addHtml('<p style="font-family:unicode-path">বাংলা ভাষা</p>')->output();
        same('বাংলা ভাষা', logicalText(getDecompressedStreams($pdf)));
    } finally {
        unlink($path);
    }
});
