<?php

declare(strict_types=1);

namespace Spark\Pdf\Font;

use Spark\Pdf\CoreFontMetrics;
use function ord;
use function strlen;

/**
 * Standard Adobe 14 Type 1 Core Font (Helvetica, Times, Courier).
 */
final class CoreFont implements FontInterface
{
    /** @var list<int> width per Windows-1252 byte in 1/1000 em */
    private array $widths;

    public function __construct(
        private readonly string $baseFont,
        public readonly bool $bold = false,
        public readonly bool $italic = false,
    ) {
        $this->widths = CoreFontMetrics::widths($this->baseFont);
    }

    public function getName(): string
    {
        return $this->baseFont;
    }

    public function getAscender(float $size): float
    {
        return 0.8 * $size;
    }

    public function getDescender(float $size): float
    {
        return -0.2 * $size;
    }

    public function getLineHeight(float $size): float
    {
        return 1.2 * $size;
    }

    public function getTextWidth(string $text, float $size): float
    {
        $encoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        $sum = 0;
        for ($i = 0, $n = strlen($encoded); $i < $n; $i++) {
            $sum += $this->widths[ord($encoded[$i])];
        }
        return ($sum * $size) / 1000;
    }

    public function encode(string $text): string
    {
        $win = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        return '(' . str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', '\\r'], $win) . ')';
    }

    public function getPdfFontObjects(int &$nextId, string $resName): array
    {
        $id = ++$nextId;
        $body = "<< /Type /Font /Subtype /Type1 /BaseFont /{$this->baseFont} /Encoding /WinAnsiEncoding >>";
        return [$id => $body];
    }
}
