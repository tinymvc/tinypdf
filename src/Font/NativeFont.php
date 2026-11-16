<?php

declare(strict_types=1);

namespace Spark\Pdf\Font;

use Spark\Pdf\Text\NativeHelper;

final class NativeFont implements FontInterface
{
    private array $cids = [];
    private array $glyphs = [];
    private array $unicode = [];

    public function __construct(private NativeHelper $helper, public readonly array $metadata)
    {
    }

    public function id(): int { return $this->metadata['font']; }
    public function getName(): string { return $this->metadata['name']; }
    public function getAscender(float $size): float { return $this->metadata['ascent'] * $size / 1000; }
    public function getDescender(float $size): float { return $this->metadata['descent'] * $size / 1000; }
    public function getLineHeight(float $size): float
    {
        return ($this->metadata['ascent'] - $this->metadata['descent'] + $this->metadata['lineGap']) * $size / 1000;
    }

    private function shape(string $text, float $size): array
    {
        return $this->helper->request('layout', ['text' => $text, 'width' => 1e9, 'spans' => [
            ['start' => 0, 'end' => strlen($text), 'item' => 0, 'style' => ['fonts' => [$this->id()], 'size' => $size]],
        ]])['lines'];
    }

    public function getTextWidth(string $text, float $size): float
    {
        return array_sum(array_column($this->shape($text, $size), 'width'));
    }

    public function supportsText(string $text): bool
    {
        try {
            $this->shape($text, 10);
            return true;
        } catch (\RuntimeException $error) {
            if (str_contains($error->getMessage(), 'No registered font covers')) return false;
            throw $error;
        }
    }

    public function encode(string $text): string
    {
        throw new \LogicException('Native fonts require positioned glyph runs, not string encoding.');
    }

    public function cid(int $glyph, string $source): string
    {
        $key = $glyph . ':' . $source;
        if (!isset($this->cids[$key])) {
            $cid = count($this->glyphs) + 1;
            if ($cid >= 65535) throw new \RuntimeException('PDF font exceeds 65534 CIDs.');
            $this->cids[$key] = $cid;
            $this->glyphs[] = ['cid' => $cid, 'gid' => $glyph];
            if ($source !== '') $this->unicode[$cid] = strtoupper(bin2hex(mb_convert_encoding($source, 'UTF-16BE', 'UTF-8')));
        }
        return sprintf('<%04X>', $this->cids[$key]);
    }

    public function getPdfFontObjects(int &$nextId, string $resName): array
    {
        $subset = $this->helper->request('subset', ['font' => $this->id(), 'glyphs' => $this->glyphs]);
        $data = base64_decode($subset['data'], true);
        if ($data === false || $data === '') throw new \RuntimeException('Invalid native font subset.');
        $truetype = $subset['format'] === 'truetype';
        $hash = hash('sha256', $data);
        $prefix = '';
        for ($i = 0; $i < 6; $i++) $prefix .= chr(65 + hexdec(substr($hash, $i * 2, 2)) % 26);
        $name = $prefix . '+' . (preg_replace('/[^a-zA-Z0-9_-]/', '', $this->getName()) ?: 'TinyPDFFont');
        $objects = [];
        $stream = static function (string $bytes, string $dict = ''): string {
            $compressed = gzcompress($bytes);
            return '<< /Length ' . strlen($compressed) . " /Filter /FlateDecode $dict >>\nstream\n$compressed\nendstream";
        };
        $fontId = ++$nextId;
        $objects[$fontId] = $stream($data, $truetype ? '/Length1 ' . strlen($data) : '/Subtype /CIDFontType0C');
        $cmapId = ++$nextId;
        $objects[$cmapId] = $stream(ToUnicodeCMap::build($this->unicode));
        $map = "\0\0";
        $widths = [];
        foreach ($this->glyphs as $glyph) {
            $map .= pack('n', $truetype ? $subset['mapping'][$glyph['gid']] : $glyph['cid']);
            $widths[] = sprintf('%.3F', $subset['widths'][$glyph['gid']]);
        }
        $mapEntry = '';
        if ($truetype) {
            $mapId = ++$nextId;
            $objects[$mapId] = $stream($map);
            $mapEntry = "/CIDToGIDMap $mapId 0 R";
        }
        $descriptor = ++$nextId;
        $box = implode(' ', array_map(static fn($v) => (string) round($v), $this->metadata['bbox']));
        $fontFile = $truetype ? 'FontFile2' : 'FontFile3';
        $objects[$descriptor] = sprintf('<< /Type /FontDescriptor /FontName /%s /Flags 4 /FontBBox [%s] /ItalicAngle 0 /Ascent %.3F /Descent %.3F /CapHeight %.3F /StemV 80 /%s %d 0 R >>',
            $name, $box, $this->metadata['ascent'], $this->metadata['descent'], $this->metadata['ascent'], $fontFile, $fontId);
        $descendant = ++$nextId;
        $subtype = $truetype ? 'CIDFontType2' : 'CIDFontType0';
        $objects[$descendant] = "<< /Type /Font /Subtype /$subtype /BaseFont /$name /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor $descriptor 0 R /DW 1000 /W [1 ["
            . implode(' ', $widths) . "]] $mapEntry >>";
        $objects[++$nextId] = "<< /Type /Font /Subtype /Type0 /BaseFont /$name /Encoding /Identity-H /DescendantFonts [$descendant 0 R] /ToUnicode $cmapId 0 R >>";
        return $objects;
    }
}
