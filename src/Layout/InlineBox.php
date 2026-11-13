<?php

declare(strict_types=1);

namespace Spark\Pdf\Layout;

use Spark\Pdf\Graphics\Color;
use function is_array;
use function sprintf;

/**
 * Handles inline styled boxes (badges, pills, tags, inline-blocks) with padding, borders, and rounded corners.
 */
final class InlineBox
{
    /**
     * Emits PDF drawing operators for an inline badge or box background and borders.
     *
     * @param float $x X coordinate
     * @param float $y Y coordinate from top of page
     * @param float $w Box width in pt
     * @param float $h Box height in pt
     * @param array $style Computed style of the inline box
     * @param float $pageH Page height in pt
     */
    public static function renderBox(
        float $x,
        float $y,
        float $w,
        float $h,
        array $style,
        float $pageH
    ): string {
        if ($w <= 0.0 || $h <= 0.0) {
            return '';
        }

        $out = "q\n";
        $radius = (float) ($style['radius'] ?? 0.0);
        if ($radius > 0.0) {
            $radius = min($radius, $w / 2, $h / 2);
        }

        // 1. Background fill
        if (isset($style['bg']) && $style['bg'] !== null) {
            $color = is_array($style['bg']) ? new Color($style['bg']) : $style['bg'];
            if ($color instanceof Color) {
                $out .= $color->rg() . "\n";
                if ($radius > 0.0) {
                    $out .= self::roundRectPath($x, $y, $w, $h, $radius, $pageH) . " f\n";
                } else {
                    $out .= sprintf("%.3F %.3F %.3F %.3F re f\n", $x, $pageH - $y - $h, $w, $h);
                }
            }
        }

        // 2. Borders
        $borders = $style['border'] ?? [];
        $hasBorders = false;
        foreach ($borders as $b) {
            if (($b['w'] ?? 0.0) > 0) {
                $hasBorders = true;
                break;
            }
        }

        if ($hasBorders) {
            $uniform = count($borders) === 4 &&
                $borders[0]['w'] > 0 &&
                $borders[0]['w'] === $borders[1]['w'] &&
                $borders[1]['w'] === $borders[2]['w'] &&
                $borders[2]['w'] === $borders[3]['w'];

            if ($radius > 0.0 && $uniform) {
                $bw = (float) $borders[0]['w'];
                $c = is_array($borders[0]['c']) ? new Color($borders[0]['c']) : $borders[0]['c'];
                if ($c instanceof Color) {
                    $out .= $c->rgStroke() . "\n";
                }
                $out .= sprintf("%.3F w\n", $bw);
                $out .= self::roundRectPath($x + $bw / 2, $y + $bw / 2, $w - $bw, $h - $bw, max(0.0, $radius - $bw / 2), $pageH) . " S\n";
            } else {
                // Stroke each side
                foreach ($borders as $i => $side) {
                    $bw = (float) ($side['w'] ?? 0.0);
                    if ($bw <= 0.0) continue;
                    $c = is_array($side['c']) ? new Color($side['c']) : $side['c'];
                    if ($c instanceof Color) {
                        $out .= $c->rgStroke() . "\n";
                    }
                    $out .= sprintf("%.3F w\n", $bw);
                    [$x1, $y1, $x2, $y2] = match ($i) {
                        0 => [$x, $y + $bw / 2, $x + $w, $y + $bw / 2], // top
                        1 => [$x + $w - $bw / 2, $y, $x + $w - $bw / 2, $y + $h], // right
                        2 => [$x, $y + $h - $bw / 2, $x + $w, $y + $h - $bw / 2], // bottom
                        default => [$x + $bw / 2, $y, $x + $bw / 2, $y + $h], // left
                    };
                    $out .= sprintf("%.3F %.3F m %.3F %.3F l S\n", $x1, $pageH - $y1, $x2, $pageH - $y2);
                }
            }
        }

        $out .= "Q\n";
        return $out;
    }

    private static function roundRectPath(float $x, float $y, float $w, float $h, float $r, float $pageH): string
    {
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
