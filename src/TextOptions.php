<?php

declare(strict_types=1);

namespace Spark\Pdf;

final readonly class TextOptions
{
    public function __construct(
        public ?string $helperPath = null,
        public string $defaultLanguage = 'und',
        public float $helperTimeout = 30.0,
        public int $colorGlyphDpi = 600,
    ) {
        if (!is_finite($helperTimeout) || $helperTimeout <= 0) {
            throw new \InvalidArgumentException('Helper timeout must be positive and finite.');
        }
        if ($colorGlyphDpi < 72 || $colorGlyphDpi > 2400) {
            throw new \InvalidArgumentException('Color glyph DPI must be between 72 and 2400.');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $defaultLanguage)) {
            throw new \InvalidArgumentException('Invalid default language tag.');
        }
    }
}
