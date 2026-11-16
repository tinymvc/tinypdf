#!/usr/bin/env php
<?php

declare(strict_types=1);

// Locate project / package root
$root = dirname(__DIR__);
if (!is_file("$root/composer.json") && is_file(dirname(__DIR__, 2) . '/composer.json')) {
    $root = dirname(__DIR__, 2);
}

// Ensure development/CI prefix paths are searchable for DLLs and libraries
$prefixBin = "$root/native/prefix/bin";
if (is_dir($prefixBin)) {
    $curPath = getenv('PATH') ?: '';
    if (!str_contains($curPath, $prefixBin)) {
        putenv('PATH=' . $prefixBin . PATH_SEPARATOR . $curPath);
        $_SERVER['PATH'] = $prefixBin . PATH_SEPARATOR . $curPath;
    }
}
$prefixLib = "$root/native/prefix/lib";
if (is_dir($prefixLib) && PHP_OS_FAMILY !== 'Windows') {
    $ld = getenv('LD_LIBRARY_PATH') ?: '';
    if (!str_contains($ld, $prefixLib)) {
        putenv('LD_LIBRARY_PATH=' . $prefixLib . PATH_SEPARATOR . $ld);
        $_SERVER['LD_LIBRARY_PATH'] = $prefixLib . PATH_SEPARATOR . $ld;
    }
}

// Locate autoloader if available
$autoload = null;
foreach ([
    "$root/vendor/autoload.php",
    dirname(__DIR__, 3) . '/autoload.php',
    dirname(__DIR__, 4) . '/autoload.php',
] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        require_once $candidate;
        break;
    }
}

$version = class_exists(\Spark\Pdf\HtmlPdf::class) ? \Spark\Pdf\HtmlPdf::VERSION : '1.2.0';
$suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
$binaryPath = "$root/bin/tinypdf-text$suffix";
$buildBinaryPath = "$root/native/build/tinypdf-text$suffix";

$args = array_slice($argv ?? [], 1);
$forceBuild = in_array('--build', $args, true);
$forceDownload = in_array('--force', $args, true);

// Fast path: if binary is already present and passes health check, don't redo work
if (!$forceBuild && !$forceDownload) {
    $existing = is_file($binaryPath) ? $binaryPath : (is_file($buildBinaryPath) ? $buildBinaryPath : null);
    if ($existing !== null) {
        $checkCommand = [PHP_BINARY, __DIR__ . '/tinypdf-check.php', $existing];
        $process = @proc_open($checkCommand, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        if (is_resource($process)) {
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);

            if ($code === 0 && ($data = json_decode($stdout, true)) && ($data['status'] ?? null) === 'ok') {
                $installedVer = $data['helper']['version'] ?? 'unknown';
                if ($installedVer === $version) {
                    echo "✓ TinyPDF native text helper {$version} is already installed and verified.\n";
                    exit(0);
                }
            }
        }
    }
}

try {
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Enable proc_open() in PHP to run the TinyPDF native helper.');
    }

    $installed = false;

    // Attempt prebuilt download unless --build is requested
    if (!$forceBuild) {
        $installed = installPrebuilt($root, $version);
    }

    // Fallback: Build from source using CMake if prebuilt installation was not completed
    if (!$installed) {
        echo "Attempting to build native helper from source using CMake...\n";
        buildFromSource($root);
    }

    // Verify installation
    $argv = [$argv[0] ?? 'tinypdf-check.php'];
    require __DIR__ . '/tinypdf-check.php';
} catch (Throwable $error) {
    fwrite(STDERR, 'TinyPDF installation failed: ' . $error->getMessage() . "\n");
    exit(1);
}

function installPrebuilt(string $root, string $version): bool
{
    $os = match (PHP_OS_FAMILY) {
        'Windows' => 'windows',
        'Darwin' => 'darwin',
        'Linux' => 'linux',
        default => null,
    };

    $uname = strtolower(php_uname('m'));
    $arch = match (true) {
        str_contains($uname, 'arm64') || str_contains($uname, 'aarch64') => 'arm64',
        str_contains($uname, 'x86_64') || str_contains($uname, 'amd64') => 'x64',
        default => null,
    };

    if ($os === null || $arch === null) {
        echo "No prebuilt binary available for OS '{$uname}' architecture. Falling back to source build.\n";
        return false;
    }

    $ext = $os === 'windows' ? 'zip' : 'tar.gz';
    $bundleName = "tinypdf-text-{$version}-{$os}-{$arch}";
    $filename = "$bundleName.$ext";

    $repo = 'tinymvc/tinypdf';
    $url = "https://github.com/{$repo}/releases/download/v{$version}/{$filename}";

    echo "Downloading prebuilt TinyPDF native helper ({$os}-{$arch}) from GitHub Releases...\n";
    $tempArchive = tempnam(sys_get_temp_dir(), 'tinypdf-pkg-') . '.' . $ext;

    $downloadSuccess = false;
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 90,
            'follow_location' => 1,
            'max_redirects' => 10,
            'user_agent' => "TinyPDF/{$version} binary installer",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $data = @file_get_contents($url, false, $context);
    if ($data !== false && strlen($data) > 1000) {
        file_put_contents($tempArchive, $data);
        $downloadSuccess = true;
    } elseif (function_exists('exec')) {
        @exec('curl -sSL -A "TinyPDF-installer" ' . escapeshellarg($url) . ' -o ' . escapeshellarg($tempArchive), $out, $code);
        if ($code === 0 && file_exists($tempArchive) && filesize($tempArchive) > 1000) {
            $downloadSuccess = true;
        }
    }

    if (!$downloadSuccess) {
        if (file_exists($tempArchive)) {
            @unlink($tempArchive);
        }

        echo "Prebuilt release binary is not yet available at {$url}.\n";
        return false;
    }

    echo "Extracting prebuilt binary bundle...\n";
    $tempExtract = sys_get_temp_dir() . '/tinypdf-extract-' . bin2hex(random_bytes(8));
    if (!mkdir($tempExtract, 0755, true) && !is_dir($tempExtract)) {
        @unlink($tempArchive);
        return false;
    }

    try {
        if ($ext === 'zip') {
            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($tempArchive) === true) {
                    $zip->extractTo($tempExtract);
                    $zip->close();
                } else {
                    throw new RuntimeException('Failed to open zip archive.');
                }
            } else {
                @exec("powershell -NoProfile -Command \"Expand-Archive -Path " . escapeshellarg($tempArchive) . " -DestinationPath " . escapeshellarg($tempExtract) . " -Force\"", $pOut, $pCode);
                if ($pCode !== 0) {
                    throw new RuntimeException('Zip extraction failed.');
                }
            }
        } else {
            if (class_exists('PharData')) {
                $phar = new PharData($tempArchive);
                $phar->extractTo($tempExtract, null, true);
            } else {
                @exec("tar -xzf " . escapeshellarg($tempArchive) . " -C " . escapeshellarg($tempExtract), $tOut, $tCode);
                if ($tCode !== 0) {
                    throw new RuntimeException('Tar extraction failed.');
                }
            }
        }

        // Find extracted bundle root (usually $tempExtract/$bundleName)
        $bundleDir = is_dir("$tempExtract/$bundleName") ? "$tempExtract/$bundleName" : $tempExtract;
        $binDir = "$root/bin";
        $libDir = "$root/lib";

        if (!is_dir($binDir) && !mkdir($binDir, 0755, true)) {
            throw new RuntimeException("Cannot create directory {$binDir}");
        }

        // Copy binary and DLLs into bin/
        if (is_dir("$bundleDir/bin")) {
            foreach (scandir("$bundleDir/bin") as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $src = "$bundleDir/bin/$file";
                $dst = "$binDir/$file";
                copy($src, $dst);
                if (PHP_OS_FAMILY !== 'Windows' && $file === 'tinypdf-text') {
                    @chmod($dst, 0755);
                }
            }
        }

        // Copy shared libraries into lib/ (Linux/macOS)
        if (is_dir("$bundleDir/lib")) {
            if (!is_dir($libDir) && !mkdir($libDir, 0755, true)) {
                throw new RuntimeException("Cannot create directory {$libDir}");
            }

            foreach (scandir("$bundleDir/lib") as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                copy("$bundleDir/lib/$file", "$libDir/$file");
                if (PHP_OS_FAMILY !== 'Windows') {
                    @chmod("$libDir/$file", 0755);
                }
            }
        }

        echo "✓ Successfully installed prebuilt TinyPDF native helper.\n";
        return true;
    } finally {
        if (file_exists($tempArchive)) {
            @unlink($tempArchive);
        }
        cleanDir($tempExtract);
    }
}

function buildFromSource(string $root): void
{
    if (!is_file("$root/native/CMakeLists.txt")) {
        throw new RuntimeException('Native source code is not included in this Composer distribution. Please ensure internet access to download prebuilt release binaries, or clone from https://github.com/tinymvc/tinypdf.');
    }
    $build = "$root/native/build";
    $configure = ['cmake', '-S', "$root/native", '-B', $build, '-DCMAKE_BUILD_TYPE=Release'];
    if (PHP_OS_FAMILY === 'Windows' && !is_file("$build/CMakeCache.txt")) {
        $configure = [...$configure, '-G', 'Ninja'];
    }

    foreach ([$configure, ['cmake', '--build', $build, '--parallel']] as $command) {
        $process = @proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, null, ['bypass_shell' => true]);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Native build failed. Install CMake, a C++ compiler and the dependencies listed in native/README.md, or install a prebuilt release.');
        }
    }
}

function cleanDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = "$dir/$item";
        if (is_dir($path)) {
            cleanDir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}
