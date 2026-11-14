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
                foreach ($node->childNodes as $col) {
                    if ($col instanceof DOMElement && strtolower($col->tagName) === 'col') {
                        $colStyles[] = $computeStyle($col, $tableStyle);
                    }
                }
            } elseif ($tag === 'col') {
                $colStyles[] = $computeStyle($node, $tableStyle);
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
        ];
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
