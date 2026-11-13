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
        $rowsData = [];
        $occupied = []; // [rowIdx][colIdx] => true
        $maxCols = 0;
        $rowIdx = 0;

        $headRows = [];
        $footRows = [];

        foreach ($table->childNodes as $section) {
            if (!$section instanceof DOMElement) continue;
            $secTag = strtolower($section->tagName);
            $isHead = $secTag === 'thead';
            $isFoot = $secTag === 'tfoot';

            $trElements = $secTag === 'tr' ? [$section] : [];
            if (in_array($secTag, ['thead', 'tbody', 'tfoot'], true)) {
                $secStyle = $computeStyle($section, $tableStyle);
                if ($secStyle['display'] === 'none') continue;
                foreach ($section->childNodes as $child) {
                    if ($child instanceof DOMElement && strtolower($child->tagName) === 'tr') {
                        $trElements[] = $child;
                    }
                }
            }

            foreach ($trElements as $tr) {
                $rowStyle = $computeStyle($tr, $tableStyle);
                if ($rowStyle['display'] === 'none') continue;

                $cells = [];
                $colIdx = 0;

                foreach ($tr->childNodes as $cell) {
                    if (!$cell instanceof DOMElement) continue;
                    $cellTag = strtolower($cell->tagName);
                    if ($cellTag !== 'td' && $cellTag !== 'th') continue;

                    $cellStyle = $computeStyle($cell, $rowStyle);
                    if ($cellStyle['display'] === 'none') continue;

                    // Advance to next unoccupied slot
                    while (isset($occupied[$rowIdx][$colIdx])) {
                        $colIdx++;
                    }

                    $colspan = max(1, (int) $cell->getAttribute('colspan'));
                    $rowspan = max(1, (int) $cell->getAttribute('rowspan'));

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

                $rowsData[] = $rowEntry;
                if ($isHead) $headRows[] = $rowEntry;
                if ($isFoot) $footRows[] = $rowEntry;

                $rowIdx++;
            }
        }

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
                if ($cell['rowspan'] <= 1) continue;
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
