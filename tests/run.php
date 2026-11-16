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
    $pack = match ($script) {
        'bengali' => 'notosansbengali', 'cjk' => 'notosanssc', 'arabic' => 'notonaskharabic',
        default => $script,
    };
    $paths = array_filter(glob(__DIR__ . '/fonts/' . $pack . '/*.ttf'), static fn($path) => !str_contains($path, 'Italic'));
    if ($paths) return array_values($paths)[0];
    throw new RuntimeException("Required pinned font $pack is missing; run php bin/install-fonts.php.");
}

function getDecompressedStreams(string $pdf): string
{
    $out = '';
    if (preg_match_all('/\/Length (\d+)[^\r\n]*>>\r?\nstream\r?\n/', $pdf, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $start = $match[0][1] + strlen($match[0][0]);
            $decoded = @gzuncompress(substr($pdf, $start, (int) $match[1][0]));
            if ($decoded !== false && (str_contains($decoded, 'BT') || preg_match('/\b(?:Do|re|cm)\b/', $decoded))) $out .= $decoded . "\n";
        }
    }
    return $out;
}

function logicalText(string $stream): string
{
    preg_match_all('/\/ActualText <FEFF([0-9A-F]*)>/', $stream, $matches);
    return implode("\n", array_map(static fn($hex) => mb_convert_encoding(hex2bin($hex), 'UTF-8', 'UTF-16BE'), $matches[1]));
}

function textPositions(string $stream): array
{
    preg_match_all('/\/ActualText <FEFF([0-9A-F]*)>.*?([\d.-]+) ([\d.-]+) Tm/s', $stream, $matches, PREG_SET_ORDER);
    return array_map(static fn($m) => ['text' => mb_convert_encoding(hex2bin($m[1]), 'UTF-8', 'UTF-16BE'), 'x' => (float) $m[2], 'y' => (float) $m[3]], $matches);
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
