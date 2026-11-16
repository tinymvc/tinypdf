<?php

declare(strict_types=1);

namespace Spark\Pdf\Text;

use Spark\Pdf\TextOptions;

/** One bounded, persistent, versioned helper connection per document. */
final class NativeHelper
{
    private const MAX_MESSAGE = 67108864;
    private mixed $process = null;
    private array $pipes = [];
    private int $sequence = 0;
    private string $diagnostics = '';
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
            throw new \RuntimeException('TinyPDF 1.1.0 requires proc_open() and the tinypdf-text native helper.');
        }
        $suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';
        $root = dirname(__DIR__, 2);
        $path = $this->options->helperPath ?? getenv('TINYPDF_TEXT_HELPER') ?: "$root/bin/tinypdf-text$suffix";
        if ($this->options->helperPath === null && !getenv('TINYPDF_TEXT_HELPER') && !is_file($path)) {
            $path = "$root/native/build/tinypdf-text$suffix";
        }
        if (!is_file($path) || (PHP_OS_FAMILY !== 'Windows' && !is_executable($path))) {
            throw new \RuntimeException("TinyPDF text helper is missing or not executable: $path. Build native/ or set TextOptions::helperPath.");
        }
        $this->process = @proc_open([$path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes,
            null, null, ['bypass_shell' => true, 'blocking_pipes' => false]);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Cannot launch the TinyPDF text helper.');
        }
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->capabilities = $this->request('capabilities');
        foreach (['layout', 'bidi', 'graphemes', 'fallback', 'subset', 'variations', 'vertical', 'color'] as $required) {
            if (!in_array($required, $this->capabilities['capabilities'] ?? [], true)) {
                $this->fail("Text helper lacks required capability: $required");
            }
        }
    }

    public function request(string $operation, array $arguments = []): mixed
    {
        $this->start();
        $id = ++$this->sequence;
        $body = json_encode(['protocol' => 1, 'id' => $id, 'op' => $operation] + $arguments,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
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
        $this->diagnostics = substr($this->diagnostics . (stream_get_contents($this->pipes[2]) ?: ''), -8192);
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
        $diagnostics = trim($this->diagnostics);
        $this->failed = true;
        $this->close();
        throw new \RuntimeException($message . ($diagnostics === '' ? '.' : ": $diagnostics"));
    }

    public function close(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
        $this->pipes = [];
        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) proc_terminate($this->process, 9);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
