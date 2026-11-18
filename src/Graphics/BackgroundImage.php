<?php

declare(strict_types=1);

namespace Spark\Pdf\Graphics;

use Spark\Pdf\Css\Length;
use function count;
use function sprintf;

/**
 * Handles CSS background-image sizing, positioning, repeating, and clipping to box / border-radius.
 */
final class BackgroundImage
{
    /**
     * @param string $imageRes PDF XObject image resource name (e.g. "I1")
     * @param float $natW Native image width in pt
     * @param float $natH Native image height in pt
     * @param string $repeat 'repeat' | 'repeat-x' | 'repeat-y' | 'no-repeat'
     * @param string $position e.g. 'center', 'top left', '50% 50%', '10px 20px'
     * @param string $size 'auto' | 'cover' | 'contain' | '<width> <height>'
     * @param float $opacity 0.0 to 1.0
     */
    public function __construct(
        public readonly string $imageRes,
        public readonly float $natW,
        public readonly float $natH,
        public readonly string $repeat = 'no-repeat',
        public readonly string $position = '0% 0%',
        public readonly string $size = 'auto',
        public readonly float $opacity = 1.0,
    ) {
    }

    /**
     * Renders background image for a given box into PDF drawing operators.
     *
     * @param float $x Element X in pt
     * @param float $y Element Y from top of page in pt
     * @param float $w Element width in pt
     * @param float $h Element height in pt
     * @param float $radius Border radius in pt
     * @param float $pageH Total page height in pt
     * @param GraphicState|null $gs Graphics state manager for opacity
     */
    public function render(
        float $x,
        float $y,
        float $w,
        float $h,
        float $radius,
        float $pageH,
        ?GraphicState $gs = null
    ): string {
        if ($w <= 0.0 || $h <= 0.0 || $this->natW <= 0.0 || $this->natH <= 0.0) {
            return '';
        }

        // Calculate target dimensions
        [$imgW, $imgH] = $this->calculateSize($w, $h);
        if ($imgW <= 0.0 || $imgH <= 0.0) {
            return '';
        }

        // Calculate starting position
        [$posX, $posY] = $this->calculatePosition($x, $y, $w, $h, $imgW, $imgH);

        $out = "q\n";

        // Alpha state if semi-transparent
        if ($this->opacity < 0.999 && $gs !== null) {
            $gsName = $gs->getAlphaState($this->opacity, $this->opacity);
            $out .= "/$gsName gs\n";
        }

        // Clip to bounding box or rounded rectangle
        if ($radius > 0.0) {
            $out .= self::roundRectPath($x, $y, $w, $h, $radius, $pageH) . " W n\n";
        } else {
            $out .= sprintf("%.3F %.3F %.3F %.3F re W n\n", $x, $pageH - $y - $h, $w, $h);
        }

        // Draw image tile(s)
        $tiles = $this->calculateTiles($x, $y, $w, $h, $posX, $posY, $imgW, $imgH);
        foreach ($tiles as [$tx, $ty]) {
            $out .= sprintf(
                "q %.3F 0 0 %.3F %.3F %.3F cm /%s Do Q\n",
                $imgW, $imgH, $tx, $pageH - $ty - $imgH, $this->imageRes
            );
        }

        $out .= "Q\n";
        return $out;
    }

    /**
     * @return array{0: float, 1: float} width, height
     */
    private function calculateSize(float $boxW, float $boxH): array
    {
        $s = strtolower(trim($this->size));
        if ($s === 'cover') {
            $scale = max($boxW / $this->natW, $boxH / $this->natH);
            return [$this->natW * $scale, $this->natH * $scale];
        }
        if ($s === 'contain') {
            $scale = min($boxW / $this->natW, $boxH / $this->natH);
            return [$this->natW * $scale, $this->natH * $scale];
        }

        $parts = preg_split('/\s+(?![^(]*\))/', $s) ?: [];
        $wSpec = $parts[0] ?? 'auto';
        $hSpec = $parts[1] ?? 'auto';

        $w = $this->resolveLength($wSpec, $boxW, $this->natW);
        $h = $this->resolveLength($hSpec, $boxH, $this->natH);

        if ($w !== null && $h === null) {
            $h = $w * ($this->natH / $this->natW);
        } elseif ($h !== null && $w === null) {
            $w = $h * ($this->natW / $this->natH);
        } elseif ($w === null && $h === null) {
            $w = $this->natW;
            $h = $this->natH;
        }

        return [$w, $h];
    }

    private function resolveLength(string $v, float $base, float $natural): ?float
    {
        $v = trim($v);
        if ($v === 'auto' || $v === '') {
            return null;
        }
        $length = Length::parse($v, 12.0, 12.0, $base, $base);
        return $length === null ? null : Length::resolve($length, $base);
    }

    /**
     * @return array{0: float, 1: float}
     */
    public function calculatePosition(float $boxX, float $boxY, float $boxW, float $boxH, float $imgW, float $imgH): array
    {
        $pos = strtolower(trim($this->position));
        $tokens = preg_split('/\s+(?![^(]*\))/', $pos) ?: [];

        if (count($tokens) >= 3) {
            $coordinates = [$boxX + ($boxW - $imgW) / 2, $boxY + ($boxH - $imgH) / 2];
            for ($i = 0; $i < count($tokens); $i++) {
                $edge = $tokens[$i];
                if (!in_array($edge, ['left', 'right', 'top', 'bottom'], true))
                    continue;
                $horizontal = $edge === 'left' || $edge === 'right';
                $span = $horizontal ? $boxW - $imgW : $boxH - $imgH;
                $offset = isset($tokens[$i + 1]) ? $this->resolveLength($tokens[$i + 1], $span, 0) : null;
                if ($offset !== null)
                    $i++;
                $coordinates[$horizontal ? 0 : 1] = ($horizontal ? $boxX : $boxY)
                    + (in_array($edge, ['right', 'bottom'], true) ? $span - ($offset ?? 0) : ($offset ?? 0));
            }
            return $coordinates;
        }
        if (count($tokens) === 2 && (in_array($tokens[0], ['top', 'bottom'], true) || in_array($tokens[1], ['left', 'right'], true)))
            $tokens = array_reverse($tokens);

        $hVal = 'left';
        $vVal = 'top';

        if (count($tokens) === 1) {
            if (in_array($tokens[0], ['top', 'bottom'], true)) {
                $vVal = $tokens[0];
                $hVal = 'center';
            } else {
                $hVal = $tokens[0];
                $vVal = 'center';
            }
        } elseif (count($tokens) >= 2) {
            $hVal = $tokens[0];
            $vVal = $tokens[1];
        }

        $calcCoord = function (string $val, float $boxStart, float $boxSpan, float $imgSpan): float {
            if ($val === 'left' || $val === 'top') {
                return $boxStart;
            }
            if ($val === 'center') {
                return $boxStart + ($boxSpan - $imgSpan) / 2;
            }
            if ($val === 'right' || $val === 'bottom') {
                return $boxStart + ($boxSpan - $imgSpan);
            }
            $len = $this->resolveLength($val, $boxSpan - $imgSpan, $imgSpan) ?? 0.0;
            return $boxStart + $len;
        };

        return [
            $calcCoord($hVal, $boxX, $boxW, $imgW),
            $calcCoord($vVal, $boxY, $boxH, $imgH),
        ];
    }

    /**
     * @return list<array{0: float, 1: float}> list of [tileX, tileY]
     */
    private function calculateTiles(
        float $boxX,
        float $boxY,
        float $boxW,
        float $boxH,
        float $startX,
        float $startY,
        float $imgW,
        float $imgH
    ): array {
        $rep = strtolower(trim($this->repeat));
        if ($rep === 'no-repeat') {
            return [[$startX, $startY]];
        }

        $repeatX = $rep === 'repeat' || $rep === 'repeat-x';
        $repeatY = $rep === 'repeat' || $rep === 'repeat-y';

        $minX = $repeatX ? $boxX - fmod($boxX - $startX, $imgW) - $imgW : $startX;
        $maxX = $repeatX ? $boxX + $boxW + $imgW : $startX + $imgW;
        $minY = $repeatY ? $boxY - fmod($boxY - $startY, $imgH) - $imgH : $startY;
        $maxY = $repeatY ? $boxY + $boxH + $imgH : $startY + $imgH;

        $tiles = [];
        for ($ty = $minY; $ty < $maxY; $ty += $imgH) {
            for ($tx = $minX; $tx < $maxX; $tx += $imgW) {
                $tiles[] = [$tx, $ty];
            }
        }
        return $tiles;
    }

    private static function roundRectPath(float $x, float $y, float $w, float $h, float $r, float $pageH): string
    {
        $r = max(0.0, min($r, $w / 2, $h / 2));
        $k = $r * 0.5523;
        $top = $pageH - $y;
        $bottom = $pageH - $y - $h;
        $right = $x + $w;
        $fmt = fn(float $v): string => rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');

        return sprintf(
            '%s %s m %s %s l %s %s %s %s %s %s c %s %s l %s %s %s %s %s %s c %s %s l %s %s %s %s %s %s c %s %s l %s %s %s %s %s %s c h',
            $fmt($x + $r), $fmt($top),
            $fmt($right - $r), $fmt($top),
            $fmt($right - $r + $k), $fmt($top), $fmt($right), $fmt($top - $r + $k), $fmt($right), $fmt($top - $r),
            $fmt($right), $fmt($bottom + $r),
            $fmt($right), $fmt($bottom + $r - $k), $fmt($right - $r + $k), $fmt($bottom), $fmt($right - $r), $fmt($bottom),
            $fmt($x + $r), $fmt($bottom),
            $fmt($x + $r - $k), $fmt($bottom), $fmt($x), $fmt($bottom + $r - $k), $fmt($x), $fmt($bottom + $r),
            $fmt($x), $fmt($top - $r),
            $fmt($x), $fmt($top - $r + $k), $fmt($x + $r - $k), $fmt($top), $fmt($x + $r), $fmt($top)
        );
    }
}
