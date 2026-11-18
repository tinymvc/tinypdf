<?php

declare(strict_types=1);

namespace Spark\Pdf\Layout;

final class FlexEngine
{
    public static function layout(array $items, float $containerX, float $containerY, float $containerW, array $style): array
    {
        if (!$items)
            return ['items' => [], 'totalH' => 0.0];
        usort($items, static fn(array $a, array $b): int => ($a['s']['order'] ?? 0) <=> ($b['s']['order'] ?? 0));
        $direction = $style['flex-direction'] ?? 'row';
        $row = !str_starts_with($direction, 'column');
        $reverse = str_ends_with($direction, 'reverse');
        $wrap = $style['flex-wrap'] ?? 'nowrap';
        $height = $style['contentHeight'] ?? null;
        $mainSize = $row ? $containerW : $height;
        $crossSize = $row ? $height : $containerW;
        $mainGap = (float) ($style[$row ? 'column-gap' : 'row-gap'] ?? $style['gap'] ?? 0.0);
        $crossGap = (float) ($style[$row ? 'row-gap' : 'column-gap'] ?? $style['gap'] ?? 0.0);
        $mainKey = $row ? 'naturalW' : 'naturalH';
        $crossKey = $row ? 'naturalH' : 'naturalW';
        $lines = [];
        $line = [];
        $used = 0.0;
        foreach ($items as $item) {
            $basis = $item['basis'] ?? $item[$mainKey];
            if ($line && $wrap !== 'nowrap' && $mainSize !== null && $used + $mainGap + $basis > $mainSize + .001) {
                $lines[] = $line;
                $line = [];
                $used = 0.0;
            }
            $used += ($line ? $mainGap : 0.0) + $basis;
            $line[] = $item;
        }
        $lines[] = $line;
        $crossSizes = array_map(static fn(array $line): float => max(array_column($line, $crossKey)), $lines);
        $crossUsed = array_sum($crossSizes) + $crossGap * (count($lines) - 1);
        $crossExtent = $crossSize ?? $crossUsed;
        $crossFree = max(0.0, ($crossSize ?? $crossUsed) - $crossUsed);
        $alignContent = $style['align-content'] ?? 'stretch';
        if ($alignContent === 'stretch' || ($wrap === 'nowrap' && count($lines) === 1)) {
            foreach ($crossSizes as &$size)
                $size += $crossFree / count($lines);
            unset($size);
            $crossFree = 0.0;
        }
        [$crossOffset, $crossGap] = self::spacing($alignContent, $crossFree, count($lines), $crossGap);
        $result = [];
        $totalMain = 0.0;
        foreach ($lines as $lineIndex => $line) {
            if ($reverse)
                $line = array_reverse($line);
            $available = $mainSize ?? (array_sum(array_map(static fn(array $item): float => $item['basis'] ?? $item[$mainKey], $line)) + $mainGap * (count($line) - 1));
            $sizes = self::sizes($line, max(0.0, $available - $mainGap * (count($line) - 1)), $mainKey);
            $mainUsed = array_sum($sizes) + $mainGap * (count($line) - 1);
            $justify = $style['justify-content'] ?? 'flex-start';
            if ($reverse)
                $justify = match ($justify) { 'flex-start' => 'flex-end', 'flex-end' => 'flex-start', default => $justify };
            [$mainOffset, $gap] = self::spacing($justify, max(0.0, $available - $mainUsed), count($line), $mainGap);
            foreach ($line as $index => $item) {
                $align = $item['s']['align-self'] ?? 'auto';
                if ($align === 'auto')
                    $align = $style['align-items'] ?? 'stretch';
                $stretch = $align === 'stretch' && ($item['s'][$row ? 'height' : 'width'] ?? null) === null;
                $cross = $stretch ? $crossSizes[$lineIndex] : $item[$crossKey];
                $offset = match ($align) {
                    'center' => ($crossSizes[$lineIndex] - $cross) / 2,
                    'flex-end', 'end' => $crossSizes[$lineIndex] - $cross,
                    default => 0.0,
                };
                $crossPosition = $wrap === 'wrap-reverse'
                    ? $crossExtent - $crossOffset - $offset - $cross
                    : $crossOffset + $offset;
                $result[] = [
                    'el' => $item['el'],
                    's' => $item['s'],
                    'x' => $containerX + ($row ? $mainOffset : $crossPosition),
                    'y' => $containerY + ($row ? $crossPosition : $mainOffset),
                    'w' => $row ? $sizes[$index] : $cross,
                    'h' => $row ? $cross : $sizes[$index],
                    'stretch' => $stretch,
                ];
                $mainOffset += $sizes[$index] + $gap;
            }
            $totalMain = max($totalMain, $available, $mainUsed);
            $crossOffset += $crossSizes[$lineIndex] + $crossGap;
        }
        return ['items' => $result, 'totalH' => $row ? $crossOffset - $crossGap : $totalMain];
    }

    private static function sizes(array $items, float $available, string $key): array
    {
        $sizes = [];
        $grow = [];
        $shrink = [];
        foreach ($items as $index => $item) {
            $sizes[$index] = max(0.0, $item['basis'] ?? $item[$key]);
            $grow[$index] = max(0.0, (float) ($item['s']['flex-grow'] ?? $item['s']['flex'] ?? 0.0));
            $shrink[$index] = max(0.0, (float) ($item['s']['flex-shrink'] ?? 1.0)) * $sizes[$index];
        }
        $free = $available - array_sum($sizes);
        $weights = $free >= 0 ? $grow : $shrink;
        $weight = array_sum($weights);
        if ($weight > 0) {
            foreach ($sizes as $index => &$size)
                $size = max(0.0, $size + $free * $weights[$index] / $weight);
            unset($size);
        }
        return $sizes;
    }

    private static function spacing(string $alignment, float $free, int $count, float $gap): array
    {
        return match ($alignment) {
            'flex-end', 'end', 'right' => [$free, $gap],
            'center' => [$free / 2, $gap],
            'space-between' => [0.0, $count > 1 ? $gap + $free / ($count - 1) : $gap],
            'space-around' => [$free / $count / 2, $gap + $free / $count],
            'space-evenly' => [$free / ($count + 1), $gap + $free / ($count + 1)],
            default => [0.0, $gap],
        };
    }
}
