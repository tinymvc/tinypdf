#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$tests = in_array('--tests', $arguments, true);
$unicode = in_array('--unicode', $arguments, true);
$packs = array_values(array_filter($arguments, static fn($arg) => !str_starts_with($arg, '--')));
if (in_array('--help', $arguments, true)) {
    echo "Usage: php bin/install-fonts.php [--tests] [--unicode] [pack ...]\n"
        . "Installs checksummed Noto packs into fonts/, or pinned fixtures with --tests.\n"
        . "No pack names means all packs. --unicode also installs Unicode conformance data.\n";
    exit(0);
}
try {
    $manifest = json_decode(file_get_contents($root . '/tests/fonts/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $known = array_unique(array_column($manifest['files'], 'pack'));
    foreach ($packs as $pack) {
        if (!in_array($pack, $known, true)) throw new RuntimeException("Unknown font pack: $pack. Available: " . implode(', ', $known));
    }
    $files = array_filter($manifest['files'], static fn($file) => !$packs || in_array($file['pack'], $packs, true));
    if ($unicode) {
        $data = json_decode(file_get_contents($root . '/tests/unicode/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $files = [...$files, ...$data['files']];
    }
    foreach ($files as $file) {
        $relative = $file['file'];
        if (!$tests && str_starts_with($relative, 'tests/fonts/')) $relative = substr($relative, 6);
        $path = $root . '/' . $relative;
        if (is_file($path) && hash_file('sha256', $path) === $file['sha256']) continue;
        $context = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'TinyPDF/1.1.0 font installer']]);
        $bytes = @file_get_contents($file['url'], false, $context);
        if ($bytes === false) throw new RuntimeException('Download failed: ' . $file['url']);
        if (!hash_equals($file['sha256'], hash('sha256', $bytes))) throw new RuntimeException("Checksum mismatch: $relative");
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) throw new RuntimeException("Cannot create directory for $relative");
        $temporary = tempnam(dirname($path), '.tinypdf-');
        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $path)) throw new RuntimeException("Cannot install $relative");
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
        echo "Installed $relative\n";
    }
    echo "Verified TinyPDF {$manifest['version']} fonts and requested fixtures. PDF generation works offline.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
