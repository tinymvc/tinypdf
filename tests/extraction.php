<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Spark\Pdf\HtmlPdf;

$scripts = json_decode(file_get_contents(__DIR__ . '/scripts.json'), true, 512, JSON_THROW_ON_ERROR);
$pdf = new HtmlPdf(margin: 12, fontSize: 12);
foreach ($scripts as $family => $sample) {
    $paths = array_values(array_filter(glob(__DIR__ . '/fonts/' . $family . '/*.ttf'),
        static fn($path) => !str_contains($path, 'Italic')));
    $pdf->addFont($family, $paths[0]);
}
$pdf->setFallbackFonts(array_keys($scripts));
$cases = [];
foreach ($scripts as $family => [$language, $text]) {
    $cases[] = ['text' => $text, 'family' => $family, 'language' => $language,
        'direction' => in_array($language, ['ar', 'ur', 'he'], true) ? 'rtl' : 'ltr'];
}
foreach ([
    ['প্র ক্ত র্ক ক্ষি ক্‍ষ ক্‌ষ কো কৌ', 'notosansbengali'],
    ['فارسی زبان زیبایی است', 'notonaskharabic'],
    ['abc العربية (PDF) 123 😀 نهاية', 'notosans'],
    ['😀 العربية 👨‍👩‍👧‍👦 نهاية', 'notosans'],
    ['𠮷野家 日本語 𠀋', 'notosansjp'],
    [str_repeat('😀', 80), 'notocoloremoji'],
    ['العربية ' . str_repeat('نص ', 40) . 'نهاية', 'notonaskharabic'],
    ["é e\u{0301} a\u{0306}\u{0301} office ffi " . str_repeat('long text ', 20), 'notosans'],
] as [$text, $family]) {
    $cases[] = ['text' => trim($text), 'family' => $family];
}
$cases[] = ['text' => implode('', array_map(static fn($cp) => mb_chr($cp, 'UTF-8'), range(0x4e00, 0x4f67))), 'family' => 'notosansjp', 'size' => 1];
$vertical = '日本語の縦書き。TinyPDF 1.1.0 正確な文字配置。こんにちは、世界。';
foreach (['vertical-rl', 'vertical-lr'] as $mode) {
    $cases[] = ['text' => $vertical, 'family' => 'notosansjp', 'mode' => $mode];
}
foreach ($cases as $case) {
    $style = 'font-family:' . $case['family'];
    if (isset($case['size'])) $style .= ';font-size:' . $case['size'] . 'pt';
    if (isset($case['mode'])) $style .= ';writing-mode:' . $case['mode'] . ';height:160pt;width:180pt;font-size:16pt';
    $html = '<div lang="' . ($case['language'] ?? 'und') . '" dir="' . ($case['direction'] ?? 'ltr')
        . '" style="' . $style . '">' . htmlspecialchars($case['text'], ENT_QUOTES, 'UTF-8') . '</div>';
    $pdf->addHtml($html);
}
$pdf->save($argv[1]);
echo json_encode($cases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
