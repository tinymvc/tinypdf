<?php

declare(strict_types=1);

namespace Spark\Pdf\Text;

use Spark\Pdf\TextOptions;
use function is_resource;

/** One bounded, persistent, versioned helper connection per document. */
final class NativeHelper
{
    private const MAX_MESSAGE = 67108864;
    private mixed $process = null;
    private array $pipes = [];
    private int $sequence = 0;
    private string $diagnostics = '';
    private string $operation = '';
    private bool $failed = false;
    private ?array $capabilities = null;

    public function __construct(public readonly TextOptions $options = new TextOptions())
    {
    }

    public function capabilities(): array
    {
        $this->start();
        return $this->capabilities;
    }

    private function start(): void
    {
        if ($this->failed) {
            throw new \RuntimeException('The TinyPDF text helper failed; create a new document to retry.');
        }
        if (is_resource($this->process)) {
            return;
        }
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('TinyPDF ' . \Spark\Pdf\HtmlPdf::VERSION . ' requires proc_open() and the tinypdf-text native helper.');
        }
        $suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $root = dirname(__DIR__, 2);

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

        $path = $this->options->helperPath ?? getenv('TINYPDF_TEXT_HELPER') ?: "$root/bin/tinypdf-text$suffix";
        if ($this->options->helperPath === null && !getenv('TINYPDF_TEXT_HELPER') && !is_file($path)) {
            $path = "$root/native/build/tinypdf-text$suffix";
        }
        if (!is_file($path) || (PHP_OS_FAMILY !== 'Windows' && !is_executable($path))) {
            throw new \RuntimeException("TinyPDF text helper is missing or not executable: $path. Build native/ or set TextOptions::helperPath.");
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $this->startSocket($path);
        } else {
            $this->process = @proc_open(
                [$path],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $this->pipes,
                null,
                null,
                ['bypass_shell' => true, 'blocking_pipes' => false]
            );
            if (!is_resource($this->process)) {
                throw new \RuntimeException('Cannot launch the TinyPDF text helper.');
            }
            foreach ($this->pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
        }
        $this->capabilities = $this->request('capabilities');
        foreach (['layout', 'bidi', 'graphemes', 'fallback', 'subset', 'variations', 'vertical', 'color'] as $required) {
            if (!in_array($required, $this->capabilities['capabilities'] ?? [], true)) {
                $this->fail("Text helper lacks required capability: $required");
            }
        }
    }

    private function startSocket(string $path): void
    {
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        if ($listener === false)
            throw new \RuntimeException("Cannot open local text transport: $message");
        $diagnostics = tmpfile();
        if ($diagnostics === false) {
            fclose($listener);
            throw new \RuntimeException('Cannot create text helper diagnostic stream.');
        }
        $token = bin2hex(random_bytes(16));
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->process = @proc_open(
            [$path, '--connect', stream_socket_get_name($listener, false), $token],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => $diagnostics],
            $unused,
            null,
            null,
            ['bypass_shell' => true]
        );
        $this->pipes[2] = $diagnostics;
        try {
            if (!is_resource($this->process))
                $this->fail('Cannot launch the TinyPDF text helper');
            stream_set_blocking($listener, false);
            $deadline = microtime(true) + $this->options->helperTimeout;
            while (($socket = @stream_socket_accept($listener, 0)) === false)
                $this->poll($deadline, true);
            stream_set_blocking($socket, false);
            $this->pipes[0] = $this->pipes[1] = $socket;
            if (!hash_equals($token, $this->read(32, $deadline)))
                $this->fail('Text helper authentication failed');
        } finally {
            fclose($listener);
        }
    }

    public function request(string $operation, array $arguments = []): mixed
    {
        $this->start();
        $this->operation = $operation;
        $id = ++$this->sequence;
        $body = json_encode(
            ['protocol' => 1, 'id' => $id, 'op' => $operation] + $arguments,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        if (strlen($body) > self::MAX_MESSAGE) {
            throw new \RuntimeException('TinyPDF text request exceeds 64 MiB.');
        }
        $deadline = microtime(true) + $this->options->helperTimeout;
        $packet = pack('N', strlen($body)) . $body;
        $offset = 0;
        while ($offset < strlen($packet)) {
            $written = @fwrite($this->pipes[0], substr($packet, $offset, 8192));
            if ($written === false) {
                $this->fail('Cannot write to text helper');
            }
            $offset += $written;
            $this->poll($deadline, $written === 0);
        }
        $header = $this->read(4, $deadline);
        $length = unpack('N', $header)[1];
        if ($length < 1 || $length > self::MAX_MESSAGE) {
            $this->fail('Invalid text helper response size');
        }
        try {
            $response = json_decode($this->read($length, $deadline), true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            $this->fail('Invalid text helper JSON: ' . $error->getMessage());
        }
        if (($response['protocol'] ?? null) !== 1 || ($response['id'] ?? null) !== $id) {
            $this->fail('Incompatible text helper protocol or response ID');
        }
        if (isset($response['error'])) {
            throw new \RuntimeException('TinyPDF text: ' . ($response['error']['message'] ?? 'unknown native error'));
        }
        if (!array_key_exists('result', $response)) {
            $this->fail('Text helper response has no result');
        }
        return $response['result'];
    }

    private function read(int $length, float $deadline): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $chunk = @fread($this->pipes[1], min(65536, $length - strlen($result)));
            if ($chunk === false || ($chunk === '' && feof($this->pipes[1]))) {
                $this->fail('Text helper closed its output unexpectedly');
            }
            $result .= $chunk;
            $this->poll($deadline, $chunk === '');
        }
        return $result;
    }

    private function poll(float $deadline, bool $wait): void
    {
        if (stream_get_meta_data($this->pipes[2])['seekable']) {
            fseek($this->pipes[2], max(0, fstat($this->pipes[2])['size'] - 8192));
            $this->diagnostics = stream_get_contents($this->pipes[2]) ?: '';
        } else {
            $this->diagnostics = substr($this->diagnostics . (stream_get_contents($this->pipes[2]) ?: ''), -8192);
        }
        if (microtime(true) >= $deadline) {
            $this->fail('Text helper timed out');
        }
        if ($wait) {
            if (!proc_get_status($this->process)['running']) {
                $this->fail('Text helper exited unexpectedly');
            }
            usleep(1000);
        }
    }

    private function fail(string $message): never
    {
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            $this->diagnostics = substr($this->diagnostics . (stream_get_contents($this->pipes[2]) ?: ''), -8192);
        }
        $diagnostics = trim($this->diagnostics);
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if (!$status['running'])
                $message .= sprintf(' (exit %d, signal %d)', $status['exitcode'], $status['termsig']);
        }
        if ($this->operation !== '')
            $message .= ' during ' . $this->operation;
        $this->failed = true;
        $this->close();
        throw new \RuntimeException($message . ($diagnostics === '' ? '.' : ": $diagnostics"));
    }

    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe))
                fclose($pipe);
        }
        $this->pipes = [];
        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running'])
                proc_terminate($this->process, 9);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
