<?php

declare(strict_types=1);

namespace Spark\Pdf\Graphics;

use function sprintf;
use function strlen;

/**
 * Handles color parsing (hex, rgb, rgba, hsl, hsla, named colors) and PDF operator generation.
 */
final class Color
{
    /** @var array<string, array{0: int, 1: int, 2: int}> */
    private const NAMES = [
        'black' => [0, 0, 0], 'white' => [255, 255, 255], 'red' => [255, 0, 0], 'green' => [0, 128, 0],
        'blue' => [0, 0, 255], 'gray' => [128, 128, 128], 'grey' => [128, 128, 128], 'silver' => [192, 192, 192],
        'yellow' => [255, 255, 0], 'orange' => [255, 165, 0], 'purple' => [128, 0, 128], 'navy' => [0, 0, 128],
        'teal' => [0, 128, 128], 'maroon' => [128, 0, 0], 'lime' => [0, 255, 0], 'aqua' => [0, 255, 255],
        'cyan' => [0, 255, 255], 'fuchsia' => [255, 0, 255], 'magenta' => [255, 0, 255], 'olive' => [128, 128, 0],
        'brown' => [165, 42, 42], 'pink' => [255, 192, 203], 'gold' => [255, 215, 0], 'crimson' => [220, 20, 60],
        'indigo' => [75, 0, 130], 'coral' => [255, 127, 80], 'tomato' => [255, 99, 71], 'whitesmoke' => [245, 245, 245],
        'lightgray' => [211, 211, 211], 'lightgrey' => [211, 211, 211], 'darkgray' => [169, 169, 169],
        'darkgrey' => [169, 169, 169], 'darkblue' => [0, 0, 139], 'darkgreen' => [0, 100, 0], 'darkred' => [139, 0, 0],
        'lightblue' => [173, 216, 230], 'lightgreen' => [144, 238, 144], 'lightyellow' => [255, 255, 224],
    ];

    /**
     * @param array{0: int, 1: int, 2: int} $rgb
     * @param float $alpha 0.0 to 1.0
     */
    public function __construct(
        public readonly array $rgb,
        public readonly float $alpha = 1.0,
    ) {
    }

    /**
     * Parses CSS color string (hex, rgb, rgba, hsl, hsla, named color).
     */
    public static function parse(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }
        $v = strtolower(trim($value));
        if ($v === '' || $v === 'transparent' || $v === 'none') {
            return null;
        }

        if (isset(self::NAMES[$v])) {
            return new self(self::NAMES[$v], 1.0);
        }

        // Hex #RGB, #RGBA, #RRGGBB, #RRGGBBAA
        if (preg_match('/^#([0-9a-f]{3,8})$/', $v, $m)) {
            $hex = $m[1];
            $len = strlen($hex);
            if ($len === 3 || $len === 4) {
                $hex = preg_replace('/./', '$0$0', $hex) ?? $hex;
                $len = strlen($hex);
            }
            if ($len === 6) {
                return new self([
                    (int) hexdec(substr($hex, 0, 2)),
                    (int) hexdec(substr($hex, 2, 2)),
                    (int) hexdec(substr($hex, 4, 2)),
                ], 1.0);
            }
            if ($len === 8) {
                $alpha = round((int) hexdec(substr($hex, 6, 2)) / 255, 3);
                return new self([
                    (int) hexdec(substr($hex, 0, 2)),
                    (int) hexdec(substr($hex, 2, 2)),
                    (int) hexdec(substr($hex, 4, 2)),
                ], $alpha);
            }
        }

        // rgb(...) / rgba(...)
        if (preg_match('/^rgba?\(\s*([\d.]+%?)[\s,]+([\d.]+%?)[\s,]+([\d.]+%?)(?:[\s,\/]+([\d.]+%?))?\s*\)$/', $v, $m)) {
            $chan = static fn(string $c): float => str_ends_with($c, '%') ? (float) $c * 2.55 : (float) $c;
            $r = (int) round(max(0.0, min(255.0, $chan($m[1]))));
            $g = (int) round(max(0.0, min(255.0, $chan($m[2]))));
            $b = (int) round(max(0.0, min(255.0, $chan($m[3]))));
            $alpha = 1.0;
            if (isset($m[4])) {
                $alpha = str_ends_with($m[4], '%') ? (float) $m[4] / 100 : (float) $m[4];
                $alpha = max(0.0, min(1.0, $alpha));
            }
            return new self([$r, $g, $b], $alpha);
        }

        // hsl(...) / hsla(...)
        if (preg_match('/^hsla?\(\s*([\d.]+)(?:deg)?[\s,]+([\d.]+)%[\s,]+([\d.]+)%(?:[\s,\/]+([\d.]+%?))?\s*\)$/', $v, $m)) {
            $h = fmod((float) $m[1], 360) / 360;
            if ($h < 0) {
                $h += 1;
            }
            $s = max(0.0, min(1.0, (float) $m[2] / 100));
            $l = max(0.0, min(1.0, (float) $m[3] / 100));
            $alpha = 1.0;
            if (isset($m[4])) {
                $alpha = str_ends_with($m[4], '%') ? (float) $m[4] / 100 : (float) $m[4];
                $alpha = max(0.0, min(1.0, $alpha));
            }
            [$r, $g, $b] = self::hslToRgb($h, $s, $l);
            return new self([$r, $g, $b], $alpha);
        }

        return null;
    }

    /**
     * Converts HSL to [R, G, B] (0-255).
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s === 0.0) {
            $v = (int) round($l * 255);
            return [$v, $v, $v];
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        $hue2rgb = static function (float $p, float $q, float $t): float {
            if ($t < 0) $t += 1;
            if ($t > 1) $t -= 1;
            if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
            if ($t < 1 / 2) return $q;
            if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
            return $p;
        };

        return [
            (int) round($hue2rgb($p, $q, $h + 1 / 3) * 255),
            (int) round($hue2rgb($p, $q, $h) * 255),
            (int) round($hue2rgb($p, $q, $h - 1 / 3) * 255),
        ];
    }

    /**
     * PDF non-stroking color operator (rg).
     */
    public function rg(): string
    {
        return sprintf('%.3F %.3F %.3F rg', $this->rgb[0] / 255, $this->rgb[1] / 255, $this->rgb[2] / 255);
    }

    /**
     * PDF stroking color operator (RG).
     */
    public function rgStroke(): string
    {
        return sprintf('%.3F %.3F %.3F RG', $this->rgb[0] / 255, $this->rgb[1] / 255, $this->rgb[2] / 255);
    }
}
