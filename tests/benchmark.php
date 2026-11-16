<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Spark\Pdf\HtmlPdf;

$results = [];
foreach (['card' => 1, 'invoice' => 4, '100-pages' => 100] as $name => $pages) {
    $start = hrtime(true);
    $pdf = new HtmlPdf(margin: 12);
    foreach (['bn' => 'notosansbengali', 'ar' => 'notonaskharabic', 'zh' => 'notosanssc'] as $alias => $pack) {
        $pdf->addFont($alias, glob(__DIR__ . '/fonts/' . $pack . '/*.ttf')[0]);
    }
    $pdf->setFallbackFonts(['bn', 'ar', 'zh']);
    $html = '<h2 lang="bn">বাংলা ভাষায় নির্ভুল নথি তৈরি</h2><p>Invoice 2026 — café</p>'
        . '<p lang="ar" dir="rtl">مَرْحَبًا بِالْعَالَمِ 2026</p><p lang="zh-Hans">你好，世界。</p>'
        . '<table style="width:100%"><tr><td>বাংলা শিক্ষা</td><td>$100.00</td></tr></table>';
    for ($i = 0; $i < $pages; $i++) $pdf->addHtml($html);
    $bytes = $pdf->output();
    $cold = (hrtime(true) - $start) / 1e6;
    $start = hrtime(true);
    $pdf->output();
    $warm = (hrtime(true) - $start) / 1e6;
    $manager = (new ReflectionProperty($pdf, 'fontManager'))->getValue($pdf);
    $calls = (new ReflectionProperty($manager->helper(), 'sequence'))->getValue($manager->helper());
    $results[$name] = ['pages' => $pages, 'cold_ms' => round($cold, 2), 'repeat_output_ms' => round($warm, 2),
        'php_peak_bytes' => memory_get_peak_usage(true), 'helper_requests_including_repeat_output' => $calls, 'pdf_bytes' => strlen($bytes)];
    unset($pdf, $manager);
}
echo json_encode(['php' => PHP_VERSION, 'platform' => PHP_OS_FAMILY . ' ' . php_uname('m'), 'results' => $results], JSON_PRETTY_PRINT) . "\n";
