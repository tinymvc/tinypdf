#!/usr/bin/env php
<?php

declare(strict_types=1);

require $_composer_autoload_path ?? dirname(__DIR__) . '/vendor/autoload.php';

try {
    $options = new Spark\Pdf\TextOptions(helperPath: $argv[1] ?? null);
    $manager = new Spark\Pdf\Font\FontManager($options);
    $capabilities = $manager->helper()->capabilities();
    $font = $manager->getFont('sans-serif');
    if (!$font->supportsText('TinyPDF café')) throw new RuntimeException('Bundled Latin font is incomplete.');
    $bytes = (new Spark\Pdf\HtmlPdf(textOptions: $options))->addHtml('<p>TinyPDF café</p>')->output();
    if (!str_contains($bytes, '/FontFile2')) throw new RuntimeException('Font embedding check failed.');
    echo json_encode(['status' => 'ok', 'php' => PHP_VERSION, 'helper' => $capabilities,
        'defaultFont' => $font->getName(), 'pdfBytes' => strlen($bytes)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "TinyPDF installation check failed: {$error->getMessage()}\n");
    exit(1);
}
