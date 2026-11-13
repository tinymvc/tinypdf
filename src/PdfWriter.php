<?php

declare(strict_types=1);

namespace Spark\Pdf;

use function count;
use function sprintf;
use function strlen;

/**
 * Low-level PDF 1.4/1.7 document compiler.
 * Assembles pages, resources (fonts, images, ExtGState), annotations (hyperlinks),
 * outlines (bookmarks), and xref tables into valid PDF binary output.
 */
final class PdfWriter
{
    private int $id = 2; // 1 = Catalog, 2 = Pages
    private array $objects = [];

    /** @var list<array{w: float, h: float, ops: list<string>, bg: ?array, annots: list<array>}> */
    private array $pages = [];

    /** @var array<string, string> metadata */
    private array $info = [];

    /** @var list<array{title: string, page: int, y: float, level: int}> bookmarks */
    private array $bookmarks = [];

    public function setTitle(string $title): void
    {
        $this->info['Title'] = $title;
    }

    public function setAuthor(string $author): void
    {
        $this->info['Author'] = $author;
    }

    public function setSubject(string $subject): void
    {
        $this->info['Subject'] = $subject;
    }

    public function addBookmark(string $title, int $pageIdx, float $y, int $level = 1): void
    {
        $this->bookmarks[] = [
            'title' => $title,
            'page' => $pageIdx,
            'y' => $y,
            'level' => $level,
        ];
    }

    public function addPage(float $w, float $h, array $ops, ?array $bg = null, array $annots = []): void
    {
        $this->pages[] = [
            'w' => $w,
            'h' => $h,
            'ops' => $ops,
            'bg' => $bg,
            'annots' => $annots,
        ];
    }

    /**
     * Builds and returns the final raw PDF binary string.
     */
    public function output(
        array $extraObjects,
        string $fontResources,
        string $imageResources,
        string $extGStateResources
    ): string {
        $this->objects = [1 => '', 2 => ''];
        foreach ($extraObjects as $k => $v) {
            $this->objects[$k] = $v;
        }

        $this->id = empty($this->objects) ? 2 : max(array_keys($this->objects));

        $resources = "<< $fontResources$imageResources$extGStateResources>>";

        $pageKids = [];
        $pageIds = [];

        // Build Pages and Contents
        foreach ($this->pages as $i => $p) {
            $pageId = ++$this->id;
            $pageIds[$i] = $pageId;
            $pageKids[] = "$pageId 0 R";

            // Annotations (Links)
            $annotRefs = [];
            foreach ($p['annots'] as $link) {
                $annotId = ++$this->id;
                [$lx, $ly, $lw, $lh] = $link['rect'];
                $rectStr = sprintf('%.3F %.3F %.3F %.3F', $lx, $p['h'] - $ly - $lh, $lx + $lw, $p['h'] - $ly);
                $uri = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $link['url']);
                $this->objects[$annotId] = sprintf(
                    '<< /Type /Annot /Subtype /Link /Rect [%s] /Border [0 0 0] /A << /Type /Action /S /URI /URI (%s) >> >>',
                    $rectStr,
                    $uri
                );
                $annotRefs[] = "$annotId 0 R";
            }
            $annotStr = !empty($annotRefs) ? ' /Annots [' . implode(' ', $annotRefs) . ']' : '';

            // Contents stream
            $contentId = ++$this->id;
            $ops = $p['ops'];
            if ($p['bg'] !== null) {
                $bgOp = sprintf("q %.3F %.3F %.3F rg 0 0 %.3F %.3F re f Q\n", $p['bg'][0] / 255, $p['bg'][1] / 255, $p['bg'][2] / 255, $p['w'], $p['h']);
                array_unshift($ops, $bgOp);
            }
            $streamData = gzcompress(implode("\n", $ops), 6);
            $this->objects[$contentId] = sprintf(
                "<< /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                strlen($streamData),
                $streamData
            );

            // Page object
            $this->objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.3F %.3F] /Resources %s /Contents %d 0 R%s >>',
                $p['w'],
                $p['h'],
                $resources,
                $contentId,
                $annotStr
            );
        }

        // Outlines (Bookmarks)
        $outlinesRef = '';
        if (!empty($this->bookmarks)) {
            $outlinesRef = $this->buildOutlines($pageIds);
        }

        // Catalog and Pages
        $this->objects[1] = '<< /Type /Catalog /Pages 2 0 R' . $outlinesRef . ' >>';
        $this->objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $pageKids), count($pageKids));

        // Info dictionary
        $infoDict = '<< /Producer ' . self::pdfString('TinyPDF (HtmlPdf)') . ' /CreationDate (D:' . date('YmdHis') . ')';
        foreach ($this->info as $k => $v) {
            $infoDict .= " /$k " . self::pdfString($v);
        }
        $infoDict .= ' >>';
        $infoId = ++$this->id;
        $this->objects[$infoId] = $infoDict;

        // Assembly
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($this->objects);

        foreach ($this->objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "$num 0 obj\n$body\nendobj\n";
        }

        $xref = strlen($out);
        $totalObjs = $this->id + 1;
        $out .= "xref\n0 $totalObjs\n0000000000 65535 f \n";
        for ($n = 1; $n <= $this->id; $n++) {
            $off = $offsets[$n] ?? 0;
            $out .= sprintf("%010d 00000 n \n", $off);
        }

        $out .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF",
            $totalObjs,
            $infoId,
            $xref
        );

        return $out;
    }

    private function buildOutlines(array $pageIds): string
    {
        $firstId = ++$this->id;
        $count = count($this->bookmarks);
        $itemIds = [];

        for ($i = 0; $i < $count; $i++) {
            $itemIds[$i] = ++$this->id;
        }

        for ($i = 0; $i < $count; $i++) {
            $bm = $this->bookmarks[$i];
            $targetPageId = $pageIds[$bm['page']] ?? $pageIds[0];
            $prevStr = $i > 0 ? sprintf(' /Prev %d 0 R', $itemIds[$i - 1]) : '';
            $nextStr = $i + 1 < $count ? sprintf(' /Next %d 0 R', $itemIds[$i + 1]) : '';

            $this->objects[$itemIds[$i]] = sprintf(
                '<< /Title %s /Parent %d 0 R%s%s /Dest [%d 0 R /XYZ 0 %.3F null] >>',
                self::pdfString($bm['title']),
                $firstId,
                $prevStr,
                $nextStr,
                $targetPageId,
                $bm['y']
            );
        }

        $this->objects[$firstId] = sprintf(
            '<< /Type /Outlines /First %d 0 R /Last %d 0 R /Count %d >>',
            $itemIds[0],
            $itemIds[$count - 1],
            $count
        );

        return sprintf(' /Outlines %d 0 R', $firstId);
    }

    private static function pdfString(string $text): string
    {
        return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))) . '>';
    }
}
