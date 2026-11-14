<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$tests = [];

class SkippedTest extends RuntimeException
{
}

function skip(string $reason): never
{
    throw new SkippedTest($reason);
}

function test(string $name, Closure $callback): void
{
    global $tests;
    $tests[$name] = $callback;
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message === '' ? 'Values differ' : $message)
            . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function throws(string $class, Closure $callback): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException("Expected {$class}.");
}

function testFont(string $script): string
{
    $paths = match ($script) {
        'bengali' => [
            getenv('TINYPDF_BENGALI_FONT') ?: '',
            'C:/Windows/Fonts/kalpurush.ttf',
            '/usr/share/fonts/truetype/noto/NotoSansBengali-Regular.ttf',
            '/System/Library/Fonts/Supplemental/Bangla Sangam MN.ttc',
        ],
        'cjk' => [
            getenv('TINYPDF_CJK_FONT') ?: '',
            'C:/Windows/Fonts/simsun.ttc',
            '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
            '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
        ],
        default => [],
    };
    foreach ($paths as $path) {
        if (is_file($path))
            return $path;
    }
    if (getenv('TINYPDF_REQUIRE_FONTS')) {
        throw new RuntimeException("Required $script test font is missing.");
    }
    skip("No $script test font installed; set TINYPDF_" . strtoupper($script) . '_FONT.');
}

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    if (isset($argv[1]) && !str_contains(basename($file), $argv[1])) {
        continue;
    }
    require $file;
}

if ($tests === []) {
    fwrite(STDERR, "No matching tests.\n");
    exit(1);
}
$failures = [];
$skipped = [];
$started = microtime(true);
foreach ($tests as $name => $callback) {
    try {
        $callback();
        echo '.';
    } catch (SkippedTest $error) {
        echo 'S';
        $skipped[] = "{$name}: {$error->getMessage()}";
    } catch (Throwable $error) {
        echo 'F';
        $failures[] = "{$name}: {$error->getMessage()}\n  {$error->getFile()}:{$error->getLine()}";
    }
}
echo "\n";
foreach ($failures as $failure) {
    echo "\nFAIL {$failure}\n";
}
foreach ($skipped as $reason) {
    echo "SKIP {$reason}\n";
}
printf("%d tests, %d failures, %d skipped (%.2fs)\n", count($tests), count($failures), count($skipped), microtime(true) - $started);
exit($failures === [] ? 0 : 1);