<?php

declare(strict_types=1);

namespace Spark\Pdf\Font;

use function count;
use function is_int;
use function sprintf;

/**
 * Builds PostScript ToUnicode CMap streams for PDF CID-keyed fonts.
 * Enables text selection, copying, and searchability in all standard PDF viewers.
 */
final class ToUnicodeCMap
{
    /**
     * @param array<int, int|string> $glyphToUnicode Map of glyphId => Unicode codepoint (or UTF-16BE hex string)
     */
    public static function build(array $glyphToUnicode): string
    {
        ksort($glyphToUnicode);

        $stream = "/CIDInit /ProcSet findresource begin\n"
            . "12 dict begin\n"
            . "begincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Custom-ToUnicode def\n"
            . "/CMapType 2 def\n"
            . "1 begincodespacerange\n"
            . "<0000> <FFFF>\n"
            . "endcodespacerange\n";

        // Group into chunks of at most 100 entries per beginbfchar
        $chunks = array_chunk($glyphToUnicode, 100, true);
        foreach ($chunks as $chunk) {
            $stream .= count($chunk) . " beginbfchar\n";
            foreach ($chunk as $gid => $uni) {
                $gidHex = sprintf('%04X', $gid);
                if (is_int($uni)) {
                    if ($uni > 0xFFFF) {
                        $high = 0xD800 + (($uni - 0x10000) >> 10);
                        $low = 0xDC00 + (($uni - 0x10000) & 0x3FF);
                        $uniHex = sprintf('%04X%04X', $high, $low);
                    } else {
                        $uniHex = sprintf('%04X', $uni);
                    }
                } else {
                    $uniHex = $uni;
                }
                $stream .= "<$gidHex> <$uniHex>\n";
            }
            $stream .= "endbfchar\n";
        }

        $stream .= "endcmap\n"
            . "CMapName currentdict /CMap defineresource pop\n"
            . "end\n"
            . "end\n";

        return $stream;
    }
}
