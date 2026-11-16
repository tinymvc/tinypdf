#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$build = $root . '/native/build';

try {
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Enable proc_open() in PHP to build and run the TinyPDF helper.');
    }
    $configure = ['cmake', '-S', $root . '/native', '-B', $build, '-DCMAKE_BUILD_TYPE=Release'];
    if (PHP_OS_FAMILY === 'Windows' && !is_file($build . '/CMakeCache.txt')) {
        $configure = array_merge($configure, ['-G', 'Ninja']);
    }
    foreach ([$configure, ['cmake', '--build', $build, '--parallel']] as $command) {
        $process = @proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, null, ['bypass_shell' => true]);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Native build failed. Install CMake, a C++ compiler and the dependencies listed in native/README.md, then rerun this command.');
        }
    }

    require __DIR__ . '/tinypdf-check.php';
} catch (Throwable $error) {
    fwrite(STDERR, 'TinyPDF installation failed: ' . $error->getMessage() . "\n");
    exit(1);
}
