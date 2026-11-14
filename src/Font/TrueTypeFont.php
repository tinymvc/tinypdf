<?php

declare(strict_types=1);

namespace Spark\Pdf\Font;

use RuntimeException;
use function count;
use function in_array;
use function sprintf;
use function strlen;

/**
 * TrueType-outline font parser and PDF CIDFontType2 / Type0 embedder.
 * Supports .ttf, .otf, and .ttc (TrueType Collection) fonts for global language coverage
 * (Bangla, Chinese, Japanese, Korean, Arabic, Cyrillic, Latin-Extended).
 */
final class TrueTypeFont implements FontInterface
{
    private string $data;
    private int $baseOffset = 0;
    private string $postScriptName;
    private int $unitsPerEm;
    private int $ascender;
    private int $descender;
    private int $lineGap;
    private array $bbox;
    private int $numGlyphs;
    private int $numberOfHMetrics;

    /** @var list<int> glyphId => advanceWidth in font units */
    private array $advanceWidths = [];

    /** @var array<int, int> unicode codepoint => glyphId */
    private array $cmap = [];

    /** @var array<int, list<array{comps: list<int>, lig: int}>> firstGlyphId => list of ligatures */
    private array $ligatures = [];

    /** @var array<int, bool> glyphIds used in this document */
    private array $usedGlyphs = [0 => true];

    /** @var array<int, int|string> glyphId => unicode codepoint or UTF-16BE hex */
    private array $glyphToUnicode = [];
    private array $shapedText = [];

    public function __construct(
        private readonly string $fontPath,
        public readonly bool $bold = false,
        public readonly bool $italic = false,
    ) {
        $content = @file_get_contents($fontPath);
        if ($content === false || $content === '') {
            throw new RuntimeException("Cannot read font file: $fontPath");
        }
        $this->data = $content;
        $this->parse();
    }

    private function parse(): void
    {
        $magic = substr($this->data, 0, 4);

        // Decompress WOFF 1.0 (wOFF) into standard sfnt font binary
        if ($magic === 'wOFF') {
            $this->data = $this->unpackWoff($this->data);
            $magic = substr($this->data, 0, 4);
        }

        // Extract standalone sfnt TrueType/OpenType from TTC collection (reduces memory & PDF size)
        if ($magic === 'ttcf') {
            $this->data = $this->extractTtcFont($this->data, 0);
            $magic = substr($this->data, 0, 4);
        }

        $this->baseOffset = 0;
        if ($magic !== "\0\1\0\0" && $magic !== 'true') {
            throw new RuntimeException('Unsupported font outlines: use a TrueType font (TTF, TrueType WOFF or TTC), not CFF/WOFF2.');
        }

        $numTables = $this->readUint16($this->baseOffset + 4);
        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $offset = $this->baseOffset + 12 + $i * 16;
            $tag = substr($this->data, $offset, 4);
            $to = $this->readUint32($offset + 8);
            $tl = $this->readUint32($offset + 12);
            $this->checkBounds($to, $tl);
            $tables[$tag] = ['offset' => $to, 'length' => $tl];
        }

        // 1. head table
        if (!isset($tables['head'])) {
            throw new RuntimeException("Font missing 'head' table: {$this->fontPath}");
        }
        $headOff = $tables['head']['offset'];
        $this->unitsPerEm = $this->readUint16($headOff + 18);
        if ($this->unitsPerEm < 16 || $this->unitsPerEm > 16384) {
            throw new RuntimeException('Invalid font unitsPerEm.');
        }
        $xMin = $this->readInt16($headOff + 36);
        $yMin = $this->readInt16($headOff + 38);
        $xMax = $this->readInt16($headOff + 40);
        $yMax = $this->readInt16($headOff + 42);
        $this->bbox = [$xMin, $yMin, $xMax, $yMax];

        // 2. hhea table
        if (!isset($tables['hhea'])) {
            throw new RuntimeException("Font missing 'hhea' table: {$this->fontPath}");
        }
        $hheaOff = $tables['hhea']['offset'];
        $this->ascender = $this->readInt16($hheaOff + 4);
        $this->descender = $this->readInt16($hheaOff + 6);
        $this->lineGap = $this->readInt16($hheaOff + 8);
        $this->numberOfHMetrics = $this->readUint16($hheaOff + 34);

        // 3. maxp table
        $maxpOff = $tables['maxp']['offset'] ?? throw new RuntimeException("Missing 'maxp' table");
        $this->numGlyphs = $this->readUint16($maxpOff + 4);
        if ($this->numberOfHMetrics < 1 || $this->numberOfHMetrics > $this->numGlyphs) {
            throw new RuntimeException('Invalid font horizontal metrics.');
        }

        // 4. hmtx table (horizontal metrics)
        $hmtxOff = $tables['hmtx']['offset'] ?? throw new RuntimeException("Missing 'hmtx' table");
        $lastWidth = 0;
        for ($g = 0; $g < $this->numberOfHMetrics; $g++) {
            $lastWidth = $this->readUint16($hmtxOff + $g * 4);
            $this->advanceWidths[$g] = $lastWidth;
        }
        for ($g = $this->numberOfHMetrics; $g < $this->numGlyphs; $g++) {
            $this->advanceWidths[$g] = $lastWidth;
        }

        // 5. name table (PostScript Name)
        $this->postScriptName = $this->parsePostScriptName($tables['name']['offset'] ?? null);

        // 6. cmap table (Character to Glyph mapping)
        $this->parseCmap($tables['cmap']['offset'] ?? null);

        // 7. GSUB table (Ligatures)
        if (isset($tables['GSUB'])) {
            $this->parseGsub($tables['GSUB']['offset']);
        }
    }

    private function parsePostScriptName(?int $offset): string
    {
        if ($offset === null) {
            return basename($this->fontPath, '.ttf');
        }
        $count = $this->readUint16($offset + 2);
        $stringOffset = $offset + $this->readUint16($offset + 4);
        for ($i = 0; $i < $count; $i++) {
            $record = $offset + 6 + $i * 12;
            $nameId = $this->readUint16($record + 6);
            if ($nameId === 6) { // PostScript name
                $length = $this->readUint16($record + 8);
                $strOff = $stringOffset + $this->readUint16($record + 10);
                $str = substr($this->data, $strOff, $length);
                $clean = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace("\0", '', $str));
                if ($clean !== '') {
                    return $clean;
                }
            }
        }
        return preg_replace('/[^A-Za-z0-9_-]/', '', pathinfo($this->fontPath, PATHINFO_FILENAME));
    }

    private function parseCmap(?int $cmapOffset): void
    {
        if ($cmapOffset === null) {
            return;
        }
        $numSubtables = $this->readUint16($cmapOffset + 2);
        $fmt4Offset = null;
        $fmt12Offset = null;

        for ($i = 0; $i < $numSubtables; $i++) {
            $pId = $this->readUint16($cmapOffset + 4 + $i * 8);
            $eId = $this->readUint16($cmapOffset + 6 + $i * 8);
            $subOff = $this->readUint32($cmapOffset + 8 + $i * 8);
            $fmt = $this->readUint16($cmapOffset + $subOff);

            if (($pId === 3 && $eId === 10) || ($pId === 0 && $eId === 4)) {
                if ($fmt === 12) {
                    $fmt12Offset = $cmapOffset + $subOff;
                }
            }
            if (($pId === 3 && $eId === 1) || ($pId === 0 && $eId === 3)) {
                if ($fmt === 4) {
                    $fmt4Offset = $cmapOffset + $subOff;
                }
            }
        }

        if ($fmt12Offset !== null) {
            $this->parseCmapFormat12($fmt12Offset);
        } elseif ($fmt4Offset !== null) {
            $this->parseCmapFormat4($fmt4Offset);
        }
    }

    private function parseCmapFormat4(int $subOff): void
    {
        $segCount = (int) ($this->readUint16($subOff + 6) / 2);
        $endCodeOffset = $subOff + 14;
        $startCodeOffset = $endCodeOffset + 2 + $segCount * 2;
        $idDeltaOffset = $startCodeOffset + $segCount * 2;
        $idRangeOffset = $idDeltaOffset + $segCount * 2;

        for ($s = 0; $s < $segCount; $s++) {
            $start = $this->readUint16($startCodeOffset + $s * 2);
            $end = $this->readUint16($endCodeOffset + $s * 2);
            $delta = $this->readInt16($idDeltaOffset + $s * 2);
            $ro = $this->readUint16($idRangeOffset + $s * 2);

            for ($c = $start; $c <= $end; $c++) {
                if ($c === 0xFFFF)
                    break;
                if ($ro === 0) {
                    $gid = ($c + $delta) & 0xFFFF;
                } else {
                    $glyphOff = $idRangeOffset + $s * 2 + $ro + ($c - $start) * 2;
                    $gid = $this->readUint16($glyphOff);
                    if ($gid !== 0) {
                        $gid = ($gid + $delta) & 0xFFFF;
                    }
                }
                if ($gid > 0 && $gid < $this->numGlyphs) {
                    $this->cmap[$c] = $gid;
                }
            }
        }
    }

    private function parseCmapFormat12(int $subOff): void
    {
        $numGroups = $this->readUint32($subOff + 12);
        for ($i = 0; $i < $numGroups; $i++) {
            $startCharCode = $this->readUint32($subOff + 16 + $i * 12);
            $endCharCode = $this->readUint32($subOff + 20 + $i * 12);
            $startGlyphId = $this->readUint32($subOff + 24 + $i * 12);
            if ($startCharCode > $endCharCode || $endCharCode > 0x10FFFF) {
                throw new RuntimeException('Invalid Unicode cmap range.');
            }

            for ($c = $startCharCode; $c <= $endCharCode; $c++) {
                $gid = (int) ($startGlyphId + ($c - $startCharCode));
                if ($gid > 0 && $gid < $this->numGlyphs) {
                    $this->cmap[$c] = $gid;
                }
            }
        }
    }

    private function parseGsub(int $gsubOffset): void
    {
        $featuresOffset = $gsubOffset + $this->readUint16($gsubOffset + 6);
        $featureCount = $this->readUint16($featuresOffset);
        $enabledLookups = [];
        $defaultFeatures = ['ccmp', 'liga', 'rlig', 'akhn', 'rphf', 'pref', 'blwf', 'half', 'pstf', 'vatu', 'cjct', 'pres', 'abvs', 'blws', 'psts'];
        for ($i = 0; $i < $featureCount; $i++) {
            $record = $featuresOffset + 2 + $i * 6;
            $tag = substr($this->data, $record, 4);
            if (!in_array($tag, $defaultFeatures, true))
                continue;
            $feature = $featuresOffset + $this->readUint16($record + 4);
            $count = $this->readUint16($feature + 2);
            for ($j = 0; $j < $count; $j++) {
                $enabledLookups[$this->readUint16($feature + 4 + $j * 2)] = true;
            }
        }
        $lookupListOffset = $gsubOffset + $this->readUint16($gsubOffset + 8);
        $lookupCount = $this->readUint16($lookupListOffset);

        for ($i = 0; $i < $lookupCount; $i++) {
            if (!isset($enabledLookups[$i]))
                continue;
            $lookupOff = $lookupListOffset + $this->readUint16($lookupListOffset + 2 + $i * 2);
            $type = $this->readUint16($lookupOff);
            $subCount = $this->readUint16($lookupOff + 4);

            if ($type === 4) { // Direct Ligature Substitution
                for ($s = 0; $s < $subCount; $s++) {
                    $subOff = $lookupOff + $this->readUint16($lookupOff + 6 + $s * 2);
                    $this->parseLigatureSubtable($subOff);
                }
            } elseif ($type === 7) { // Extension Substitution (wraps lookups in modern fonts)
                for ($s = 0; $s < $subCount; $s++) {
                    $subOff = $lookupOff + $this->readUint16($lookupOff + 6 + $s * 2);
                    $extType = $this->readUint16($subOff + 2);
                    if ($extType === 4) {
                        $extOffset = $this->readUint32($subOff + 4);
                        $this->parseLigatureSubtable($subOff + $extOffset);
                    }
                }
            }
        }
    }

    private function parseLigatureSubtable(int $subOff): void
    {
        $covOff = $subOff + $this->readUint16($subOff + 2);
        $ligSetCount = $this->readUint16($subOff + 4);
        $firstGlyphs = $this->parseCoverage($covOff);

        for ($i = 0; $i < $ligSetCount; $i++) {
            $firstGid = $firstGlyphs[$i] ?? null;
            if ($firstGid === null)
                continue;

            $ligSetOff = $subOff + $this->readUint16($subOff + 6 + $i * 2);
            $ligCount = $this->readUint16($ligSetOff);

            for ($l = 0; $l < $ligCount; $l++) {
                $ligOff = $ligSetOff + $this->readUint16($ligSetOff + 2 + $l * 2);
                $ligGid = $this->readUint16($ligOff);
                $compCount = $this->readUint16($ligOff + 2);
                $comps = [];
                for ($c = 0; $c < $compCount - 1; $c++) {
                    $comps[] = $this->readUint16($ligOff + 4 + $c * 2);
                }
                $this->ligatures[$firstGid][] = ['comps' => $comps, 'lig' => $ligGid];
            }

            // Sort ligatures by number of components descending (longest match first)
            if (isset($this->ligatures[$firstGid])) {
                usort($this->ligatures[$firstGid], static fn($a, $b) => count($b['comps']) <=> count($a['comps']));
            }
        }
    }

    private function parseCoverage(int $covOff): array
    {
        $fmt = $this->readUint16($covOff);
        $glyphs = [];
        if ($fmt === 1) {
            $count = $this->readUint16($covOff + 2);
            for ($i = 0; $i < $count; $i++) {
                $glyphs[] = $this->readUint16($covOff + 4 + $i * 2);
            }
        } elseif ($fmt === 2) {
            $rangeCount = $this->readUint16($covOff + 2);
            for ($i = 0; $i < $rangeCount; $i++) {
                $start = $this->readUint16($covOff + 4 + $i * 6);
                $end = $this->readUint16($covOff + 4 + $i * 6 + 2);
                for ($g = $start; $g <= $end; $g++) {
                    $glyphs[] = $g;
                }
            }
        }
        return $glyphs;
    }

    public function shapeText(string $text): array
    {
        if (isset($this->shapedText[$text])) {
            return $this->shapedText[$text];
        }
        if (BengaliShaper::containsBengali($text) || BengaliShaper::containsDevanagari($text)) {
            $charList = BengaliShaper::shape($text);
        } elseif (ArabicShaper::containsArabic($text)) {
            $charList = mb_str_split(ArabicShaper::shape($text));
        } else {
            $charList = mb_str_split($text);
        }

        $gids = [];
        $origUnicodes = [];
        foreach ($charList as $char) {
            $code = mb_ord($char);
            $gid = $this->cmap[$code] ?? 0;
            $gids[] = $gid;
            $origUnicodes[] = $code;
            if ($gid > 0) {
                $this->glyphToUnicode[$gid] = $code;
            }
        }

        if (!empty($this->ligatures)) {
            $gids = $this->applyLigatures($gids, $origUnicodes);
        }

        foreach ($gids as $gid) {
            $this->usedGlyphs[$gid] = true;
        }
        if (count($this->shapedText) < 2048) {
            $this->shapedText[$text] = $gids;
        }

        return $gids;
    }

    private function applyLigatures(array $gids, array $unicodes): array
    {
        $out = [];
        $n = count($gids);
        $i = 0;

        while ($i < $n) {
            $first = $gids[$i];
            if (isset($this->ligatures[$first])) {
                $matched = false;
                foreach ($this->ligatures[$first] as $rule) {
                    $compCount = count($rule['comps']);
                    if ($i + $compCount >= $n) {
                        continue;
                    }

                    $allMatch = true;
                    for ($c = 0; $c < $compCount; $c++) {
                        if ($gids[$i + 1 + $c] !== $rule['comps'][$c]) {
                            $allMatch = false;
                            break;
                        }
                    }

                    if ($allMatch) {
                        $out[] = $rule['lig'];
                        $uniHex = '';
                        for ($u = 0; $u <= $compCount; $u++) {
                            $uniHex .= strtoupper(bin2hex(mb_convert_encoding(mb_chr($unicodes[$i + $u] ?? 0), 'UTF-16BE', 'UTF-8')));
                        }
                        $this->glyphToUnicode[$rule['lig']] = $uniHex;

                        $i += 1 + $compCount;
                        $matched = true;
                        break;
                    }
                }
                if ($matched) {
                    continue;
                }
            }

            $out[] = $first;
            $i++;
        }

        return $out;
    }

    public function getName(): string
    {
        return $this->postScriptName;
    }

    public function getAscender(float $size): float
    {
        return ($this->ascender / $this->unitsPerEm) * $size;
    }

    public function getDescender(float $size): float
    {
        return ($this->descender / $this->unitsPerEm) * $size;
    }

    public function getLineHeight(float $size): float
    {
        $gap = ($this->ascender - $this->descender + $this->lineGap) / $this->unitsPerEm;
        return max(1.2, $gap) * $size;
    }

    public function getTextWidth(string $text, float $size): float
    {
        $gids = $this->shapeText($text);
        $totalUnits = 0;
        foreach ($gids as $gid) {
            $totalUnits += $this->advanceWidths[$gid] ?? 0;
        }
        return ($totalUnits / $this->unitsPerEm) * $size;
    }

    public function supportsText(string $text): bool
    {
        if ($text === '') {
            return true;
        }
        foreach (mb_str_split($text) as $char) {
            $cp = mb_ord($char);
            if ($cp <= 32) {
                continue;
            }
            if (!isset($this->cmap[$cp]) || $this->cmap[$cp] === 0) {
                return false;
            }
        }
        return true;
    }

    public function encode(string $text): string
    {
        $gids = $this->shapeText($text);
        $hex = '';
        foreach ($gids as $gid) {
            $hex .= sprintf('%04X', $gid);
        }
        return "<$hex>";
    }

    public function getPdfFontObjects(int &$nextId, string $resName): array
    {
        $objects = [];

        $fontFileId = ++$nextId;
        $compressedFont = gzcompress($this->data, 6);
        $objects[$fontFileId] = sprintf(
            "<< /Length %d /Length1 %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
            strlen($compressedFont),
            strlen($this->data),
            $compressedFont
        );
        $fontFileRef = sprintf('/FontFile2 %d 0 R', $fontFileId);

        // 2. Font descriptor
        $descId = ++$nextId;
        $scale = 1000 / $this->unitsPerEm;
        $ascent = (int) round($this->ascender * $scale);
        $descent = (int) round($this->descender * $scale);
        $bbox = [
            (int) round($this->bbox[0] * $scale),
            (int) round($this->bbox[1] * $scale),
            (int) round($this->bbox[2] * $scale),
            (int) round($this->bbox[3] * $scale),
        ];
        $flags = 4;
        if ($this->italic)
            $flags |= 64;

        $objects[$descId] = sprintf(
            "<< /Type /FontDescriptor /FontName /%s /Flags %d /FontBBox [%s] /Ascent %d /Descent %d /CapHeight %d /StemV 80 %s >>",
            $this->postScriptName,
            $flags,
            implode(' ', $bbox),
            $ascent,
            $descent,
            $ascent,
            $fontFileRef
        );

        // 3. Compact Widths table (/W) grouped by contiguous used glyph runs
        $used = array_keys($this->usedGlyphs);
        sort($used);
        $wRuns = [];
        $runStart = null;
        $runWidths = [];

        foreach ($used as $gid) {
            $w = (int) round(($this->advanceWidths[$gid] ?? 0) * $scale);
            if ($runStart === null) {
                $runStart = $gid;
                $runWidths = [$w];
            } elseif ($gid === $runStart + count($runWidths)) {
                $runWidths[] = $w;
            } else {
                $wRuns[] = sprintf('%d [ %s]', $runStart, implode(' ', $runWidths));
                $runStart = $gid;
                $runWidths = [$w];
            }
        }
        if ($runStart !== null) {
            $wRuns[] = sprintf('%d [ %s]', $runStart, implode(' ', $runWidths));
        }
        $widthsTable = '[ ' . implode(' ', $wRuns) . ' ]';

        // 4. Descendant Font (CIDFontType2)
        $cidFontId = ++$nextId;
        $objects[$cidFontId] = sprintf(
            "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW 1000 /W %s /CIDToGIDMap /Identity >>",
            $this->postScriptName,
            $descId,
            $widthsTable
        );

        // 5. ToUnicode CMap
        $toUnicodeId = ++$nextId;
        $cMapData = ToUnicodeCMap::build($this->glyphToUnicode);
        $compressedCMap = gzcompress($cMapData, 6);
        $objects[$toUnicodeId] = sprintf(
            "<< /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
            strlen($compressedCMap),
            $compressedCMap
        );

        // 6. Type0 Composite Font
        $type0Id = ++$nextId;
        $objects[$type0Id] = sprintf(
            "<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>",
            $this->postScriptName,
            $cidFontId,
            $toUnicodeId
        );

        return $objects;
    }

    private function unpackWoff(string $woff): string
    {
        $this->checkBounds(0, 44);
        $flavor = substr($woff, 4, 4);
        $numTables = $this->readUint16(12);
        $this->checkBounds(44, $numTables * 20);
        $header = $flavor . pack('nnnn', $numTables, 0, 0, 0);
        $tableRecords = '';
        $tableData = '';
        $offset = 12 + $numTables * 16;

        for ($i = 0; $i < $numTables; $i++) {
            $recOff = 44 + $i * 20;
            $tag = substr($woff, $recOff, 4);
            $to = unpack('N', substr($woff, $recOff + 4, 4))[1];
            $compLen = unpack('N', substr($woff, $recOff + 8, 4))[1];
            $origLen = unpack('N', substr($woff, $recOff + 12, 4))[1];
            $origSum = unpack('N', substr($woff, $recOff + 16, 4))[1];
            $this->checkBounds($to, $compLen);
            if ($origLen > 64 * 1024 * 1024 || $compLen > $origLen) {
                throw new RuntimeException('Invalid WOFF table length.');
            }

            $chunk = substr($woff, $to, $compLen);
            $decompressed = $compLen < $origLen ? @gzuncompress($chunk, $origLen) : $chunk;
            if ($decompressed === false || strlen($decompressed) !== $origLen) {
                throw new RuntimeException("Corrupted WOFF font table: $tag");
            }

            $padLen = (4 - (strlen($decompressed) % 4)) % 4;
            $padded = $decompressed . str_repeat("\0", $padLen);

            $tableRecords .= $tag . pack('NNN', $origSum, $offset, $origLen);
            $tableData .= $padded;
            $offset += strlen($padded);
        }

        return $header . $tableRecords . $tableData;
    }

    private function extractTtcFont(string $ttc, int $fontIndex = 0): string
    {
        $numFonts = $this->readUint32(8);
        if ($numFonts < 1) {
            throw new RuntimeException('Empty TrueType collection.');
        }
        if ($fontIndex >= $numFonts) {
            $fontIndex = 0;
        }
        $fontOffset = $this->readUint32(12 + $fontIndex * 4);
        $sfntVer = substr($ttc, $fontOffset, 4);
        $numTables = $this->readUint16($fontOffset + 4);
        $this->checkBounds($fontOffset, 12 + $numTables * 16);
        $header = $sfntVer . substr($ttc, $fontOffset + 4, 8);

        $tableRecords = '';
        $tableData = '';
        $offset = 12 + $numTables * 16;

        for ($i = 0; $i < $numTables; $i++) {
            $recOff = $fontOffset + 12 + $i * 16;
            $tag = substr($ttc, $recOff, 4);
            $checksum = substr($ttc, $recOff + 4, 4);
            $to = unpack('N', substr($ttc, $recOff + 8, 4))[1];
            $tl = unpack('N', substr($ttc, $recOff + 12, 4))[1];
            $this->checkBounds($to, $tl);

            $chunk = substr($ttc, $to, $tl);
            $padLen = (4 - (strlen($chunk) % 4)) % 4;
            $padded = $chunk . str_repeat("\0", $padLen);

            $tableRecords .= $tag . $checksum . pack('NN', $offset, $tl);
            $tableData .= $padded;
            $offset += strlen($padded);
        }

        return "$header$tableRecords$tableData";
    }

    private function readUint16(int $offset): int
    {
        $this->checkBounds($offset, 2);
        return unpack('n', substr($this->data, $offset, 2))[1];
    }

    private function readInt16(int $offset): int
    {
        $v = $this->readUint16($offset);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function readUint32(int $offset): int
    {
        $this->checkBounds($offset, 4);
        return unpack('N', substr($this->data, $offset, 4))[1];
    }

    private function checkBounds(int $offset, int $length): void
    {
        if ($offset < 0 || $length < 0 || $offset > strlen($this->data) - $length) {
            throw new RuntimeException('Truncated or invalid font table.');
        }
    }
}
