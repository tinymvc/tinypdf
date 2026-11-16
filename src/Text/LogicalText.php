<?php

declare(strict_types=1);

namespace Spark\Pdf\Text;

use Spark\Pdf\Font\ToUnicodeCMap;

/** Keeps a shaped line's vector appearance separate from its logical text order. */
final class LogicalText
{
    private array $fonts = [];

    public function __construct(private NativeHelper $helper)
    {
    }

    public function wrap(string $text, string $appearance, float $x, float $y, float $width,
        float $ascent, float $descent): string
    {
        if ($text === '' || !preg_match('/[^\x00-\x7F]/', $text)) {
            return $appearance;
        }
        // PDFium drops supplementary characters from ActualText. Give those
        // characters ordinary ToUnicode mappings, retaining joiners/selectors.
        preg_match_all('/[^\x{FFFD}-\x{10FFFF}]+|[\x{FFFD}-\x{10FFFF}][\x{FE0F}\x{200D}\x{FFFD}-\x{10FFFF}]*/u', $text, $matches);
        $segments = [];
        foreach ($matches[0] as $part) {
            $supplementary = (bool) preg_match('/[\x{FFFD}-\x{10FFFF}]/u', $part);
            foreach ($supplementary ? mb_str_split($part, 24, 'UTF-8') : [$part] as $chunk) {
                $segments[] = [$chunk, $supplementary];
            }
        }
        $rtl = (bool) preg_match('/[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}\p{Nko}\p{Adlam}]/u', $text);
        $advance = max(.001, $width / max(1, mb_strlen($text, 'UTF-8')));
        $offset = 0.0;
        $out = "q\n";
        foreach ($segments as [$part, $supplementary]) {
            $serial = count($this->fonts) + 1;
            $name = 'L' . $serial;
            // PDFium compares character codes when suppressing duplicate text;
            // distinct nearby line codes prevent adjacent rows being mistaken.
            $first = ($serial % 7) * 32 + 1;
            $characters = mb_str_split($part, 1, 'UTF-8');
            $mapping = [];
            if ($supplementary) {
                foreach ($characters as $index => $character) {
                    if ($rtl && $index === 0) $character = "\u{200E}" . $character;
                    if ($rtl && $index === count($characters) - 1) $character .= "\u{200E}";
                    $mapping[$first + $index] = self::hex($character);
                }
                $codes = range($first, $first + count($characters) - 1);
                $actualText = null;
            } else {
                // A composite glyph is replaced by the full logical string.
                // Neutral placeholders prevent a second bidi pass in MuPDF.
                $mapping = [$first => 'FFFC', $first + 1 => 'FFFC'];
                $codes = array_fill(0, count($characters), $first + 1);
                $codes[0] = $first;
                $actualText = $part;
                if ($rtl) {
                    $actualText = preg_replace('/[ -~]*[!-~][ -~]*/u', "\u{200E}$0\u{200E}", $actualText);
                }
            }
            $gx = $x + $offset;
            $gy = $y;
            $paint = '';
            $this->fonts[$name] = compact('first', 'mapping', 'actualText', 'advance', 'ascent', 'descent', 'paint', 'width');
            $out .= 'BT /' . $name . ' 1 Tf 0 Tr 0 Tc 0 Tw ' . '1 0 0 1 '
                . self::numbers([$gx, $gy]) . " Tm\n";
            if ($actualText !== null) $out .= "/Span /A$name BDC\n";
            $out .= '<' . implode('', array_map(static fn($code) => sprintf('%02X', $code), $codes)) . "> Tj\n";
            if ($actualText !== null) $out .= "EMC\n";
            $out .= "ET\n";
            $offset += count($characters) * $advance;
        }
        return $out . "Q\n" . $this->compatibilityText($text, $appearance, $x, $y, $width, $ascent, $descent, $rtl);
    }

    private function compatibilityText(string $text, string $appearance, float $x, float $y,
        float $width, float $ascent, float $descent, bool $rtl): string
    {
        // PDFKit ignores replacement text on ink-free Type3 fonts. Supply real
        // Unicode on the visible font, while other readers use the logical layer.
        // A leading .notdef consumes PDFKit's first-object ActualText handling.
        if ($rtl) {
            $text = preg_replace_callback('/[\p{Arabic}\p{Hebrew}\p{M}]+/u',
                static fn($match) => $match[0] . (preg_match('/\p{M}$/u', $match[0]) ? "\u{200F}" : ''), $text);
            $text = preg_replace('/[ -~\p{N}\x{066B}\x{066C}]*[!-~\p{N}\x{066B}\x{066C}][ -~\p{N}\x{066B}\x{066C}]*/u', "\u{200E}$0\u{200E}", $text);
            $analysis = $this->helper->request('analyze', ['text' => $text, 'baseLevel' => 0]);
            $characters = mb_str_split($text, 1, 'UTF-8');
            $order = array_unique(array_map(static fn($index) => $analysis['utf16ToCharacter'][$index], $analysis['order']));
            $characters = array_map(static fn($index) => $characters[$index], $order);
        } else {
            $characters = mb_str_split($text, 1, 'UTF-8');
        }
        $characters = ["\u{200E}", ...$characters, "\u{200E}"];
        $advance = max(.001, $width / count($characters));
        $out = '';
        $offset = 0.0;
        $chunks = [];
        $mapping = [0 => '0020'];
        $lookup = [];
        $codes = [];
        foreach ($characters as $character) {
            if (count($mapping) === 256 && !isset($lookup[$character])) {
                $chunks[] = [$mapping, $codes];
                $mapping = [0 => '0020'];
                $lookup = [];
                $codes = [];
            }
            $code = $lookup[$character] ?? count($mapping);
            if (!isset($mapping[$code])) {
                $mapping[$code] = self::hex($character);
                // Code 1 paints the line and must occur only once.
                if ($code !== 1) $lookup[$character] = $code;
            }
            $codes[] = $code;
        }
        $chunks[] = [$mapping, $codes];
        foreach ($chunks as $chunkIndex => [$mapping, $codes]) {
            $name = 'L' . (count($this->fonts) + 1);
            $first = 0;
            $paint = 'q ' . self::numbers([1, 0, 0, 1, -$x - $offset, -$y]) . " cm\n";
            if (count($chunks) > 1) {
                // Partition the vector appearance between continuation fonts.
                // Each font has real ink, and no visible region is painted twice.
                $left = $chunkIndex === 0 ? -1000000 : $x + $offset;
                $right = $chunkIndex === count($chunks) - 1 ? 1000000 : $x + $offset + count($codes) * $advance;
                $paint .= self::numbers([$left, -1000000, $right - $left, 2000000]) . " re W n\n";
            }
            $paint .= $appearance . "Q\n";
            $actualText = null;
            $this->fonts[$name] = compact('first', 'mapping', 'actualText', 'advance', 'ascent', 'descent', 'paint', 'width')
                + ['compatibility' => true];
            $count = count($codes);
            $codes = implode('', array_map(static fn($code) => sprintf('%02X', $code), $codes));
            $out .= "q /Span << /ActualText ( ) >> BDC BT /$name 1 Tf 0 Tr 0 Tc 0 Tw 1 0 0 1 "
                . self::numbers([$x + $offset, $y]) . " Tm <00> Tj\n<$codes> Tj ET EMC Q\n";
            $offset += $count * $advance;
        }
        return $out;
    }

    public function buildResources(array &$objects, int &$id, string &$fonts, string $images, string $states): string
    {
        if (!$this->fonts) return '';
        $resources = ++$id;
        $properties = '';
        $programs = [];
        foreach ($this->fonts as $name => $font) {
            $procs = [];
            $encoding = [];
            $widths = [];
            foreach ($font['mapping'] as $code => $unicode) {
                $dummy = ($font['compatibility'] ?? false) && $code === 0;
                $advance = $dummy ? 0 : $font['advance'];
                $metrics = self::numbers([$advance, 0]);
                if ($code === 1 && $font['paint'] !== '') {
                    $stream = $metrics . " d0\n" . $font['paint'];
                } else {
                    $stream = $metrics . ' ' . self::numbers([0, -$font['descent'], $font['advance'], $font['ascent']]) . " d1\n";
                }
                $key = ($code === 1 && $font['paint'] !== '' ? $name : '') . hash('sha256', $stream);
                if (!isset($programs[$key])) {
                    $objects[++$id] = self::stream($stream);
                    $programs[$key] = $id;
                }
                $program = $programs[$key];
                $glyph = $dummy ? '.notdef' : "g$code";
                $procs[] = "/$glyph $program 0 R";
                $encoding[] = "/$glyph";
                $widths[] = $advance;
            }
            $objects[++$id] = self::stream(ToUnicodeCMap::build(array_filter($font['mapping'], static fn($value) => $value !== ''), 1));
            $cmap = $id;
            if ($font['actualText'] !== null) {
                $objects[++$id] = '<< /ActualText <FEFF' . self::hex($font['actualText']) . '> >>';
                $properties .= "/A$name $id 0 R ";
            }
            $bbox = self::numbers([0, -$font['descent'], $font['width'], $font['ascent']]);
            $first = $font['first'];
            $last = $first + count($widths) - 1;
            $objects[++$id] = "<< /Type /Font /Subtype /Type3 /FontBBox [$bbox] /FontMatrix [1 0 0 1 0 0]"
                . ' /CharProcs << ' . implode(' ', $procs) . ' >>'
                . " /Encoding << /Type /Encoding /Differences [$first " . implode(' ', $encoding) . '] >>'
                . " /FirstChar $first /LastChar $last /Widths [" . self::numbers($widths) . ']'
                . " /ToUnicode $cmap 0 R /Resources $resources 0 R >>";
            $fonts .= "/$name $id 0 R ";
        }
        $properties = "/Properties << $properties >>";
        $objects[$resources] = "<< /Font << $fonts >> /XObject << $images >> $states $properties >>";
        return $properties;
    }

    private static function stream(string $data): string
    {
        $compressed = gzcompress($data, 6);
        return '<< /Filter /FlateDecode /Length ' . strlen($compressed) . ">>\nstream\n$compressed\nendstream";
    }

    private static function hex(string $text): string
    {
        return strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')));
    }

    private static function numbers(array $numbers): string
    {
        return implode(' ', array_map(static fn($number) => sprintf('%.5F', $number), $numbers));
    }
}
