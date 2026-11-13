<?php

declare(strict_types=1);

namespace Spark\Pdf\Layout;

use DOMElement;
use function count;
use function is_string;

/**
 * Modern CSS Flexbox layout engine.
 * Computes coordinate frames for children in row/column flow with alignment, justification, and gap.
 */
final class FlexEngine
{
    /**
     * Lays out flex children and returns their assigned box geometry.
     *
     * @param list<array{el: DOMElement, s: array, naturalW: float, naturalH: float}> $items
     * @param float $containerX Container content X
     * @param float $containerY Container content Y
     * @param float $containerW Container content width
     * @param array $containerStyle Computed CSS of the flex container
     * @return array{items: list<array{el: DOMElement, s: array, x: float, y: float, w: float, h: float}>, totalH: float}
     */
    public static function layout(
        array $items,
        float $containerX,
        float $containerY,
        float $containerW,
        array $containerStyle
    ): array {
        if (empty($items)) {
            return ['items' => [], 'totalH' => 0.0];
        }

        $dir = $containerStyle['flex-direction'] ?? 'row';
        $isRow = !str_starts_with($dir, 'column');
        $isReverse = str_ends_with($dir, 'reverse');
        $justify = $containerStyle['justify-content'] ?? 'flex-start';
        $align = $containerStyle['align-items'] ?? 'stretch';
        $gap = (float) ($containerStyle['gap'] ?? 0.0);

        if ($isReverse) {
            $items = array_reverse($items);
        }

        return $isRow
            ? self::layoutRow($items, $containerX, $containerY, $containerW, $justify, $align, $gap)
            : self::layoutColumn($items, $containerX, $containerY, $containerW, $justify, $align, $gap);
    }

    private static function layoutRow(
        array $items,
        float $cx,
        float $cy,
        float $cw,
        string $justify,
        string $align,
        float $gap
    ): array {
        $count = count($items);
        $totalGap = max(0, $count - 1) * $gap;
        $availW = max(0.0, $cw - $totalGap);

        // Calculate flexible vs fixed widths
        $totalFlex = 0.0;
        $usedFixedW = 0.0;
        $itemWidths = [];

        foreach ($items as $i => $item) {
            $flex = (float) ($item['s']['flex'] ?? ($item['s']['flex-grow'] ?? 0.0));
            if ($flex > 0) {
                $totalFlex += $flex;
            } elseif ($item['s']['width'] !== null) {
                $w = is_string($item['s']['width']) && str_ends_with($item['s']['width'], '%')
                    ? ((float) $item['s']['width'] / 100) * $cw
                    : (float) $item['s']['width'];
                $itemWidths[$i] = $w;
                $usedFixedW += $w;
            } else {
                $itemWidths[$i] = $item['naturalW'];
                $usedFixedW += $item['naturalW'];
            }
        }

        // Allocate remaining width to flex items
        $freeW = max(0.0, $availW - $usedFixedW);
        foreach ($items as $i => $item) {
            $flex = (float) ($item['s']['flex'] ?? ($item['s']['flex-grow'] ?? 0.0));
            if ($flex > 0) {
                $itemWidths[$i] = $totalFlex > 0 ? ($flex / $totalFlex) * $freeW : 0.0;
            }
        }

        $rowW = array_sum($itemWidths) + $totalGap;
        $maxH = max(array_column($items, 'naturalH'));

        // Justification offset
        $remainW = max(0.0, $cw - $rowW);
        $startX = $cx;
        $spaceGap = $gap;

        switch ($justify) {
            case 'flex-end':
            case 'right':
                $startX = $cx + $remainW;
                break;
            case 'center':
                $startX = $cx + $remainW / 2;
                break;
            case 'space-between':
                if ($count > 1) {
                    $spaceGap = $gap + ($remainW / ($count - 1));
                }
                break;
            case 'space-around':
                $spaceGap = $gap + ($remainW / $count);
                $startX = $cx + ($remainW / $count) / 2;
                break;
            case 'space-evenly':
                $spaceGap = $gap + ($remainW / ($count + 1));
                $startX = $cx + ($remainW / ($count + 1));
                break;
        }

        $curX = $startX;
        $result = [];

        foreach ($items as $i => $item) {
            $w = $itemWidths[$i];
            $h = $align === 'stretch' ? $maxH : $item['naturalH'];
            $y = $cy;

            if ($align === 'center') {
                $y = $cy + ($maxH - $h) / 2;
            } elseif ($align === 'flex-end') {
                $y = $cy + ($maxH - $h);
            }

            $result[] = [
                'el' => $item['el'],
                's' => $item['s'],
                'x' => $curX,
                'y' => $y,
                'w' => $w,
                'h' => $h,
            ];

            $curX += $w + $spaceGap;
        }

        return ['items' => $result, 'totalH' => $maxH];
    }

    private static function layoutColumn(
        array $items,
        float $cx,
        float $cy,
        float $cw,
        string $justify,
        string $align,
        float $gap
    ): array {
        $curY = $cy;
        $result = [];
        $count = count($items);

        foreach ($items as $idx => $item) {
            $w = $item['s']['width'] !== null
                ? (is_string($item['s']['width']) && str_ends_with($item['s']['width'], '%')
                    ? ((float) $item['s']['width'] / 100) * $cw
                    : (float) $item['s']['width'])
                : ($align === 'stretch' ? $cw : $item['naturalW']);

            $x = $cx;
            if ($align === 'center') {
                $x = $cx + ($cw - $w) / 2;
            } elseif ($align === 'flex-end') {
                $x = $cx + ($cw - $w);
            }

            $h = $item['naturalH'];
            $result[] = [
                'el' => $item['el'],
                's' => $item['s'],
                'x' => $x,
                'y' => $curY,
                'w' => $w,
                'h' => $h,
            ];

            $curY += $h + ($idx + 1 < $count ? $gap : 0.0);
        }

        return ['items' => $result, 'totalH' => max(0.0, $curY - $cy)];
    }
}
