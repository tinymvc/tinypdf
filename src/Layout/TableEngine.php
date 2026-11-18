<?php

declare(strict_types=1);

namespace Spark\Pdf\Layout;

use DOMElement;
use function in_array;

/**
 * Advanced Table Layout Engine.
 * Supports rowspan 2D grid matrix mapping, colspan, repeating <thead>/<tfoot>, and proportional row height distribution.
 */
final class TableEngine
{
    /**
     * Builds a normalized 2D grid of table cells resolving rowspan and colspan.
     *
     * @return array{
     *   rows: list<array{s: array, cells: list<array>, head: bool, foot: bool}>,
     *   colCount: int,
     *   headRows: list<array>,
     *   footRows: list<array>
     * }
     */
    public static function buildGrid(DOMElement $table, array $tableStyle, callable $computeStyle): array
    {
        $occupied = []; // [rowIdx][colIdx] => true
        $maxCols = 0;
        $rowIdx = 0;

        $headRows = [];
        $bodyRows = [];
        $footRows = [];
        $colStyles = [];

        // Check for <colgroup> and <col> elements for column styles / widths
        foreach ($table->childNodes as $node) {
            if (!$node instanceof DOMElement)
                continue;
            $tag = strtolower($node->tagName);
            if ($tag === 'colgroup') {
                $groupStyle = $computeStyle($node, $tableStyle);
                $hasColumns = false;
                foreach ($node->childNodes as $col) {
                    if ($col instanceof DOMElement && strtolower($col->tagName) === 'col') {
                        $hasColumns = true;
                        $columnStyle = $computeStyle($col, $groupStyle);
                        $columnStyle['width'] ??= $groupStyle['width'];
                        for ($i = 0, $span = min(1000, max(1, (int) $col->getAttribute('span'))); $i < $span; $i++)
                            $colStyles[] = $columnStyle;
                    }
                }
                if (!$hasColumns) {
                    for ($i = 0, $span = min(1000, max(1, (int) $node->getAttribute('span'))); $i < $span; $i++)
                        $colStyles[] = $groupStyle;
                }
            } elseif ($tag === 'col') {
                $columnStyle = $computeStyle($node, $tableStyle);
                for ($i = 0, $span = min(1000, max(1, (int) $node->getAttribute('span'))); $i < $span; $i++)
                    $colStyles[] = $columnStyle;
            }
        }

        $sections = [];
        foreach ($table->childNodes as $node) {
            if ($node instanceof DOMElement)
                $sections[] = $node;
        }
        $rank = static fn(DOMElement $node): int => match (strtolower($node->tagName)) {
            'thead' => 0, 'tfoot' => 2, default => 1,
        };
        usort($sections, static fn(DOMElement $a, DOMElement $b): int => $rank($a) <=> $rank($b));
        foreach ($sections as $sectionIndex => $section) {
            $secTag = strtolower($section->tagName);
            $isHead = $secTag === 'thead';
            $isFoot = $secTag === 'tfoot';

            $trElements = $secTag === 'tr' ? [$section] : [];
            $secStyle = $tableStyle;
            if (in_array($secTag, ['thead', 'tbody', 'tfoot'], true)) {
                $occupied = [];
                $secStyle = $computeStyle($section, $tableStyle);
                if ($secStyle['display'] === 'none')
                    continue;
                foreach ($section->childNodes as $child) {
                    if ($child instanceof DOMElement && strtolower($child->tagName) === 'tr') {
                        $trElements[] = $child;
                    }
                }
            }

            foreach ($trElements as $sectionRow => $tr) {
                $rowStyle = $computeStyle($tr, $secStyle);
                if ($rowStyle['display'] === 'none')
                    continue;

                $cells = [];
                $colIdx = 0;

                foreach ($tr->childNodes as $cell) {
                    if (!$cell instanceof DOMElement)
                        continue;
                    $cellTag = strtolower($cell->tagName);
                    if ($cellTag !== 'td' && $cellTag !== 'th')
                        continue;

                    $cellStyle = $computeStyle($cell, $rowStyle);
                    if ($cellStyle['display'] === 'none')
                        continue;

                    // Advance to next unoccupied slot
                    while (isset($occupied[$rowIdx][$colIdx])) {
                        $colIdx++;
                    }

                    $colspan = min(1000, max(1, (int) $cell->getAttribute('colspan')));
                    $remainingRows = count($trElements) - $sectionRow;
                    if ($secTag === 'tr') {
                        for ($next = $sectionIndex + 1; isset($sections[$next]) && strtolower($sections[$next]->tagName) === 'tr'; $next++) {
                            $remainingRows++;
                        }
                    }
                    $rowspan = $cell->getAttribute('rowspan') === '0'
                        ? $remainingRows : min($remainingRows, max(1, (int) $cell->getAttribute('rowspan')));

                    // If cell doesn't have an explicit width, inherit from <col>
                    if ($cellStyle['width'] === null && isset($colStyles[$colIdx]['width'])) {
                        $cellStyle['width'] = $colStyles[$colIdx]['width'];
                    }

                    // Mark matrix occupancy for spanned slots
                    for ($r = 0; $r < $rowspan; $r++) {
                        for ($c = 0; $c < $colspan; $c++) {
                            $occupied[$rowIdx + $r][$colIdx + $c] = true;
                        }
                    }

                    $cells[] = [
                        'el' => $cell,
                        's' => $cellStyle,
                        'col' => $colIdx,
                        'row' => $rowIdx,
                        'colspan' => $colspan,
                        'rowspan' => $rowspan,
                    ];

                    $colIdx += $colspan;
                    $maxCols = max($maxCols, $colIdx);
                }

                $rowEntry = [
                    's' => $rowStyle,
                    'cells' => $cells,
                    'head' => $isHead,
                    'foot' => $isFoot,
                ];

                if ($isHead) {
                    $headRows[] = $rowEntry;
                } elseif ($isFoot) {
                    $footRows[] = $rowEntry;
                } else {
                    $bodyRows[] = $rowEntry;
                }

                $rowIdx++;
            }
        }

        // Standard HTML table section ordering: <thead>, <tbody>, <tfoot>
        $rowsData = array_merge($headRows, $bodyRows, $footRows);

        return [
            'rows' => $rowsData,
            'colCount' => $maxCols,
            'headRows' => $headRows,
            'footRows' => $footRows,
            'columns' => $colStyles,
        ];
    }

    public static function fixedWidths(array $grid, float $width, float $spacing, callable $resolve): array
    {
        $count = $grid['colCount'];
        $available = max(0.0, $width - $spacing * ($count + 1));
        $widths = array_fill(0, $count, null);
        foreach ($grid['columns'] as $index => $column) {
            if ($index < $count && $column['width'] !== null)
                $widths[$index] = max(0.0, $resolve($column['width'], $width));
        }
        foreach ($grid['rows'][0]['cells'] as $cell) {
            $style = $cell['s'];
            if ($style['width'] === null)
                continue;
            $target = $resolve($style['width'], $width);
            if ($style['sizing'] !== 'border') {
                $target += $resolve($style['padding'][1], $width) + $resolve($style['padding'][3], $width)
                    + $style['border'][1]['w'] + $style['border'][3]['w'];
            }
            $indices = range($cell['col'], $cell['col'] + $cell['colspan'] - 1);
            $unset = array_filter($indices, static fn(int $index): bool => $widths[$index] === null);
            $known = array_sum(array_map(static fn(int $index): float => $widths[$index] ?? 0.0, $indices));
            foreach ($unset as $index)
                $widths[$index] = max(0.0, ($target - $known - $spacing * ($cell['colspan'] - 1)) / count($unset));
        }
        $remaining = max(0.0, $available - array_sum($widths));
        $unset = count(array_filter($widths, static fn(?float $value): bool => $value === null));
        $extra = $remaining / max(1, $unset ?: $count);
        foreach ($widths as &$value)
            $value = $value === null ? $extra : $value + ($unset === 0 ? $extra : 0.0);
        unset($value);
        return $widths;
    }

    /**
     * Distributes row heights for multi-row spanned cells (rowspan > 1).
     *
     * @param list<array> $rows
     * @param array<int, float> $naturalRowHeights
     * @param array<string, float> $cellHeights key: "r_c" => required cell height
     * @return array<int, float> adjusted row heights
     */
    public static function distributeRowHeights(
        array $rows,
        array $naturalRowHeights,
        array $cellHeights
    ): array {
        $finalHeights = $naturalRowHeights;

        foreach ($rows as $r => $row) {
            foreach ($row['cells'] as $cell) {
                if ($cell['rowspan'] <= 1)
                    continue;
                $key = $cell['row'] . '_' . $cell['col'];
                $reqH = $cellHeights[$key] ?? 0.0;
                $rs = $cell['rowspan'];

                // Current sum of rows spanned
                $currentSum = 0.0;
                for ($k = 0; $k < $rs; $k++) {
                    $currentSum += $finalHeights[$r + $k] ?? 0.0;
                }

                // If spanning cell needs more height, distribute evenly among spanned rows
                if ($reqH > $currentSum) {
                    $diff = ($reqH - $currentSum) / $rs;
                    for ($k = 0; $k < $rs; $k++) {
                        $finalHeights[$r + $k] = ($finalHeights[$r + $k] ?? 0.0) + $diff;
                    }
                }
            }
        }

        return $finalHeights;
    }
}
