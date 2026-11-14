<?php

declare(strict_types=1);

namespace Spark\Pdf;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use InvalidArgumentException;
use RuntimeException;
use Spark\Pdf\Font\FontManager;
use Spark\Pdf\Graphics\BackgroundImage;
use Spark\Pdf\Graphics\Color;
use Spark\Pdf\Graphics\GraphicState;
use Spark\Pdf\Graphics\SvgRenderer;
use Spark\Pdf\Layout\FlexEngine;
use Spark\Pdf\Layout\InlineBox;
use Spark\Pdf\Layout\TableEngine;
use function array_key_exists;
use function array_slice;
use function chr;
use function count;
use function in_array;
use function is_array;
use function is_float;
use function is_string;
use function ord;
use function sprintf;
use function strlen;

/**
 * Pure-PHP renderer for a documented subset of HTML/CSS document templates.
 * See README.md for font, shaping, layout, and resource-trust limits.
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
final class HtmlPdf
{
    private const PX = 0.75;          // 1 CSS px in points
    private const MM = 72 / 25.4;     // 1 mm in points
    private const SIDES = ['top', 'right', 'bottom', 'left'];

    public const FORMAT_A3 = 'A3';
    public const FORMAT_A4 = 'A4';
    public const FORMAT_A5 = 'A5';
    public const FORMAT_A6 = 'A6';
    public const FORMAT_LETTER = 'LETTER';
    public const FORMAT_LEGAL = 'LEGAL';
    public const FORMAT_CARD = 'CARD';

    public const FONT_HELVETICA = 'helvetica';
    public const FONT_TIMES = 'times';
    public const FONT_COURIER = 'courier';

    private const FORMATS = [
        'A3' => [297, 420],
        'A4' => [210, 297],
        'A5' => [148, 210],
        'A6' => [105, 148],
        'LETTER' => [215.9, 279.4],
        'LEGAL' => [215.9, 355.6],
        'CARD' => [85.6, 53.98],
    ];

    private const FONT_KEYWORDS = [
        'xx-small' => 9,
        'x-small' => 10,
        'small' => 13,
        'medium' => 16,
        'large' => 18,
        'x-large' => 24,
        'xx-large' => 32,
    ];

    private const BLOCK_TAGS = [
        'html',
        'body',
        'div',
        'p',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'ul',
        'ol',
        'li',
        'dl',
        'dt',
        'dd',
        'blockquote',
        'pre',
        'hr',
        'table',
        'section',
        'article',
        'header',
        'footer',
        'main',
        'nav',
        'aside',
        'form',
        'fieldset',
        'address',
        'figure',
        'figcaption',
        'center',
    ];

    private const HIDDEN_TAGS = [
        'head',
        'style',
        'script',
        'title',
        'meta',
        'link',
        'noscript',
        'canvas',
        'input',
        'select',
        'textarea',
        'template',
    ];

    private const TABLE_DISPLAY = [
        'table' => 'table',
        'thead' => 'table-header-group',
        'tbody' => 'table-row-group',
        'tfoot' => 'table-footer-group',
        'tr' => 'table-row',
        'td' => 'table-cell',
        'th' => 'table-cell',
        'li' => 'list-item',
    ];

    private const UA = [
        'h1' => ['font-size' => '2em', 'font-weight' => 'bold', 'margin' => '.67em 0'],
        'h2' => ['font-size' => '1.5em', 'font-weight' => 'bold', 'margin' => '.75em 0'],
        'h3' => ['font-size' => '1.17em', 'font-weight' => 'bold', 'margin' => '.83em 0'],
        'h4' => ['font-weight' => 'bold', 'margin' => '1.12em 0'],
        'h5' => ['font-size' => '.83em', 'font-weight' => 'bold', 'margin' => '1.5em 0'],
        'h6' => ['font-size' => '.67em', 'font-weight' => 'bold', 'margin' => '1.67em 0'],
        'p' => ['margin' => '1em 0'],
        'ul' => ['margin' => '1em 0', 'padding-left' => '30pt', 'list-style-type' => 'disc'],
        'ol' => ['margin' => '1em 0', 'padding-left' => '30pt', 'list-style-type' => 'decimal'],
        'blockquote' => ['margin' => '1em 30pt'],
        'dd' => ['margin-left' => '30pt'],
        'hr' => ['border-top' => '1px solid #999', 'margin' => '.5em 0'],
        'pre' => ['font-family' => 'monospace', 'white-space' => 'pre', 'margin' => '1em 0'],
        'code' => ['font-family' => 'monospace'],
        'kbd' => ['font-family' => 'monospace'],
        'samp' => ['font-family' => 'monospace'],
        'b' => ['font-weight' => 'bold'],
        'strong' => ['font-weight' => 'bold'],
        'th' => ['font-weight' => 'bold', 'text-align' => 'center', 'padding' => '1px'],
        'td' => ['padding' => '1px'],
        'i' => ['font-style' => 'italic'],
        'em' => ['font-style' => 'italic'],
        'cite' => ['font-style' => 'italic'],
        'address' => ['font-style' => 'italic'],
        'u' => ['text-decoration' => 'underline'],
        'ins' => ['text-decoration' => 'underline'],
        's' => ['text-decoration' => 'line-through'],
        'strike' => ['text-decoration' => 'line-through'],
        'del' => ['text-decoration' => 'line-through'],
        'a' => ['color' => '#0000ee', 'text-decoration' => 'underline'],
        'small' => ['font-size' => '.83em'],
        'big' => ['font-size' => '1.17em'],
        'sub' => ['font-size' => '.83em', 'vertical-align' => 'sub'],
        'sup' => ['font-size' => '.83em', 'vertical-align' => 'super'],
        'mark' => ['background-color' => '#ffff00'],
        'center' => ['text-align' => 'center'],
        'table' => ['border-spacing' => '0'],
    ];

    private const INHERITED = [
        'color',
        'family',
        'size',
        'bold',
        'italic',
        'align',
        'lh',
        'under',
        'strike',
        'transform',
        'list',
        'pre',
        'wrap',
        'direction',
        'letter-spacing',
        'word-spacing',
        'wordBreak'
    ];

    private const BORDER_NONE = ['w' => 0.0, 'c' => [0, 0, 0], 's' => 'none'];

    private const RESET = [
        'display' => 'inline',
        'bg' => null,
        'bgImg' => null,
        'bgRepeat' => 'no-repeat',
        'bgPos' => '0% 0%',
        'bgSize' => 'auto',
        'opacity' => 1.0,
        'margin' => [0.0, 0.0, 0.0, 0.0],
        'mAutoL' => false,
        'mAutoR' => false,
        'padding' => [0.0, 0.0, 0.0, 0.0],
        'border' => [self::BORDER_NONE, self::BORDER_NONE, self::BORDER_NONE, self::BORDER_NONE],
        'radius' => 0.0,
        'width' => null,
        'minWidth' => null,
        'maxWidth' => null,
        'height' => null,
        'minHeight' => null,
        'maxHeight' => null,
        'sizing' => 'content',
        'position' => 'static',
        'pos' => [null, null, null, null],
        'breakBefore' => false,
        'breakAfter' => false,
        'breakInside' => true,
        'valign' => null,
        'shift' => 0.0,
        'spacing' => 0.0,
        'collapse' => false,
        'flex-direction' => 'row',
        'justify-content' => 'flex-start',
        'align-items' => 'stretch',
        'gap' => 0.0,
        'flex' => null,
        'flex-grow' => null,
        'direction' => 'ltr',
        'letter-spacing' => 0.0,
        'word-spacing' => 0.0,
        'wordBreak' => 'normal',
    ];

    // ------------------------------------------------------------------ state

    private array $formatSpec;
    private string $orientationSpec;
    private array $margin;
    private array $pages = [];
    private array $ops = [];
    private array $pageAnnots = [];
    private bool $pageOpen = false;
    private float $pageW = 0.0;
    private float $pageH = 0.0;
    private float $pageTop = 0.0;
    private float $pageBottom = 0.0;
    private ?array $pageBg = null;
    private float $y = 0.0;
    private float $pending = 0.0;
    private array $frames = [];
    private bool $allowBreak = true;
    private bool $measuring = false;
    private float $extent = 0.0;
    private ?array $marker = null;
    private array $listStack = [];
    private array $rules = [];
    private array $styleCache = [];
    private array $images = [];
    private array $imageCache = [];
    private array $info = [];
    private array $bookmarks = [];
    private array $anchors = [];
    private float $rootFontSize;

    private FontManager $fontManager;
    private GraphicState $graphicState;
    private ?string $pageBackgroundImage = null;
    private ?array $bodyBackground = null;
    private float $pageBackgroundOpacity = 1.0;
    private ?string $headerHtml = null;
    private ?string $footerHtml = null;
    private bool $excludeHeaderFirstPage = false;
    private bool $excludeFooterFirstPage = false;
    private array $activeLinks = [];

    public function __construct(
        string|array $format = 'A4',
        string $orientation = 'P',
        float|array $margin = 10.0,
        private float $fontSize = 10.0,
        private string $fontFamily = 'helvetica',
        private ?string $basePath = null,
        private bool $allowRemote = false,
        private ?string $footer = null,
    ) {
        if (!is_finite($fontSize) || $fontSize <= 0) {
            throw new InvalidArgumentException('Font size must be positive and finite.');
        }
        $this->formatSpec = is_array($format) ? $format : [$format];
        $this->orientationSpec = $orientation;
        $this->pageSize($format, $orientation);
        $this->margin = $this->parseMargin($margin);
        $this->rootFontSize = $fontSize;
        $this->fontManager = new FontManager();
        $this->graphicState = new GraphicState();
    }

    // ------------------------------------------------------------- public API

    public function setTitle(string $title): static
    {
        $this->info['Title'] = $title;
        return $this;
    }

    public function setAuthor(string $author): static
    {
        $this->info['Author'] = $author;
        return $this;
    }

    public function setSubject(string $subject): static
    {
        $this->info['Subject'] = $subject;
        return $this;
    }

    public function setFooter(?string $footer): static
    {
        $this->footer = $footer;
        return $this;
    }

    public function setHeaderHtml(?string $html, bool $excludeFirstPage = false): static
    {
        $this->headerHtml = $html;
        $this->excludeHeaderFirstPage = $excludeFirstPage;
        return $this;
    }

    public function setFooterHtml(?string $html, bool $excludeFirstPage = false): static
    {
        $this->footerHtml = $html;
        $this->excludeFooterFirstPage = $excludeFirstPage;
        return $this;
    }

    public function setBackgroundImage(string $src, float $opacity = 1.0): static
    {
        $this->pageBackgroundImage = $src;
        $this->pageBackgroundOpacity = max(0.0, min(1.0, $opacity));
        return $this;
    }

    public function addFont(string $family, string $path, bool $bold = false, bool $italic = false): static
    {
        $this->fontManager->addFont($family, $path, $bold, $italic);
        return $this;
    }

    public function setBasePath(?string $path): static
    {
        $this->basePath = $path;
        return $this;
    }

    public function addBookmark(string $title, int $level = 1, ?int $page = null, ?float $y = null): static
    {
        $this->bookmarks[] = [
            'title' => $title,
            'page' => $page ?? ($this->pageOpen ? count($this->pages) : max(0, count($this->pages) - 1)),
            'y' => $y ?? max(0.0, $this->pageH - ($this->y + $this->pending)),
            'level' => max(1, $level),
        ];
        return $this;
    }

    /**
     * Renders HTML document/fragment, starting on a new page.
     */
    public function addHtml(string $html, string|array|null $format = null, ?string $orientation = null): static
    {
        $this->finishPage();

        $doc = $this->parse($html);
        $this->styleCache = [];
        $this->rules = $this->collectRules($doc);
        $this->bodyBackground = null;

        $spec = $format ?? (count($this->formatSpec) === 1 ? $this->formatSpec[0] : $this->formatSpec);
        if ($format !== null) {
            $this->formatSpec = is_array($format) ? array_values($format) : [$format];
        }
        if ($orientation !== null) {
            $this->orientationSpec = $orientation;
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            $this->beginPage($this->pageSize($spec, $orientation ?? $this->orientationSpec));
            return $this;
        }

        $this->rootFontSize = $this->fontSize;
        $root = $doc->documentElement instanceof DOMElement
            ? $this->computeStyle($doc->documentElement, $this->rootStyle())
            : $this->rootStyle();
        $this->rootFontSize = $root['size'];
        $style = $this->computeStyle($body, $root);
        $this->bodyBackground = $style;
        $this->beginPage($this->pageSize($spec, $orientation ?? $this->orientationSpec));
        $style['bg'] = null;
        $style['bgImg'] = null;

        $this->renderBox($body, $style, $this->margin[3], $this->pageW - $this->margin[1] - $this->margin[3]);

        return $this;
    }

    public function output(): string
    {
        $this->finishPage();
        if (!$this->pages) {
            $this->beginPage($this->pageSize(count($this->formatSpec) === 1 ? $this->formatSpec[0] : $this->formatSpec, $this->orientationSpec));
            $this->flushPage();
            $this->pageOpen = false;
        }
        $pages = $this->addHeadersAndFooters();

        $writer = new PdfWriter();
        foreach ($this->info as $k => $v) {
            if ($k === 'Title')
                $writer->setTitle($v);
            if ($k === 'Author')
                $writer->setAuthor($v);
            if ($k === 'Subject')
                $writer->setSubject($v);
        }

        $id = 2;
        $extraObjects = [];

        // Build Font Resources
        $fontRes = $this->fontManager->buildPdfResources($extraObjects, $id);

        // Build Image Resources
        $imageRes = '';
        foreach ($this->images as $img) {
            $extra = '';
            if ($img['smask'] !== null) {
                $id++;
                $extraObjects[$id] = sprintf(
                    "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                    $img['w'],
                    $img['h'],
                    strlen($img['smask']),
                    $img['smask']
                );
                $extra = " /SMask $id 0 R";
            }
            $id++;
            $extraObjects[$id] = sprintf(
                "<< %s%s /Length %d >>\nstream\n%s\nendstream",
                $img['dict'],
                $extra,
                strlen($img['data']),
                $img['data']
            );
            $imageRes .= "/{$img['name']} $id 0 R ";
        }

        // Build ExtGState Resources
        $extGStateRes = $this->graphicState->getResourceDict($extraObjects, $id);

        // Pass bookmarks to writer
        foreach ($this->bookmarks as $bm) {
            $writer->addBookmark($bm['title'], $bm['page'], $bm['y'], $bm['level']);
        }

        // Resolve internal anchors for link annotations
        foreach ($pages as &$p) {
            if (!empty($p['annots'])) {
                foreach ($p['annots'] as &$annot) {
                    if (str_starts_with($annot['url'], '#')) {
                        $targetId = substr($annot['url'], 1);
                        if (isset($this->anchors[$targetId])) {
                            $annot['dest'] = $this->anchors[$targetId];
                        }
                    }
                }
                unset($annot);
            }
        }
        unset($p);

        // Feed pages to writer
        foreach ($pages as $p) {
            $writer->addPage($p['w'], $p['h'], $p['ops'], $p['bg'], $p['annots'] ?? []);
        }

        return $writer->output($extraObjects, "/Font << $fontRes>> ", "/XObject << $imageRes>> ", $extGStateRes);
    }

    public function save(string $path): void
    {
        if (file_put_contents($path, $this->output()) === false) {
            throw new RuntimeException("Cannot write PDF to $path");
        }
    }

    public function download(string $filename = 'document.pdf', bool $inline = false): void
    {
        $filename = str_replace(['\\', '"', "\r", "\n", "\0"], '_', basename($filename));
        $pdf = $this->output();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    // ------------------------------------------------------------ page setup

    private function toMm(float|int|string $val): ?float
    {
        if (is_numeric($val)) {
            return (float) $val;
        }
        $v = strtolower(trim((string) $val));
        if (!preg_match('/^(-?[\d.]+)\s*(px|pt|mm|cm|in)?$/', $v, $m)) {
            return null;
        }
        $n = (float) $m[1];
        return match ($m[2] ?? 'mm') {
            'pt' => round($n / self::MM, 4),
            'mm' => $n,
            'cm' => $n * 10.0,
            'in' => round($n * 25.4, 4),
            'px' => round(($n * self::PX) / self::MM, 4),
            default => $n,
        };
    }

    private function pageSize(string|array $format, string $orientation): array
    {
        if (is_array($format)) {
            $vals = array_values($format);
            if (count($vals) >= 2) {
                $w = $this->toMm($vals[0]);
                $h = $this->toMm($vals[1]);
                if ($w !== null && $h !== null && is_finite($w) && is_finite($h) && $w > 0 && $h > 0) {
                    if (strtoupper($orientation) === 'L' && $w < $h) {
                        [$w, $h] = [$h, $w];
                    } elseif (strtoupper($orientation) === 'P' && $w > $h) {
                        [$w, $h] = [$h, $w];
                    }
                    return [(float) $w, (float) $h];
                }
            } elseif (count($vals) === 1) {
                $format = $vals[0];
            }
        }

        if (is_string($format)) {
            $clean = trim($format, "()[] \t\r\n");
            if (isset(self::FORMATS[strtoupper($clean)])) {
                [$w, $h] = self::FORMATS[strtoupper($clean)];
            } else {
                $parts = preg_split('/[\s,]+/', $clean);
                if ($parts !== false && count($parts) === 2) {
                    $w = $this->toMm($parts[0]);
                    $h = $this->toMm($parts[1]);
                } elseif (preg_match('/^([\d.]+(?:mm|pt|cm|in|px)?)\s*[x*]\s*([\d.]+(?:mm|pt|cm|in|px)?)$/i', $clean, $m)) {
                    $w = $this->toMm($m[1]);
                    $h = $this->toMm($m[2]);
                } elseif (($single = $this->toMm($clean)) !== null) {
                    $w = $single;
                    $h = $single;
                } else {
                    throw new InvalidArgumentException("Unknown page format '$format'");
                }
                if ($w === null || $h === null || !is_finite($w) || !is_finite($h) || $w <= 0 || $h <= 0) {
                    throw new InvalidArgumentException("Unknown page format '$format'");
                }
            }
        } else {
            throw new InvalidArgumentException("Unknown page format");
        }

        if (strtoupper($orientation) === 'L' && $w < $h) {
            [$w, $h] = [$h, $w];
        } elseif (strtoupper($orientation) === 'P' && $w > $h) {
            [$w, $h] = [$h, $w];
        }
        return [(float) $w, (float) $h];
    }

    private function parseMargin(float|array $margin): array
    {
        $m = is_array($margin) ? array_values($margin) : [$margin];
        $m = match (count($m)) {
            1 => [$m[0], $m[0], $m[0], $m[0]],
            2 => [$m[0], $m[1], $m[0], $m[1]],
            4 => $m,
            default => throw new InvalidArgumentException('Margin needs 1, 2 or 4 values (mm).'),
        };
        return array_map(static fn($v) => (float) $v * self::MM, $m);
    }

    private function beginPage(array $mm, bool $keepFrames = false): void
    {
        $this->pageW = $mm[0] * self::MM;
        $this->pageH = $mm[1] * self::MM;
        $this->pageTop = $this->margin[0];
        $this->pageBottom = $this->pageH - $this->margin[2];
        if ($this->pageBottom <= $this->pageTop || $this->pageW <= $this->margin[1] + $this->margin[3]) {
            throw new InvalidArgumentException('Page margins leave no content area.');
        }
        $this->ops = [];
        $this->pageAnnots = [];
        if (!$keepFrames) {
            $this->frames = [];
        }
        $this->pageBg = $this->bodyBackground['bg'] ?? null;
        $this->pageOpen = true;
        $this->y = $this->pageTop;
        $this->pending = 0.0;

        // Apply page-level background image if set
        $backgroundImage = $this->pageBackgroundImage ?? ($this->bodyBackground['bgImg'] ?? null);
        if ($backgroundImage !== null) {
            $img = $this->loadImage($backgroundImage);
            if ($img !== null) {
                $bg = $this->pageBackgroundImage !== null
                    ? new BackgroundImage($img['name'], $img['w'] * self::PX, $img['h'] * self::PX, 'no-repeat', 'center', 'cover', $this->pageBackgroundOpacity)
                    : new BackgroundImage($img['name'], $img['w'] * self::PX, $img['h'] * self::PX, $this->bodyBackground['bgRepeat'], $this->bodyBackground['bgPos'], $this->bodyBackground['bgSize']);
                $this->ops[] = $bg->render(0, 0, $this->pageW, $this->pageH, 0, $this->pageH, $this->graphicState);
            }
        }
    }

    private function flushPage(): void
    {
        $this->pages[] = [
            'w' => $this->pageW,
            'h' => $this->pageH,
            'ops' => $this->ops,
            'bg' => $this->pageBg,
            'annots' => $this->pageAnnots,
            'margin' => $this->margin,
        ];
        $this->ops = [];
        $this->pageAnnots = [];
    }

    private function finishPage(): void
    {
        if (!$this->pageOpen) {
            return;
        }
        if ($this->ops !== [] || !$this->pages) {
            $this->flushPage();
        }
        $this->pageOpen = false;
    }

    private function newPage(): void
    {
        for ($i = count($this->frames) - 1; $i >= 0; $i--) {
            $f = $this->frames[$i];
            $this->frames[$i]['op'] = 0;
            if ($f['start'] !== null) {
                $this->paintFrame($f, max($f['start'], $this->y), false);
                $this->frames[$i]['first'] = false;
                $this->frames[$i]['start'] = $this->pageTop;
            }
        }
        $this->flushPage();
        $this->beginPage([$this->pageW / self::MM, $this->pageH / self::MM], keepFrames: true);
    }

    private function forcePageBreak(): void
    {
        if ($this->allowBreak && $this->y > $this->pageTop + 0.01) {
            $this->newPage();
        }
    }

    private function addHeadersAndFooters(): array
    {
        $total = count($this->pages);
        $pages = $this->pages;

        foreach ($pages as $i => &$page) {
            $isFirst = ($i === 0);
            $margin = $page['margin'];

            // Simple text footer
            if ($this->footer !== null && $this->footer !== '') {
                $text = str_replace(['{page}', '{pages}'], [(string) ($i + 1), (string) $total], $this->footer);
                $font = $this->fontManager->getFont($this->fontFamily, false, false, $text);
                $resName = $this->fontManager->getResourceName($font);
                $w = $font->getTextWidth($text, 8.0);
                $x = ($page['w'] - $w) / 2;
                $y = $page['h'] - $margin[2] / 2 + 3;

                $op = sprintf(
                    "q BT /%s 8 Tf 0.43 0.43 0.43 rg %.3F %.3F Td %s Tj ET Q\n",
                    $resName,
                    $x,
                    $page['h'] - $y,
                    $font->encode($text)
                );
                $page['ops'][] = $op;
            }

            // HTML Header
            if ($this->headerHtml !== null && $this->headerHtml !== '' && !($isFirst && $this->excludeHeaderFirstPage)) {
                $hHtml = str_replace(['{page}', '{pages}'], [(string) ($i + 1), (string) $total], $this->headerHtml);
                $headerY = max(0.0, ($margin[0] / 2) - 4.0 * self::MM);
                $ops = $this->renderIsolatedHtml($hHtml, $page['w'], $page['h'], $margin[3], $headerY, $page['w'] - $margin[1] - $margin[3]);
                if (!empty($ops)) {
                    array_unshift($page['ops'], ...$ops);
                }
            }

            // HTML Footer
            if ($this->footerHtml !== null && $this->footerHtml !== '' && !($isFirst && $this->excludeFooterFirstPage)) {
                $fHtml = str_replace(['{page}', '{pages}'], [(string) ($i + 1), (string) $total], $this->footerHtml);
                $footerY = $page['h'] - ($margin[2] / 2) - 4.0 * self::MM;
                $ops = $this->renderIsolatedHtml($fHtml, $page['w'], $page['h'], $margin[3], $footerY, $page['w'] - $margin[1] - $margin[3]);
                if (!empty($ops)) {
                    $page['ops'] = [...$page['ops'], ...$ops];
                }
            }
        }
        unset($page);
        return $pages;
    }

    private function renderIsolatedHtml(string $html, float $pageW, float $pageH, float $x, float $y, float $maxW): array
    {
        $saved = get_object_vars($this);

        $this->ops = [];
        $this->y = $y;
        $this->pageW = $pageW;
        $this->pageH = $pageH;
        $this->pageTop = $y;
        $this->pageBottom = $pageH;
        $this->allowBreak = false;
        $this->pending = 0.0;
        $this->frames = [];
        $this->listStack = [];
        $this->activeLinks = [];
        $this->styleCache = [];

        try {
            $doc = $this->parse($html);
            $this->rules = $this->collectRules($doc);
            $body = $doc->getElementsByTagName('body')->item(0);
            if ($body instanceof DOMElement) {
                $style = $this->computeStyle($body, $this->rootStyle());
                $this->renderBox($body, $style, $x, $maxW);
            }
            return $this->ops;
        } finally {
            // Keep resources discovered in the header, but restore document layout state.
            unset($saved['fontManager'], $saved['graphicState'], $saved['images'], $saved['imageCache']);
            foreach ($saved as $name => $value) {
                $this->$name = $value;
            }
        }
    }

    // ---------------------------------------------------------------- parsing

    private function parse(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }

    private function collectRules(DOMDocument $doc): array
    {
        $css = '';
        foreach ($doc->getElementsByTagName('style') as $style) {
            if ($this->printMedia($style->getAttribute('media'))) {
                $css .= $style->textContent . "\n";
            }
        }
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? '';
        $css = $this->filterAtRules($css);

        // Parse @font-face rules
        if (preg_match_all('/@font-face\s*\{([^{}]*)\}/i', $css, $fontFaceMatches)) {
            foreach ($fontFaceMatches[1] as $body) {
                $decl = $this->parseDeclarations($body);
                if (isset($decl['font-family']) && isset($decl['src'])) {
                    $family = trim($decl['font-family'], "'\"");
                    if (preg_match('/url\(([\'"]?)(.*?)\1\)/i', $decl['src'], $urlM)) {
                        $path = $urlM[2];
                        if (str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path))
                            continue;
                        if ($this->basePath !== null && !preg_match('~^(/|[A-Za-z]:[\\\\/])~', $path)) {
                            $path = rtrim($this->basePath, '/\\') . '/' . $path;
                        }
                        $bold = isset($decl['font-weight']) && in_array(strtolower($decl['font-weight']), ['bold', '700', '800', '900'], true);
                        $italic = isset($decl['font-style']) && in_array(strtolower($decl['font-style']), ['italic', 'oblique'], true);
                        if (is_file($path)) {
                            $this->fontManager->addFont($family, $path, $bold, $italic);
                        }
                    }
                }
            }
        }

        // Parse @page rules
        if (preg_match('/@page\s*\{([^{}]*)\}/i', $css, $pageMatch)) {
            $pageDecl = $this->parseDeclarations($pageMatch[1]);
            foreach (self::SIDES as $i => $side) {
                if (isset($pageDecl["margin-$side"])) {
                    $length = $this->length($pageDecl["margin-$side"], $this->fontSize);
                    if (is_float($length) && $length >= 0) {
                        $this->margin[$i] = $length;
                    }
                }
            }
            if (isset($pageDecl['size'])) {
                $sizeVal = trim($pageDecl['size'], "() \t\r\n");
                $sizeTokens = $this->tokens($sizeVal);
                $fmt = null;
                $orient = null;
                $customDims = [];
                foreach ($sizeTokens as $st) {
                    $stl = strtolower($st);
                    if ($stl === 'landscape') {
                        $orient = 'L';
                    } elseif ($stl === 'portrait') {
                        $orient = 'P';
                    } elseif (isset(self::FORMATS[strtoupper($st)])) {
                        $fmt = $st;
                    } else {
                        $dim = $this->toMm($st);
                        if ($dim !== null) {
                            $customDims[] = $dim;
                        }
                    }
                }
                if (count($customDims) === 2) {
                    $this->formatSpec = [$customDims[0], $customDims[1]];
                } elseif (count($customDims) === 1) {
                    $this->formatSpec = [$customDims[0], $customDims[0]];
                } elseif ($fmt !== null) {
                    $this->formatSpec = [$fmt];
                }
                if ($orient !== null) {
                    $this->orientationSpec = $orient;
                }
            }
        }

        $rules = [];
        if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $order => $m) {
                if (str_contains($m[1], '@')) {
                    continue;
                }
                $decl = $this->parseDeclarations($m[2], $important);
                foreach ($this->splitCss($m[1], ',') as $selector) {
                    $parts = $this->parseSelector(trim($selector));
                    if ($parts) {
                        $rules[] = ['parts' => $parts, 'spec' => $this->specificity($selector), 'decl' => $decl, 'important' => $important, 'order' => $order];
                    }
                }
            }
        }
        usort($rules, static fn($a, $b) => [$a['spec'], $a['order']] <=> [$b['spec'], $b['order']]);
        return $rules;
    }

    private function printMedia(string $media): bool
    {
        foreach (explode(',', strtolower(trim($media))) as $query) {
            if (in_array(trim($query), ['', 'all', 'print', 'only print'], true)) {
                return true;
            }
        }
        return false;
    }

    private function filterAtRules(string $css): string
    {
        // Keep paged-media and font rules, flatten print media, and skip unsupported groups.
        return preg_replace_callback('/@([\w-]+)([^{};]*)\{((?:[^{}]|\{(?3)\})*)\}/s', function (array $match): string {
            $name = strtolower($match[1]);
            if ($name === 'media') {
                return $this->printMedia($match[2]) ? $this->filterAtRules($match[3]) : '';
            }
            return in_array($name, ['page', 'font-face'], true) ? $match[0] : '';
        }, $css) ?? '';
    }

    private function parseSelector(string $selector): array
    {
        $parts = [];
        $combinator = ' ';
        $tokens = preg_split('/\s*(>)\s*|\s+(?![^()]*\))/', $selector, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if ($token === '>') {
                $combinator = '>';
                continue;
            }
            $parts[] = [$token, $combinator];
            $combinator = ' ';
        }
        return $parts;
    }

    private function specificity(string $selector): array
    {
        return [
            (int) preg_match_all('/#[\p{L}\p{N}\p{M}_-]+/u', $selector),
            (int) preg_match_all('/[.:][\p{L}\p{N}\p{M}_-]+/u', $selector),
            (int) preg_match_all('/(?:^|[\s>])[a-zA-Z][\w-]*/', $selector),
        ];
    }

    private function matchSelector(DOMElement $el, array $parts, int $i): bool
    {
        [$compound, $combinator] = $parts[$i];
        if (!$this->matchCompound($el, $compound)) {
            return false;
        }
        if ($i === 0) {
            return true;
        }
        $parent = $el->parentNode;
        if ($combinator === '>') {
            return $parent instanceof DOMElement && $this->matchSelector($parent, $parts, $i - 1);
        }
        while ($parent instanceof DOMElement) {
            if ($this->matchSelector($parent, $parts, $i - 1)) {
                return true;
            }
            $parent = $parent->parentNode;
        }
        return false;
    }

    private function matchCompound(DOMElement $el, string $compound): bool
    {
        if (!preg_match_all('/([#.:]?)(\*|[\p{L}\p{N}\p{M}_-]+)(?:\(([^)]*)\))?/u', $compound, $m, PREG_SET_ORDER)) {
            return false;
        }
        if (implode('', array_column($m, 0)) !== $compound)
            return false;
        foreach ($m as $t) {
            [$type, $name, $arg] = [$t[1], $t[2], $t[3] ?? ''];
            $ok = match ($type) {
                '' => $name === '*' || strtolower($name) === $this->tag($el),
                '#' => $el->getAttribute('id') === $name,
                '.' => in_array($name, preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [], true),
                default => match (strtolower($name)) {
                        'first-child' => $this->elementIndex($el) === 1,
                        'last-child' => $this->nextElement($el) === null,
                        'nth-child' => $this->nthMatches($this->elementIndex($el), $arg),
                        'root' => $el === $el->ownerDocument->documentElement,
                        default => false,
                    },
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    private function elementIndex(DOMElement $el): int
    {
        $i = 1;
        for ($n = $el->previousSibling; $n !== null; $n = $n->previousSibling) {
            if ($n instanceof DOMElement) {
                $i++;
            }
        }
        return $i;
    }

    private function nextElement(DOMElement $el): ?DOMElement
    {
        for ($n = $el->nextSibling; $n !== null; $n = $n->nextSibling) {
            if ($n instanceof DOMElement) {
                return $n;
            }
        }
        return null;
    }

    private function nthMatches(int $pos, string $arg): bool
    {
        $arg = strtolower(trim($arg));
        if ($arg === 'odd')
            return $pos % 2 === 1;
        if ($arg === 'even')
            return $pos % 2 === 0;
        if (ctype_digit($arg))
            return $pos === (int) $arg;
        if (preg_match('/^([+-]?\d*)n\s*(?:([+-])\s*(\d+))?$/', $arg, $m)) {
            $a = match ($m[1]) { '', '+' => 1, '-' => -1, default => (int) $m[1]};
            $b = isset($m[3]) ? (int) $m[3] * ($m[2] === '-' ? -1 : 1) : 0;
            $n = $pos - $b;
            return $a === 0 ? $n === 0 : ($n % $a === 0 && intdiv($n, $a) >= 0);
        }
        return false;
    }

    // ----------------------------------------------------------------- styles

    private function tag(DOMElement $el): string
    {
        return strtolower($el->tagName);
    }

    private function rootStyle(): array
    {
        return [
            'color' => [0, 0, 0],
            'family' => $this->fontFamily,
            'size' => $this->fontSize,
            'bold' => false,
            'italic' => false,
            'align' => 'left',
            'lh' => null,
            'under' => false,
            'strike' => false,
            'transform' => 'none',
            'list' => 'disc',
            'pre' => false,
            'wrap' => true,
        ] + self::RESET;
    }

    private function computeStyle(DOMElement $el, array $parent): array
    {
        $key = spl_object_id($el);
        if (isset($this->styleCache[$key]) && $this->styleCache[$key]['el'] === $el) {
            return $this->styleCache[$key]['s'];
        }

        $tag = $this->tag($el);
        $s = array_intersect_key($parent, array_flip(self::INHERITED)) + self::RESET;
        $s['display'] = self::TABLE_DISPLAY[$tag] ?? (in_array($tag, self::BLOCK_TAGS, true) ? 'block' : 'inline');
        if (in_array($tag, self::HIDDEN_TAGS, true)) {
            $s['display'] = 'none';
        }
        if (in_array($tag, ['tr', 'thead', 'tbody', 'tfoot', 'td', 'th'], true)) {
            $s['valign'] = $parent['valign'] ?? null;
        }

        $decl = $this->normalize(self::UA[$tag] ?? []);
        $decl = array_replace($decl, $this->normalize($this->attributeDeclarations($el, $tag)));
        $important = [];
        foreach ($this->rules as $rule) {
            if ($this->matchSelector($el, $rule['parts'], count($rule['parts']) - 1)) {
                $decl = array_replace($decl, $rule['decl']);
                $important = array_replace($important, $rule['important']);
            }
        }
        $inline = $this->parseDeclarations($el->getAttribute('style'), $inlineImportant);
        $decl = array_replace($decl, $inline, $important, $inlineImportant);
        $s['variables'] = $parent['variables'] ?? [];
        foreach ($decl as $name => $value) {
            if (str_starts_with($name, '--')) {
                $s['variables'][$name] = $value;
            }
        }
        foreach ($decl as $name => $value) {
            if (str_contains($value, 'var(')) {
                $decl[$name] = $this->resolveVariables($value, $s['variables']);
            }
        }
        $decl = $this->normalize($decl);
        $this->apply($s, $decl, $parent);

        // In RTL mode, default text alignment is right unless explicitly overridden
        if ($s['direction'] === 'rtl' && !isset($decl['text-align'])) {
            $s['align'] = 'right';
        }

        $this->styleCache[$key] = ['el' => $el, 's' => $s];
        return $s;
    }

    private function resolveVariables(string $value, array $variables, array $seen = []): string
    {
        return preg_replace_callback('/var\(\s*(--[\w-]+)\s*(?:,\s*((?:[^()]|\((?2)\))*))?\)/', function (array $match) use ($variables, $seen): string {
            $name = $match[1];
            if (isset($seen[$name])) {
                return $match[2] ?? '';
            }
            $seen[$name] = true;
            return $this->resolveVariables($variables[$name] ?? ($match[2] ?? ''), $variables, $seen);
        }, $value) ?? $value;
    }

    private function attributeDeclarations(DOMElement $el, string $tag): array
    {
        $d = [];
        $attr = static fn(string $name): string => trim($el->getAttribute($name));
        $px = static fn(string $v): string => ctype_digit($v) ? $v . 'px' : $v;

        if (($v = strtolower($attr('dir'))) !== '') {
            if ($v === 'rtl' || $v === 'ltr') {
                $d['direction'] = $v;
            }
        }

        if (($v = $attr('width')) !== '' && in_array($tag, ['table', 'td', 'th', 'img', 'col', 'svg'], true)) {
            $d['width'] = $px($v);
        }
        if (($v = $attr('height')) !== '' && in_array($tag, ['table', 'td', 'th', 'tr', 'img', 'svg'], true)) {
            $d['height'] = $px($v);
        }
        if (($v = $attr('bgcolor')) !== '') {
            $d['background-color'] = $v;
        }
        if (($v = strtolower($attr('align'))) !== '') {
            if ($tag === 'table') {
                if ($v === 'center') {
                    $d['margin-left'] = $d['margin-right'] = 'auto';
                } elseif ($v === 'right') {
                    $d['margin-left'] = 'auto';
                }
            } else {
                $d['text-align'] = $v;
            }
        }
        if (($v = $attr('valign')) !== '') {
            $d['vertical-align'] = $v;
        }

        switch ($tag) {
            case 'font':
                if (($v = $attr('color')) !== '')
                    $d['color'] = $v;
                if (($v = $attr('face')) !== '')
                    $d['font-family'] = $v;
                if (($v = $attr('size')) !== '' && isset([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1][(int) $v])) {
                    $d['font-size'] = [1 => 7.5, 2 => 10, 3 => 12, 4 => 13.5, 5 => 18, 6 => 24, 7 => 36][(int) $v] . 'pt';
                }
                break;
            case 'table':
                if (($v = $attr('cellspacing')) !== '')
                    $d['border-spacing'] = $px($v);
                if ((int) $attr('border') > 0)
                    $d['border'] = ((int) $attr('border')) . 'px solid #999';
                break;
            case 'td':
            case 'th':
                for ($p = $el->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
                    if ($this->tag($p) === 'table') {
                        if (($v = trim($p->getAttribute('cellpadding'))) !== '')
                            $d['padding'] = $px($v);
                        if ((int) $p->getAttribute('border') > 0)
                            $d['border'] = '1px solid #999';
                        break;
                    }
                }
                break;
        }
        return $d;
    }

    private function parseDeclarations(string $css, ?array &$important = null): array
    {
        $out = [];
        $important = [];
        foreach ($this->splitCss($css, ';') as $declaration) {
            if (!str_contains($declaration, ':'))
                continue;
            [$name, $value] = explode(':', $declaration, 2);
            $isImportant = (bool) preg_match('/\s*!important\s*$/i', $value);
            $value = trim(preg_replace('/\s*!important\s*$/i', '', $value) ?? $value);
            if ($value !== '') {
                $name = trim($name);
                $expanded = $this->normalize([str_starts_with($name, '--') ? $name : strtolower($name) => $value]);
                $out = array_replace($out, $expanded);
                if ($isImportant) {
                    $important = array_replace($important, $expanded);
                }
            }
        }
        return array_replace($out, $important);
    }

    private function splitCss(string $css, string $delimiter): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        for ($i = 0, $length = strlen($css); $i < $length; $i++) {
            $char = $css[$i];
            if ($char === '\\') {
                $i++;
            } elseif ($quote !== null) {
                if ($char === $quote)
                    $quote = null;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === $delimiter && $depth === 0) {
                $parts[] = substr($css, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($css, $start);
        return $parts;
    }

    private function normalize(array $in): array
    {
        $out = [];
        foreach ($in as $name => $value) {
            $value = trim((string) $value);
            if (str_contains($value, 'var(')) {
                $out[$name] = $value;
                continue;
            }
            switch ($name) {
                case 'font':
                    if (preg_match('/^(.*?)\b((?:\d*\.)?\d+(?:px|pt|em|rem|%|mm|cm|in)|xx-small|x-small|small|medium|large|x-large|xx-large)(?:\s*\/\s*([^\s]+))?\s+(.+)$/i', $value, $font)) {
                        $out['font-style'] = preg_match('/\b(italic|oblique)\b/i', $font[1], $style) ? $style[1] : 'normal';
                        $out['font-weight'] = preg_match('/\b(bold|bolder|[1-9]00)\b/i', $font[1], $weight) ? $weight[1] : 'normal';
                        $out['font-size'] = $font[2];
                        $out['line-height'] = $font[3] !== '' ? $font[3] : 'normal';
                        $out['font-family'] = $font[4];
                    }
                    break;
                case 'margin':
                case 'padding':
                    foreach ($this->box($value) as $i => $v) {
                        $out["$name-" . self::SIDES[$i]] = $v;
                    }
                    break;
                case 'border-width':
                case 'border-style':
                case 'border-color':
                    foreach ($this->box($value) as $i => $v) {
                        $out['border-' . self::SIDES[$i] . '-' . substr($name, 7)] = $v;
                    }
                    break;
                case 'border':
                case 'border-top':
                case 'border-right':
                case 'border-bottom':
                case 'border-left':
                    [$w, $style, $color] = $this->borderParts($value);
                    foreach ($name === 'border' ? self::SIDES : [substr($name, 7)] as $side) {
                        $out["border-$side-width"] = $w;
                        $out["border-$side-style"] = $style;
                        if ($color !== null) {
                            $out["border-$side-color"] = $color;
                        }
                    }
                    break;
                case 'background':
                    if (preg_match('/url\(([\'"]?)(.*?)\1\)/i', $value, $m)) {
                        $out['background-image'] = $m[2];
                    }
                    foreach ($this->tokens($value) as $token) {
                        if ($this->color($token) !== null || strtolower($token) === 'transparent') {
                            $out['background-color'] = $token;
                        } elseif (in_array(strtolower($token), ['repeat', 'repeat-x', 'repeat-y', 'no-repeat'], true)) {
                            $out['background-repeat'] = strtolower($token);
                        }
                    }
                    break;
                case 'background-image':
                    if (preg_match('/url\(([\'"]?)(.*?)\1\)/i', $value, $m)) {
                        $out['background-image'] = $m[2];
                    } else {
                        $out['background-image'] = $value;
                    }
                    break;
                default:
                    $out[$name] = $value;
            }
        }
        return $out;
    }

    private function tokens(string $v): array
    {
        return preg_split('/\s+(?![^(]*\))/', trim($v), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function box(string $v): array
    {
        $t = $this->tokens($v);
        return match (count($t)) {
            0 => ['0', '0', '0', '0'],
            1 => [$t[0], $t[0], $t[0], $t[0]],
            2 => [$t[0], $t[1], $t[0], $t[1]],
            3 => [$t[0], $t[1], $t[2], $t[1]],
            default => [$t[0], $t[1], $t[2], $t[3]],
        };
    }

    private function borderParts(string $v): array
    {
        $w = $style = $color = null;
        foreach ($this->tokens($v) as $token) {
            $l = strtolower($token);
            if ($l === 'none' || $l === 'hidden') {
                $style = 'none';
            } elseif (in_array($l, ['solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset'], true)) {
                $style = $l;
            } elseif (in_array($l, ['thin', 'medium', 'thick'], true) || preg_match('/^[\d.]/', $l)) {
                $w = $l;
            } else {
                $color = $token;
            }
        }
        $style ??= $w !== null ? 'solid' : 'none';
        if ($style === 'none') {
            $w = '0';
        }
        return [$w ?? 'medium', $style, $color];
    }

    private function apply(array &$s, array $d, array $parent): void
    {
        if (isset($d['font-size'])) {
            $s['size'] = $this->fontSizeOf($d['font-size'], $parent['size']);
        }
        $fs = $s['size'];

        foreach ($d as $name => $value) {
            $l = strtolower($value);
            switch ($name) {
                case 'color':
                    $s['color'] = $this->color($value) ?? $s['color'];
                    break;
                case 'font-family':
                    $s['family'] = $this->family($value);
                    break;
                case 'font-weight':
                    $s['bold'] = $l === 'bold' || $l === 'bolder' || (ctype_digit($l) && (int) $l >= 600);
                    break;
                case 'font-style':
                    $s['italic'] = $l === 'italic' || $l === 'oblique';
                    break;
                case 'text-align':
                    if (in_array($l, ['left', 'right', 'center', 'justify'], true))
                        $s['align'] = $l;
                    break;
                case 'text-decoration':
                case 'text-decoration-line':
                    $s['under'] = str_contains($l, 'underline');
                    $s['strike'] = str_contains($l, 'line-through');
                    break;
                case 'text-transform':
                    $s['transform'] = $l;
                    break;
                case 'white-space':
                    $s['pre'] = str_starts_with($l, 'pre');
                    $s['wrap'] = !in_array($l, ['pre', 'nowrap'], true);
                    break;
                case 'line-height':
                    $s['lh'] = $this->lineHeight($l, $fs);
                    break;
                case 'list-style':
                case 'list-style-type':
                    foreach ($this->tokens($l) as $token) {
                        if (in_array($token, ['none', 'disc', 'circle', 'square', 'decimal', 'lower-alpha', 'upper-alpha', 'lower-latin', 'upper-latin'], true)) {
                            $s['list'] = $token;
                        }
                    }
                    break;
                case 'background-color':
                    $s['bg'] = $this->color($value);
                    break;
                case 'background-image':
                    $s['bgImg'] = $value;
                    break;
                case 'background-repeat':
                    $s['bgRepeat'] = $l;
                    break;
                case 'background-position':
                    $s['bgPos'] = $value;
                    break;
                case 'background-size':
                    $s['bgSize'] = $value;
                    break;
                case 'opacity':
                    $s['opacity'] = max(0.0, min(1.0, (float) $value));
                    break;
                case 'display':
                    $s['display'] = $this->displayOf($l);
                    break;
                case 'flex-direction':
                    $s['flex-direction'] = $l;
                    break;
                case 'justify-content':
                    $s['justify-content'] = $l;
                    break;
                case 'align-items':
                    $s['align-items'] = $l;
                    break;
                case 'gap':
                    $s['gap'] = $this->length($value, $fs) ?? 0.0;
                    break;
                case 'flex':
                    $s['flex'] = (float) $value;
                    break;
                case 'flex-grow':
                    $s['flex-grow'] = (float) $value;
                    break;
                case 'margin-top':
                    $s['margin'][0] = $this->length($value, $fs) ?? 0.0;
                    break;
                case 'margin-right':
                    $s['margin'][1] = $this->length($value, $fs) ?? 0.0;
                    $s['mAutoR'] = $l === 'auto';
                    break;
                case 'margin-bottom':
                    $s['margin'][2] = $this->length($value, $fs) ?? 0.0;
                    break;
                case 'margin-left':
                    $s['margin'][3] = $this->length($value, $fs) ?? 0.0;
                    $s['mAutoL'] = $l === 'auto';
                    break;
                case 'padding-top':
                    $s['padding'][0] = $this->length($value, $fs) ?? 0.0;
                    break;
                case 'padding-right':
                    $s['padding'][1] = $this->length($value, $fs) ?? 0.0;
                    break;
                case 'padding-bottom':
                    $s['padding'][2] = $this->length($value, $fs) ?? 0.0;
                    break;
                case 'padding-left':
                    $s['padding'][3] = $this->length($value, $fs) ?? 0.0;
                    break;
                case 'border-radius':
                    $s['radius'] = $this->length($this->tokens($value)[0] ?? '0', $fs) ?? 0.0;
                    break;
                case 'direction':
                    if (in_array($l, ['ltr', 'rtl'], true)) {
                        $s['direction'] = $l;
                    }
                    break;
                case 'letter-spacing':
                    $s['letter-spacing'] = (float) ($this->length($value, $fs) ?: 0.0);
                    break;
                case 'word-spacing':
                    $s['word-spacing'] = (float) ($this->length($value, $fs) ?: 0.0);
                    break;
                case 'word-break':
                case 'overflow-wrap':
                case 'word-wrap':
                    if (in_array($l, ['break-all', 'break-word', 'normal'], true)) {
                        $s['wordBreak'] = $l;
                    }
                    break;
                case 'width':
                    $s['width'] = $this->length($value, $fs);
                    break;
                case 'min-width':
                    $s['minWidth'] = $this->length($value, $fs);
                    break;
                case 'max-width':
                    $s['maxWidth'] = $this->length($value, $fs);
                    break;
                case 'height':
                    $s['height'] = $this->length($value, $fs);
                    break;
                case 'min-height':
                    $s['minHeight'] = $this->length($value, $fs);
                    break;
                case 'max-height':
                    $s['maxHeight'] = $this->length($value, $fs);
                    break;
                case 'box-sizing':
                    $s['sizing'] = $l === 'border-box' ? 'border' : 'content';
                    break;
                case 'position':
                    $s['position'] = match ($l) { 'absolute', 'fixed' => 'absolute', 'relative' => 'relative', default => 'static'};
                    break;
                case 'top':
                    $s['pos'][0] = $this->length($value, $fs);
                    break;
                case 'right':
                    $s['pos'][1] = $this->length($value, $fs);
                    break;
                case 'bottom':
                    $s['pos'][2] = $this->length($value, $fs);
                    break;
                case 'left':
                    $s['pos'][3] = $this->length($value, $fs);
                    break;
                case 'page-break-before':
                case 'break-before':
                    $s['breakBefore'] = in_array($l, ['always', 'page'], true);
                    break;
                case 'page-break-after':
                case 'break-after':
                    $s['breakAfter'] = in_array($l, ['always', 'page'], true);
                    break;
                case 'page-break-inside':
                case 'break-inside':
                    $s['breakInside'] = $l !== 'avoid';
                    break;
                case 'vertical-align':
                    if (in_array($l, ['top', 'middle', 'bottom'], true)) {
                        $s['valign'] = $l;
                    } elseif ($l === 'super') {
                        $s['shift'] = 0.35 * $parent['size'];
                    } elseif ($l === 'sub') {
                        $s['shift'] = -0.2 * $parent['size'];
                    }
                    break;
                case 'border-collapse':
                    $s['collapse'] = $l === 'collapse';
                    break;
                case 'border-spacing':
                    $len = $this->length($this->tokens($value)[0] ?? '0', $fs);
                    $s['spacing'] = is_float($len) ? $len : 0.0;
                    break;
            }
        }

        foreach (self::SIDES as $i => $side) {
            if (!isset($d["border-$side-width"]) && !isset($d["border-$side-style"]) && !isset($d["border-$side-color"])) {
                continue;
            }
            $style = strtolower($d["border-$side-style"] ?? 'none');
            $w = ($style === 'none' || $style === 'hidden') ? 0.0 : $this->borderWidth($d["border-$side-width"] ?? 'medium', $fs);
            $color = isset($d["border-$side-color"]) ? ($this->color($d["border-$side-color"]) ?? $s['color']) : $s['color'];
            $s['border'][$i] = ['w' => $w, 'c' => $color, 's' => $style];
        }
    }

    private function borderWidth(string $v, float $fs): float
    {
        return match (strtolower($v)) {
            'thin' => 1 * self::PX,
            'medium' => 3 * self::PX,
            'thick' => 5 * self::PX,
            default => (float) ($this->length($v, $fs) ?: 0.0),
        };
    }

    private function displayOf(string $v): string
    {
        return match ($v) {
            'none', 'inline', 'list-item', 'table', 'table-row', 'table-cell', 'table-row-group',
            'table-header-group', 'table-footer-group', 'flex', 'inline-block' => $v,
            default => 'block',
        };
    }

    private function family(string $v): string
    {
        return trim($v, "'\"");
    }

    private function lineHeight(string $v, float $fs): ?float
    {
        if ($v === 'normal' || $v === '')
            return null;
        if (is_numeric($v))
            return (float) $v;
        $len = $this->length($v, $fs);
        if (is_float($len))
            return $fs > 0 ? $len / $fs : 1.2;
        return is_string($len) ? (float) $len / 100 : 1.2;
    }

    private function fontSizeOf(string $v, float $parent): float
    {
        $v = strtolower(trim($v));
        if (isset(self::FONT_KEYWORDS[$v]))
            return self::FONT_KEYWORDS[$v] * self::PX;
        if ($v === 'smaller')
            return $parent * 0.83;
        if ($v === 'larger')
            return $parent * 1.2;
        $len = $this->length($v, $parent);
        return match (true) {
            is_float($len) => $len,
            is_string($len) => (float) $len / 100 * $parent,
            default => $parent,
        };
    }

    private function length(string $v, float $fs): float|string|null
    {
        $v = strtolower(trim($v));
        if (!preg_match('/^(-?[\d.]+)\s*(px|pt|mm|cm|in|em|rem|%)?$/', $v, $m)) {
            return null;
        }
        $n = (float) $m[1];
        return match ($m[2] ?? '') {
            'pt' => $n,
            'mm' => $n * self::MM,
            'cm' => $n * self::MM * 10,
            'in' => $n * 72,
            'em' => $n * $fs,
            'rem' => $n * $this->rootFontSize,
            '%' => $m[1] . '%',
            default => $n * self::PX,
        };
    }

    private function px(float|string|null $v, float $base): float
    {
        if (is_string($v)) {
            return (float) $v / 100 * $base;
        }
        return $v ?? 0.0;
    }

    private function edges(array $values, float $base): array
    {
        return array_map(fn($v) => $this->px($v, $base), $values);
    }

    private function color(string $v): ?array
    {
        $c = Color::parse($v);
        return $c === null ? null : [...$c->rgb, $c->alpha];
    }

    // ------------------------------------------------------- block layout

    private function settle(): void
    {
        if ($this->pending > 0) {
            $this->y += $this->pending;
            $this->pending = 0.0;
        }
        foreach ($this->frames as $i => $f) {
            if ($f['start'] === null) {
                $this->frames[$i]['start'] = $this->y;
            }
        }
    }

    private function renderChildren(DOMNode $parent, array $s, float $x, float $w): void
    {
        $items = [];
        $textStyle = ['bg' => null, 'radius' => 0.0, 'padding' => [0.0, 0.0, 0.0, 0.0], 'border' => self::RESET['border']] + $s;

        // If parent is flex container, dispatch to FlexEngine
        if ($s['display'] === 'flex') {
            $this->renderFlexContainer($parent, $s, $x, $w);
            return;
        }

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMText) {
                $this->collectText($node->nodeValue ?? '', $textStyle, $items);
                continue;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }
            $cs = $this->computeStyle($node, $s);
            if ($cs['display'] === 'none') {
                continue;
            }
            if ($cs['position'] === 'absolute') {
                $this->renderAbsolute($node, $cs, $x, $w);
                continue;
            }
            if (!in_array($cs['display'], ['inline', 'inline-block'], true)) {
                $this->flushLines($items, $x, $w, $s);
                $this->renderBlock($node, $cs, $x, $w);
                continue;
            }
            $this->collectInline($node, $cs, $items, $w);
        }
        $this->flushLines($items, $x, $w, $s);
    }

    private function renderFlexContainer(DOMNode $parent, array $s, float $x, float $w): void
    {
        $children = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMText && trim($node->nodeValue ?? '') !== '') {
                $anonymous = $parent->ownerDocument->createElement('span');
                $anonymous->appendChild($node->cloneNode());
                $node = $anonymous;
                $cs = array_intersect_key($s, array_flip(self::INHERITED)) + self::RESET;
                $cs['display'] = 'block';
            } elseif ($node instanceof DOMElement) {
                $cs = $this->computeStyle($node, $s);
            } else {
                continue;
            }
            if ($cs['display'] === 'none')
                continue;
            if ($cs['position'] === 'absolute') {
                $this->renderAbsolute($node, $cs, $x, $w);
                continue;
            }

            $naturalH = $this->measure(fn() => $this->renderElement($node, $cs, 0.0, $w));
            $naturalW = $cs['width'] !== null ? $this->outerWidth($cs, $w) : $this->maxContent(['el' => $node, 's' => $cs]);
            if ($cs['width'] === null && in_array($this->tag($node), ['img', 'svg'], true)) {
                $item = $this->tag($node) === 'img' ? $this->imageItem($node, $cs, $w) : $this->svgItem($node, $cs, $w);
                $naturalW = $item['w'] ?? 0.0;
            }
            if ($cs['width'] === null) {
                [, $pr, , $pl] = $this->edges($cs['padding'], $w);
                [, $mr, , $ml] = $this->edges($cs['margin'], $w);
                [, $br, , $bl] = array_column($cs['border'], 'w');
                $naturalW += $pl + $pr + $bl + $br + $ml + $mr;
            }

            $children[] = [
                'el' => $node,
                's' => $cs,
                'naturalW' => max(1.0, $naturalW),
                'naturalH' => max(1.0, $naturalH),
            ];
        }

        $layout = FlexEngine::layout($children, $x, $this->y + $this->pending, $w, $s);
        $childIndices = [];
        foreach ($children as $i => $child)
            $childIndices[spl_object_id($child['el'])] = $i;
        foreach ($layout['items'] as $item) {
            $i = $childIndices[spl_object_id($item['el'])];
            $childStyle = $item['s'];
            $childStyle['width'] = null;
            $children[$i]['naturalH'] = $this->measure(fn() => $this->renderElement($item['el'], $childStyle, 0.0, $item['w']));
        }
        $layout = FlexEngine::layout($children, $x, $this->y + $this->pending, $w, $s);
        if (
            $this->allowBreak && $layout['totalH'] <= $this->pageBottom - $this->pageTop
            && $this->y + $this->pending + $layout['totalH'] > $this->pageBottom && $this->y > $this->pageTop
        ) {
            $this->newPage();
            $layout = FlexEngine::layout($children, $x, $this->y, $w, $s);
        }
        $this->settle();

        foreach ($layout['items'] as $it) {
            $savedY = $this->y;
            $savedPending = $this->pending;
            $this->y = $it['y'];
            $this->pending = 0.0;
            $childStyle = $it['s'];
            $childStyle['width'] = null;
            if ($s['align-items'] === 'stretch' && !str_starts_with($s['flex-direction'], 'column')) {
                $childStyle['minHeight'] = $it['h'];
                $childStyle['sizing'] = 'border';
            }
            $allowBreak = $this->allowBreak;
            $this->allowBreak = false;
            $this->renderBlock($it['el'], $childStyle, $it['x'], $it['w']);
            $this->allowBreak = $allowBreak;
            $this->y = $savedY;
            $this->pending = $savedPending;
        }

        $this->y += $layout['totalH'];
    }

    private function renderBlock(DOMElement $el, array $s, float $x, float $w): void
    {
        if ($s['breakBefore']) {
            $this->forcePageBreak();
        }

        $tag = $this->tag($el);

        // Record anchor for internal page links
        if ($el->hasAttribute('id') && !$this->measuring) {
            $id = $el->getAttribute('id');
            $this->anchors[$id] = [
                'page' => count($this->pages),
                'y' => max(0.0, $this->pageH - ($this->y + $this->pending)),
            ];
        }

        // Automatic PDF bookmarks for h1-h6 headings
        if (preg_match('/^h([1-6])$/', $tag, $hm) && !$this->measuring) {
            $hText = trim($el->textContent);
            if ($hText !== '') {
                $this->addBookmark($hText, (int) $hm[1]);
            }
        }

        if ($tag === 'img') {
            $this->renderImageBlock($el, $s, $x, $w);
        } elseif ($tag === 'svg') {
            $this->renderSvgBlock($el, $s, $x, $w);
        } else {
            if (!$s['breakInside'] && $this->allowBreak) {
                $height = $this->measure(fn() => $this->renderElement($el, $s, $x, $w));
                if ($this->y + $this->pending + $height > $this->pageBottom && $this->y > $this->pageTop + 0.01) {
                    $this->newPage();
                }
            }
            $this->renderElement($el, $s, $x, $w);
        }

        if ($s['breakAfter']) {
            $this->forcePageBreak();
        }
    }

    private function renderElement(DOMElement $el, array $s, float $x, float $w): void
    {
        if ($this->tag($el) === 'img') {
            $this->renderImageBlock($el, $s, $x, $w);
        } elseif ($this->tag($el) === 'svg') {
            $this->renderSvgBlock($el, $s, $x, $w);
        } elseif ($s['display'] === 'table' && $this->tag($el) === 'table') {
            $this->renderTable($el, $s, $x, $w);
        } else {
            $this->renderBox($el, $s, $x, $w);
        }
    }

    private function renderBox(DOMElement $el, array $s, float $x, float $w, ?callable $inner = null): void
    {
        [$mt, $mr, $mb, $ml] = $this->edges($s['margin'], $w);
        [$pt, $pr, $pb, $pl] = $this->edges($s['padding'], $w);
        [$bt, $br, $bb, $bl] = array_column($s['border'], 'w');
        $extra = $pl + $pr + $bl + $br;

        if ($s['width'] !== null) {
            $cw = max(0.0, $this->px($s['width'], $w) - ($s['sizing'] === 'border' ? $extra : 0.0));
            $free = $w - ($cw + $extra + $ml + $mr);
            if ($s['mAutoL'] && $s['mAutoR']) {
                $ml = $mr = max(0.0, $free / 2);
            } elseif ($s['mAutoL']) {
                $ml = max(0.0, $free);
            }
        } else {
            $cw = max(0.0, $w - $ml - $mr - $extra);
        }

        if ($s['minWidth'] !== null) {
            $cw = max($cw, $this->px($s['minWidth'], $w) - ($s['sizing'] === 'border' ? $extra : 0.0));
        }
        if ($s['maxWidth'] !== null) {
            $cw = max(0.0, min($cw, $this->px($s['maxWidth'], $w) - ($s['sizing'] === 'border' ? $extra : 0.0)));
        }
        if ($s['mAutoL'] || $s['mAutoR']) {
            $free = max(0.0, $w - $cw - $extra - ($s['mAutoL'] ? 0 : $ml) - ($s['mAutoR'] ? 0 : $mr));
            if ($s['mAutoL'] && $s['mAutoR']) {
                $ml = $mr = $free / 2;
            } elseif ($s['mAutoL']) {
                $ml = $free;
            } else {
                $mr = $free;
            }
        }

        $vertical = $pt + $pb + $bt + $bb;
        $hb = is_float($s['height']) ? $s['height'] + ($s['sizing'] === 'border' ? 0.0 : $vertical) : null;
        $minHb = is_float($s['minHeight']) ? $s['minHeight'] + ($s['sizing'] === 'border' ? 0.0 : $vertical) : null;

        $this->pending = max($this->pending, $mt);
        $i = count($this->frames);
        $this->frames[] = [
            's' => $s,
            'x' => $x + $ml,
            'w' => $cw + $extra,
            'start' => null,
            'op' => count($this->ops),
            'first' => true,
            'hb' => $hb,
        ];
        if ($pt + $bt > 0) {
            $this->settle();
            $this->y += $bt + $pt;
        }

        $tag = $this->tag($el);
        $isList = $tag === 'ul' || $tag === 'ol';
        if ($isList) {
            $this->listStack[] = ['n' => (int) ($el->getAttribute('start') ?: 1) - 1];
        }
        if ($s['display'] === 'list-item') {
            $this->prepareMarker($s);
        }

        $cx = $x + $ml + $bl + $pl;
        if ($inner !== null) {
            $inner($cx, $cw);
        } else {
            $this->renderChildren($el, $s, $cx, $cw);
        }

        $this->marker = null;
        if ($isList) {
            array_pop($this->listStack);
        }

        if ($pb + $bb > 0 || $hb !== null || $minHb !== null) {
            $this->settle();
        }
        $this->y += $pb + $bb;

        $f = $this->frames[$i];
        if ($f['start'] !== null) {
            $target = max($hb ?? 0.0, $minHb ?? 0.0);
            if ($hb !== null) {
                $this->y = $f['start'] + $hb;
            } elseif ($target > 0 && $this->y < $f['start'] + $target) {
                $this->y = $f['start'] + $target;
            }
            $this->paintFrame($f, $this->y, true);
        }
        array_pop($this->frames);
        $this->pending = max($this->pending, $mb);
    }

    private function prepareMarker(array $s): void
    {
        $last = array_key_last($this->listStack);
        $n = 0;
        if ($last !== null) {
            $n = ++$this->listStack[$last]['n'];
        }
        $text = match ($s['list']) {
            'none' => '',
            'decimal' => "$n.",
            'lower-alpha', 'lower-latin' => chr(96 + ($n - 1) % 26 + 1) . '.',
            'upper-alpha', 'upper-latin' => chr(64 + ($n - 1) % 26 + 1) . '.',
            'circle' => 'o',
            'square' => '-',
            default => "\u{2022}",
        };
        $this->marker = $text === '' ? null : ['text' => $text, 's' => ['bg' => null] + $s];
    }

    private function paintFrame(array $f, float $endY, bool $last): void
    {
        $h = $endY - $f['start'];
        if ($h <= 0) {
            return;
        }

        $ops = $this->boxOps($f['x'], $f['start'], $f['w'], $h, $f['s'], [$f['first'], true, $last, true]);

        // Background Image if present
        if ($f['s']['bgImg'] !== null) {
            $img = $this->loadImage($f['s']['bgImg']);
            if ($img !== null) {
                $bg = new BackgroundImage(
                    $img['name'],
                    $img['w'] * self::PX,
                    $img['h'] * self::PX,
                    $f['s']['bgRepeat'],
                    $f['s']['bgPos'],
                    $f['s']['bgSize'],
                    $f['s']['opacity']
                );
                $radius = is_string($f['s']['radius']) ? (float) $f['s']['radius'] / 100 * min($f['w'], $h) : $f['s']['radius'];
                $fill = ['border' => self::RESET['border']] + $f['s'];
                $border = ['bg' => null] + $f['s'];
                $ops = $this->boxOps($f['x'], $f['start'], $f['w'], $h, $fill)
                    . $bg->render($f['x'], $f['start'], $f['w'], $h, $radius, $this->pageH, $this->graphicState)
                    . $this->boxOps($f['x'], $f['start'], $f['w'], $h, $border, [$f['first'], true, $last, true]);
            }
        }

        if ($ops !== '') {
            array_splice($this->ops, $f['op'], 0, [$ops]);
        }
    }

    private function measure(callable $fn): float
    {
        $saved = [$this->ops, $this->y, $this->pending, $this->frames, $this->allowBreak, $this->measuring, $this->marker, $this->listStack, $this->pageAnnots];
        $this->ops = [];
        $this->y = 0.0;
        $this->pending = 0.0;
        $this->frames = [];
        $this->allowBreak = false;
        $this->measuring = true;
        $this->marker = null;
        $this->pageAnnots = [];

        try {
            $fn();
            return $this->y + $this->pending;
        } finally {
            [$this->ops, $this->y, $this->pending, $this->frames, $this->allowBreak, $this->measuring, $this->marker, $this->listStack, $this->pageAnnots] = $saved;
        }
    }

    // ------------------------------------------------- absolute positioning

    private function renderAbsolute(DOMElement $el, array $s, float $x, float $w): void
    {
        if ($this->measuring)
            return;
        $cb = $this->containingBlock();
        $resolve = fn(float|string|null $v, float $base): ?float => $v === null ? null : $this->px($v, $base);
        $top = $resolve($s['pos'][0], $cb['h'] ?? 0.0);
        $right = $resolve($s['pos'][1], $cb['w']);
        $bottom = $resolve($s['pos'][2], $cb['h'] ?? 0.0);
        $left = $resolve($s['pos'][3], $cb['w']);

        $s2 = $s;
        $s2['margin'] = [0.0, 0.0, 0.0, 0.0];
        $s2['mAutoL'] = $s2['mAutoR'] = false;
        $isImage = $this->tag($el) === 'img';

        $bx = $cb['x'];
        $pw = $cb['w'];
        if ($left !== null) {
            $bx = $cb['x'] + $left;
            $pw = $right !== null && $s['width'] === null ? $cb['w'] - $left - $right : $cb['w'] - $left;
        } elseif ($right !== null) {
            $pw = $cb['w'] - $right;
            if ($s['width'] !== null) {
                $outer = $this->outerWidth($s2, $cb['w']);
                $bx = $cb['x'] + $cb['w'] - $right - $outer;
                $pw = $outer;
            }
        } else {
            $bx = $x;
            $pw = $w;
        }
        if ($s['width'] !== null) {
            $pw = max($pw, $this->outerWidth($s2, $cb['w']));
        }

        $item = $isImage ? $this->imageItem($el, $s, $pw) : null;
        if ($isImage && $item === null)
            return;

        if ($top !== null) {
            $by = $cb['y'] + $top;
        } elseif ($bottom !== null && $cb['h'] !== null) {
            $height = $item !== null ? $item['h'] : $this->measure(fn() => $this->renderElement($el, $s2, $bx, $pw));
            $by = $cb['y'] + $cb['h'] - $bottom - $height;
        } else {
            $by = $this->y + $this->pending;
        }

        if ($item !== null) {
            $this->ops[] = $this->imageOp($item, $bx, $by);
            return;
        }

        $saved = [$this->y, $this->pending, $this->allowBreak, $this->frames, $this->marker];
        $this->frames = [];
        $this->y = $by;
        $this->pending = 0.0;
        $this->allowBreak = false;
        $this->renderElement($el, $s2, $bx, $pw);
        [$this->y, $this->pending, $this->allowBreak, $this->frames, $this->marker] = $saved;
    }

    private function outerWidth(array $s, float $base): float
    {
        $width = $this->px($s['width'], $base);
        if ($s['sizing'] === 'border')
            return $width;
        [, $pr, , $pl] = $this->edges($s['padding'], $base);
        [, $br, , $bl] = array_column($s['border'], 'w');
        return $width + $pl + $pr + $bl + $br;
    }

    private function containingBlock(): array
    {
        for ($i = count($this->frames) - 1; $i >= 0; $i--) {
            $f = $this->frames[$i];
            if ($f['s']['position'] !== 'static') {
                return ['x' => $f['x'], 'y' => $f['start'] ?? ($this->y + $this->pending), 'w' => $f['w'], 'h' => $f['hb']];
            }
        }
        return [
            'x' => $this->margin[3],
            'y' => $this->pageTop,
            'w' => $this->pageW - $this->margin[1] - $this->margin[3],
            'h' => $this->pageBottom - $this->pageTop,
        ];
    }

    // ------------------------------------------------------------ tables

    private function renderTable(DOMElement $el, array $s, float $x, float $w): void
    {
        $gridData = TableEngine::buildGrid($el, $s, fn(DOMElement $elem, array $parent) => $this->computeStyle($elem, $parent));
        $rows = $gridData['rows'];
        $columns = $gridData['colCount'];
        $head = $gridData['headRows'];

        if (!$rows || $columns === 0)
            return;

        $spacing = $s['collapse'] ? 0.0 : (float) $s['spacing'];
        [, $mr, , $ml] = $this->edges($s['margin'], $w);
        [, $pr, , $pl] = $this->edges($s['padding'], $w);
        [, $br, , $bl] = array_column($s['border'], 'w');
        $extra = $pl + $pr + $bl + $br;
        $available = $w - $ml - $mr - $extra;
        $fixed = $s['width'] !== null ? max(0.0, $this->px($s['width'], $w) - $extra) : null;

        $widths = $this->columnWidths($rows, $columns, $spacing, $fixed, $available);
        $s['width'] = array_sum($widths) + $spacing * ($columns + 1);
        $s['sizing'] = 'content';
        $grid = ['cols' => $widths, 'spacing' => $spacing, 'collapse' => $s['collapse'], 'head' => $head];

        $this->renderBox($el, $s, $x, $w, function (float $cx) use ($rows, $grid, $spacing): void {
            $this->settle();
            $this->y += $spacing;
            $grid['x'] = $cx;
            $naturalHeights = [];
            $cellHeights = [];
            foreach ($rows as $i => &$row) {
                $measured = $this->measureRow($row, $grid);
                $row['layout'] = $measured['cells'];
                $row['index'] = $i;
                $naturalHeights[$i] = $measured['height'];
                foreach ($row['cells'] as &$cell)
                    $cell['row'] = $i;
                unset($cell);
                foreach ($measured['cells'] as $cell) {
                    $cellHeights[$i . '_' . $cell['col']] = $cell['h'] - $spacing * ($cell['rowspan'] - 1);
                }
            }
            unset($row);
            $grid['heights'] = TableEngine::distributeRowHeights($rows, $naturalHeights, $cellHeights);
            $grid['head'] = array_values(array_filter($rows, static fn($row) => $row['head']));
            for ($i = 0, $count = count($rows); $i < $count; ) {
                $end = $i + 1;
                for ($j = $i; $j < $end && $j < $count; $j++) {
                    foreach ($rows[$j]['cells'] as $cell)
                        $end = max($end, $j + $cell['rowspan']);
                }
                $end = min($count, $end);
                $rows[$i]['groupHeight'] = array_sum(array_slice($grid['heights'], $i, $end - $i)) + $spacing * ($end - $i - 1);
                for ($j = $i; $j < $end; $j++) {
                    $this->drawRow($rows[$j], $grid, $j > $i);
                }
                $i = $end;
            }
        });
    }

    private function columnWidths(array $rows, int $n, float $spacing, ?float $fixedWidth, float $available): array
    {
        $spec = array_fill(0, $n, null);
        $max = array_fill(0, $n, 0.0);
        $min = array_fill(0, $n, 0.0);
        $spans = [];

        foreach ($rows as $row) {
            foreach ($row['cells'] as $cell) {
                $c = $cell['col'];
                $span = $cell['colspan'];
                $cs = $cell['s'];
                [, $pr, , $pl] = $this->edges($cs['padding'], 0.0);
                [, $br, , $bl] = array_column($cs['border'], 'w');
                $extra = $pl + $pr + $bl + $br;
                $content = $this->maxContent($cell) + $extra;

                if ($span === 1) {
                    $max[$c] = max($max[$c], $content);
                    $min[$c] = max($min[$c], $this->minContent($cell['el'], $cs) + $extra);
                    if (is_string($cs['width'])) {
                        $spec[$c] = ['pct' => (float) $cs['width']];
                    } elseif (is_float($cs['width'])) {
                        $abs = $cs['width'] + ($cs['sizing'] === 'border' ? 0.0 : $extra);
                        $spec[$c] = ['abs' => max($abs, $spec[$c]['abs'] ?? 0.0)];
                    }
                } else {
                    $spans[] = [$c, $span, $content];
                }
            }
        }

        foreach ($spans as [$c, $span, $content]) {
            $have = array_sum(array_slice($max, $c, $span));
            if ($have < $content) {
                for ($k = $c; $k < min($n, $c + $span); $k++) {
                    $max[$k] += ($content - $have) / $span;
                }
            }
        }

        $inner = ($fixedWidth ?? $available) - $spacing * ($n + 1);
        $base = $fixedWidth ?? $available;
        $fixed = [];
        $auto = [];
        foreach ($spec as $c => $sp) {
            if ($sp === null) {
                $auto[] = $c;
            } else {
                $fixed[$c] = isset($sp['pct']) ? $sp['pct'] / 100 * $base : $sp['abs'];
            }
        }
        $fixedSum = array_sum($fixed);
        $autoMax = array_sum(array_map(static fn($c) => $max[$c], $auto));
        $target = $fixedWidth !== null ? $inner : min($inner, $fixedSum + $autoMax);

        $widths = [];
        if ($fixedSum > $target && $fixedSum > 0) {
            foreach ($fixed as $c => $v) {
                $fixed[$c] = $v * $target / $fixedSum;
            }
            $fixedSum = $target;
        }
        if (!$auto) {
            $scale = $fixedSum > 0 && ($fixedWidth !== null || $fixedSum > $inner) ? $target / $fixedSum : 1.0;
            foreach ($fixed as $c => $v) {
                $widths[$c] = $v * $scale;
            }
        } else {
            $remaining = max(0.0, $target - $fixedSum);
            $minimum = array_sum(array_map(static fn($c) => $min[$c], $auto));
            $growth = max(0.0, $autoMax - $minimum);
            foreach ($fixed as $c => $v) {
                $widths[$c] = $v;
            }
            foreach ($auto as $c) {
                if ($minimum > $remaining && $minimum > 0) {
                    $widths[$c] = $min[$c] * $remaining / $minimum;
                } else {
                    $share = $growth > 0 ? ($max[$c] - $min[$c]) / $growth : 1 / count($auto);
                    $widths[$c] = $min[$c] + max(0.0, $remaining - $minimum) * $share;
                }
            }
        }
        ksort($widths);
        return array_map(static fn($v) => max(2.0, (float) $v), array_values($widths));
    }

    private function minContent(DOMElement $element, array $style): float
    {
        if (!$style['wrap'])
            return $this->maxContent(['el' => $element, 's' => $style]);
        $width = 0.0;
        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMText) {
                $items = [];
                $this->collectText($node->nodeValue ?? '', $style, $items);
                foreach ($items as $item)
                    $width = max($width, $item['w']);
            } elseif ($node instanceof DOMElement) {
                $childStyle = $this->computeStyle($node, $style);
                if ($childStyle['display'] !== 'none') {
                    $width = max($width, $this->minContent($node, $childStyle));
                }
            }
        }
        return $width;
    }

    private function maxContent(array $cell): float
    {
        $saved = $this->extent;
        $this->extent = 0.0;
        $this->measure(fn() => $this->renderChildren($cell['el'], $cell['s'], 0.0, 3000.0));
        $width = $this->extent;
        $this->extent = $saved;
        return $width;
    }

    private function measureRow(array $row, array $grid): array
    {
        $spacing = $grid['spacing'];
        $cells = [];

        foreach ($row['cells'] as $cell) {
            $cs = $cell['s'];
            $col = $cell['col'];
            $span = $cell['colspan'];
            $cellW = array_sum(array_slice($grid['cols'], $col, $span)) + $spacing * ($span - 1);
            $cellX = $grid['x'] + $spacing + array_sum(array_slice($grid['cols'], 0, $col)) + $spacing * $col;
            [$pt, $pr, $pb, $pl] = $this->edges($cs['padding'], $cellW);
            [$bt, $br, $bb, $bl] = array_column($cs['border'], 'w');
            $innerW = max(1.0, $cellW - $pl - $pr - $bl - $br);
            $height = $this->measure(fn() => $this->renderChildren($cell['el'], $cs, 0.0, $innerW)) + $pt + $pb + $bt + $bb;
            if (is_float($cs['height'])) {
                $height = max($height, $cs['height'] + ($cs['sizing'] === 'border' ? 0.0 : $pt + $pb + $bt + $bb));
            }
            $cells[] = [
                'el' => $cell['el'],
                's' => $cs,
                'x' => $cellX,
                'w' => $cellW,
                'h' => $height,
                'innerW' => $innerW,
                'col' => $col,
                'dx' => $bl + $pl,
                'dy' => $bt + $pt,
                'rowspan' => $cell['rowspan'] ?? 1,
            ];
        }

        $rowH = $cells ? max(array_map(static fn($cell) => $cell['rowspan'] === 1 ? $cell['h'] : 0.0, $cells)) : 0.0;
        if (is_float($row['s']['height'])) {
            $rowH = max($rowH, $row['s']['height']);
        }

        return ['cells' => $cells, 'height' => $rowH];
    }

    private function drawRow(array $row, array $grid, bool $repeat): void
    {
        $spacing = $grid['spacing'];
        $cells = $row['layout'];
        $rowH = $grid['heights'][$row['index']];
        $needed = $row['groupHeight'] ?? $rowH;

        if (!$repeat && $this->allowBreak && $this->y + $needed > $this->pageBottom && $this->y > $this->pageTop + 0.01) {
            $this->newPage();
            if (!$row['head']) {
                foreach ($grid['head'] as $headRow) {
                    $this->drawRow($headRow, $grid, true);
                }
            }
        }

        $top = $this->y;
        $rowX = $grid['x'] + $spacing;
        $rowW = array_sum($grid['cols']) + $spacing * (count($grid['cols']) - 1);
        $this->ops[] = $this->boxOps($rowX, $top, $rowW, $rowH, ['bg' => null] + $row['s'], [true, true, true, true], $grid['collapse']);

        foreach ($cells as $c) {
            $cellSpanH = array_sum(array_slice($grid['heights'], $row['index'], $c['rowspan'])) + $spacing * ($c['rowspan'] - 1);
            $c['s']['bg'] ??= $row['s']['bg'];
            $this->ops[] = $this->boxOps($c['x'], $top, $c['w'], $cellSpanH, $c['s'], [true, true, true, true], $grid['collapse']);
            $valign = $c['s']['valign'] ?? 'middle';
            $offset = match ($valign) {
                'top' => 0.0,
                'bottom' => $cellSpanH - $c['h'],
                default => ($cellSpanH - $c['h']) / 2,
            };
            $this->y = $top + $c['dy'] + $offset;
            $this->pending = 0.0;
            $allow = $this->allowBreak;
            $this->allowBreak = false;
            $this->renderChildren($c['el'], $c['s'], $c['x'] + $c['dx'], $c['innerW']);
            $this->allowBreak = $allow;
        }

        $this->y = $top + $rowH + $spacing;
        $this->pending = 0.0;
    }

    // ------------------------------------------------------ inline layout

    private function collectInline(DOMElement $el, array $s, array &$items, float $w): void
    {
        $tag = $this->tag($el);
        if ($tag === 'br') {
            $items[] = ['t' => 'br', 'w' => 0.0, 's' => $s, 'k' => 'br'];
            return;
        }
        if ($tag === 'img') {
            if ($item = $this->imageItem($el, $s, $w)) {
                $items[] = $item;
            }
            return;
        }
        if ($tag === 'svg') {
            if ($item = $this->svgItem($el, $s, $w)) {
                $items[] = $item;
            }
            return;
        }

        $isLink = $tag === 'a' && $el->hasAttribute('href');
        if ($isLink) {
            $this->activeLinks[] = $el->getAttribute('href');
        }

        // Inline box styling check (badge / pill: background, padding, or border on inline)
        $hasBadgeStyle = $s['bg'] !== null || $s['radius'] > 0 || array_sum($this->edges($s['padding'], $w)) > 0
            || array_sum(array_column($s['border'], 'w')) > 0 || $s['display'] === 'inline-block';

        if ($hasBadgeStyle) {
            [, $pr, , $pl] = $this->edges($s['padding'], $w);
            [, $br, , $bl] = array_column($s['border'], 'w');
            [, $mr, , $ml] = $this->edges($s['margin'], $w);
            $extra = $pl + $pr + $bl + $br;
            if ($s['width'] === null) {
                $s['width'] = min(max(0.0, $w - $extra - $ml - $mr), $this->maxContent(['el' => $el, 's' => $s]));
                $s['sizing'] = 'content';
            } else {
                $s['width'] = $this->px($s['width'], $w);
            }
            $finalW = $s['width'] + ($s['sizing'] === 'border' ? 0.0 : $extra) + $ml + $mr;
            $finalH = $this->measure(fn() => $this->renderBox($el, $s, 0.0, $finalW));
            $font = $this->fontManager->getFont($s['family'], $s['bold'], $s['italic']);
            $lineHeight = $s['lh'] === null ? $font->getLineHeight($s['size']) : $s['size'] * $s['lh'];
            $leading = ($lineHeight - $font->getAscender($s['size']) + $font->getDescender($s['size'])) / 2;
            $baseline = $leading - $font->getDescender($s['size'])
                + $this->px($s['padding'][2], $w) + $s['border'][2]['w'] + $this->px($s['margin'][2], $w);
            $items[] = [
                't' => 'inline-box',
                'el' => $el,
                's' => $s,
                'w' => $finalW,
                'h' => $finalH,
                'baseline' => $baseline,
                'link' => $this->activeLinks ? end($this->activeLinks) : null,
                'k' => 'ib_' . spl_object_id($el),
            ];
            if ($isLink)
                array_pop($this->activeLinks);
            return;
        }

        foreach ($el->childNodes as $node) {
            if ($node instanceof DOMText) {
                $this->collectText($node->nodeValue ?? '', $s, $items);
            } elseif ($node instanceof DOMElement) {
                $cs = $this->computeStyle($node, $s);
                if ($cs['display'] === 'none')
                    continue;
                $this->collectInline($node, $cs, $items, $w);
                if (!in_array($cs['display'], ['inline', 'inline-block'], true)) {
                    $items[] = ['t' => 'br', 'w' => 0.0, 's' => $s, 'k' => 'br'];
                }
            }
        }

        if ($isLink) {
            array_pop($this->activeLinks);
        }
    }

    private function collectText(string $text, array $s, array &$items): void
    {
        if ($s['pre']) {
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
            foreach ($lines as $i => $line) {
                if ($i > 0)
                    $items[] = ['t' => 'br', 'w' => 0.0, 's' => $s, 'k' => 'br'];
                if ($line !== '') {
                    $items[] = $this->textItem($this->transform(str_replace("\t", '    ', $line), $s), $s, 'w');
                }
            }
            return;
        }

        $text = preg_replace('/[ \t\r\n\f]+/', ' ', $text) ?? $text;
        if ($text === '')
            return;
        $text = $this->transform($text, $s);

        // Check if text has CJK characters (Chinese, Japanese, Korean)
        $hasCjk = (bool) preg_match('/[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text);
        if ($hasCjk) {
            $tokens = preg_split('/( )|([\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $token) {
                if ($token === ' ') {
                    $items[] = $this->textItem(' ', $s, 'sp');
                } else {
                    $items[] = $this->textItem($token, $s, 'w');
                }
            }
            return;
        }

        foreach (preg_split('/( )/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $items[] = $part === ' ' ? $this->textItem(' ', $s, 'sp') : $this->textItem($part, $s, 'w');
        }
    }

    private function transform(string $text, array $s): string
    {
        return match ($s['transform']) {
            'uppercase' => mb_strtoupper($text),
            'lowercase' => mb_strtolower($text),
            'capitalize' => mb_convert_case($text, MB_CASE_TITLE),
            default => $text,
        };
    }

    private function textItem(string $text, array $s, string $type): array
    {
        $font = $this->fontManager->getFont($s['family'], $s['bold'], $s['italic'], $text);
        $letterSpacing = (float) ($s['letter-spacing'] ?? 0.0);
        $charCount = $font instanceof \Spark\Pdf\Font\TrueTypeFont ? count($font->shapeText($text)) : mb_strlen($text);
        $w = $font->getTextWidth($text, $s['size']) + $letterSpacing * $charCount
            + substr_count($text, ' ') * (float) $s['word-spacing'];
        $link = !empty($this->activeLinks) ? end($this->activeLinks) : null;

        $key = implode('|', [
            spl_object_id($font),
            $s['size'],
            implode(',', $s['color']),
            (int) $s['under'],
            (int) $s['strike'],
            $s['bg'] === null ? '' : implode(',', $s['bg']),
            $s['shift'],
            $link ?? '',
            $letterSpacing,
            $s['word-spacing'] ?? 0.0,
            $s['opacity'],
            $s['direction'],
            $s['radius'],
            implode(',', $s['padding']),
        ]);
        return ['t' => $type, 'x' => $text, 'w' => $w, 's' => $s, 'k' => $key, 'link' => $link];
    }

    private function flushLines(array &$items, float $x, float $w, array $s, ?string $align = null): void
    {
        while ($items && $items[0]['t'] === 'sp')
            array_shift($items);
        while ($items && $items[array_key_last($items)]['t'] === 'sp')
            array_pop($items);
        if (!$items)
            return;
        $this->settle();
        $lines = $this->breakLines($items, $w);
        $items = [];
        $align ??= $s['align'];
        $last = array_key_last($lines);
        foreach ($lines as $i => $line) {
            $this->drawLine($line, $x, $w, $align, !$line['hard'] && $i !== $last);
        }
    }

    private function breakLines(array $items, float $w): array
    {
        $lines = [];
        $cur = [];
        $curW = 0.0;
        $push = function (bool $hard) use (&$lines, &$cur, &$curW): void {
            while ($cur && $cur[array_key_last($cur)]['t'] === 'sp') {
                $curW -= array_pop($cur)['w'];
            }
            $lines[] = ['items' => $cur, 'w' => $curW, 'hard' => $hard];
            $cur = [];
            $curW = 0.0;
        };

        foreach ($items as $it) {
            if ($it['t'] === 'br') {
                $cur[] = $it;
                $push(true);
                continue;
            }
            if ($it['t'] === 'sp') {
                if ($cur && $cur[array_key_last($cur)]['t'] !== 'sp') {
                    $cur[] = $it;
                    $curW += $it['w'];
                }
                continue;
            }
            $isBreakAll = ($it['s']['wordBreak'] ?? 'normal') === 'break-all';
            if ($it['s']['wrap'] && $cur && $curW + $it['w'] > $w + 0.01) {
                if ($isBreakAll && $it['t'] === 'w') {
                    $avail = max(0.0, $w - $curW);
                    $pieces = $this->splitWord($it, $avail > 5.0 ? $avail : $w);
                    if ($pieces) {
                        $first = array_shift($pieces);
                        $cur[] = $first;
                        $curW += $first['w'];
                        $push(false);
                        foreach ($pieces as $piece) {
                            if ($cur && $curW + $piece['w'] > $w + 0.01) {
                                $push(false);
                            }
                            $cur[] = $piece;
                            $curW += $piece['w'];
                        }
                        continue;
                    }
                }
                $push(false);
            }
            if ($it['s']['wrap'] && $it['t'] === 'w' && $it['w'] > $w + 0.01) {
                foreach ($this->splitWord($it, $w) as $piece) {
                    if ($cur && $curW + $piece['w'] > $w + 0.01) {
                        $push(false);
                    }
                    $cur[] = $piece;
                    $curW += $piece['w'];
                }
                continue;
            }
            $cur[] = $it;
            $curW += $it['w'];
        }
        if ($cur) {
            $push(true);
        }
        return $lines;
    }

    private function splitWord(array $item, float $w): array
    {
        $pieces = [];
        $buffer = '';
        $font = $this->fontManager->getFont($item['s']['family'], $item['s']['bold'], $item['s']['italic'], $item['x']);

        preg_match_all('/\X/u', $item['x'], $clusters);
        $syllables = [];
        foreach ($clusters[0] as $cluster) {
            $last = array_key_last($syllables);
            if ($last !== null && preg_match('/[\x{094D}\x{09CD}]\x{200D}?$/u', $syllables[$last])) {
                $syllables[$last] .= $cluster;
            } else {
                $syllables[] = $cluster;
            }
        }
        foreach ($syllables as $char) {
            if ($buffer !== '' && $this->textItem($buffer . $char, $item['s'], 'w')['w'] > $w) {
                $pieces[] = $this->textItem($buffer, $item['s'], 'w');
                $buffer = '';
            }
            $buffer .= $char;
        }
        if ($buffer !== '') {
            $pieces[] = $this->textItem($buffer, $item['s'], 'w');
        }
        return $pieces;
    }

    private function drawLine(array $line, float $x, float $w, string $align, bool $justify): void
    {
        $ascent = 0.0;
        $descent = 0.0;
        foreach ($line['items'] as $it) {
            if ($it['t'] === 'img' || $it['t'] === 'svg' || $it['t'] === 'inline-box') {
                $ascent = max($ascent, $it['h'] - ($it['baseline'] ?? 0.0));
                $descent = max($descent, $it['baseline'] ?? 0.0);
                continue;
            }
            $size = $it['s']['size'];
            $font = $this->fontManager->getFont($it['s']['family'], $it['s']['bold'], $it['s']['italic'], $it['x'] ?? '');
            $fontAscent = $font->getAscender($size);
            $fontDescent = -$font->getDescender($size);
            $lineHeight = $it['s']['lh'] === null ? $font->getLineHeight($size) : $size * $it['s']['lh'];
            $half = ($lineHeight - $fontAscent - $fontDescent) / 2;
            $ascent = max($ascent, $half + $fontAscent + $it['s']['shift']);
            $descent = max($descent, $half + $fontDescent - $it['s']['shift']);
        }
        $height = $ascent + $descent;

        if ($this->allowBreak && $this->y + $height > $this->pageBottom + 0.01 && $this->y > $this->pageTop + 0.01) {
            $this->newPage();
        }
        $base = $this->y + $ascent;
        $this->extent = max($this->extent, $x + $line['w']);

        if ($this->marker !== null) {
            $m = $this->marker;
            $this->marker = null;
            $mFont = $this->fontManager->getFont($m['s']['family'], $m['s']['bold'], $m['s']['italic'], $m['text']);
            $mx = $x - $mFont->getTextWidth($m['text'], $m['s']['size']) - 0.4 * $m['s']['size'];
            $this->ops[] = $this->textOp($m['text'], $mx, $base, $m['s']);
        }

        $free = max(0.0, $w - $line['w']);
        $spaces = count(array_filter($line['items'], static fn($i) => $i['t'] === 'sp'));
        $extra = ($justify && $align === 'justify' && $spaces > 0) ? $free / $spaces : 0.0;
        $cx = $x + match ($align) {
            'center' => $free / 2,
            'right' => $free,
            default => 0.0,
        };

        $run = null;
        foreach ($line['items'] as $it) {
            if ($it['t'] === 'br')
                continue;
            $itemW = $it['w'] + ($it['t'] === 'sp' ? $extra : 0.0);

            if ($it['t'] === 'img') {
                $this->flushRun($run, $base);
                $run = null;
                $this->ops[] = $this->imageOp($it, $cx, $base - $it['h']);
            } elseif ($it['t'] === 'svg') {
                $this->flushRun($run, $base);
                $run = null;
                $svg = SvgRenderer::render($it['el'], $cx, $base - $it['h'], $it['w'], $it['h'], $this->pageH);
                $this->ops[] = $svg['ops'];
            } elseif ($it['t'] === 'inline-box') {
                $this->flushRun($run, $base);
                $run = null;
                $saved = [$this->y, $this->pending, $this->allowBreak];
                $this->y = $base - $it['h'] + $it['baseline'];
                $this->pending = 0.0;
                $this->allowBreak = false;
                if ($it['link'] !== null)
                    $this->activeLinks[] = $it['link'];
                try {
                    $this->renderBox($it['el'], $it['s'], $cx, $it['w']);
                } finally {
                    if ($it['link'] !== null)
                        array_pop($this->activeLinks);
                    [$this->y, $this->pending, $this->allowBreak] = $saved;
                }
            } elseif ($run !== null && $run['k'] === $it['k']) {
                $run['text'] .= $it['x'];
                $run['w'] += $itemW;
            } else {
                $this->flushRun($run, $base);
                $run = ['k' => $it['k'], 's' => $it['s'], 'text' => $it['x'], 'x' => $cx, 'w' => $itemW, 'extra' => $extra, 'link' => $it['link'] ?? null];
            }
            $cx += $itemW;
        }
        $this->flushRun($run, $base);
        $this->y += $height;
    }

    private function flushRun(?array $run, float $base): void
    {
        if ($run === null)
            return;
        $s = $run['s'];
        $size = $s['size'];
        $b = $base - $s['shift'];
        $out = '';

        // Scoped opacity ExtGState
        $hasAlpha = (($s['opacity'] ?? 1.0) < 0.999);
        if ($hasAlpha) {
            $gsName = $this->graphicState->getAlphaState((float) $s['opacity'], (float) $s['opacity']);
            $out .= "q\n/$gsName gs\n";
        }

        // Inline background / Badge rendering
        if ($s['bg'] !== null || $s['radius'] > 0) {
            $badgeH = 1.15 * $size + ($s['padding'][0] ?? 0.0) + ($s['padding'][2] ?? 0.0);
            $badgeY = $b - 0.85 * $size - ($s['padding'][0] ?? 0.0);
            $badgeW = $run['w'] + ($s['padding'][1] ?? 0.0) + ($s['padding'][3] ?? 0.0);
            $badgeX = $run['x'] - ($s['padding'][3] ?? 0.0);
            $out .= InlineBox::renderBox($badgeX, $badgeY, $badgeW, $badgeH, $s, $this->pageH);
        }

        $out .= $this->textOp($run['text'], $run['x'], $b, $s, $run['extra']);
        $thickness = max(0.4, 0.06 * $size);
        if ($s['under']) {
            $out .= $this->fillOp($run['x'], $b + 0.1 * $size, $run['w'], $thickness, $s['color']);
        }
        if ($s['strike']) {
            $out .= $this->fillOp($run['x'], $b - 0.3 * $size, $run['w'], $thickness, $s['color']);
        }

        if ($hasAlpha) {
            $out .= "Q\n";
        }

        // Register clickable hyperlink
        if (!empty($run['link']) && !$this->measuring) {
            $this->pageAnnots[] = [
                'rect' => [$run['x'], $b - 0.85 * $size, $run['w'], 1.1 * $size],
                'url' => $run['link'],
            ];
        }

        $this->ops[] = $out;
    }

    // ----------------------------------------------------------------- images & SVG

    private function renderImageBlock(DOMElement $el, array $s, float $x, float $w): void
    {
        [$mt, $mr, $mb, $ml] = $this->edges($s['margin'], $w);
        $src = $el->getAttribute('src');

        // Check if SVG image
        if (str_ends_with(strtolower($src), '.svg') || str_starts_with($src, 'data:image/svg+xml')) {
            $svgContent = $this->readSource($src);
            if ($svgContent !== null) {
                $rendered = SvgRenderer::render($svgContent, $x + $ml, $this->y + $this->pending + $mt, $s['width'] !== null ? $this->px($s['width'], $w) : null, is_float($s['height']) ? $s['height'] : null, $this->pageH);
                $this->pending = max($this->pending, $mt);
                $this->ops[] = $rendered['ops'];
                $this->y += $rendered['h'];
                $this->pending = max($this->pending, $mb);
                return;
            }
        }

        $item = $this->imageItem($el, $s, max(1.0, $w - $ml - $mr));
        if ($item === null)
            return;
        $align = $s['mAutoL'] && $s['mAutoR'] ? 'center' : ($s['mAutoL'] ? 'right' : 'left');
        $this->pending = max($this->pending, $mt);
        $items = [$item];
        $this->flushLines($items, $x + $ml, $w - $ml - $mr, $s, $align);
        $this->pending = max($this->pending, $mb);
    }

    private function renderSvgBlock(DOMElement $el, array $s, float $x, float $w): void
    {
        [$mt, $mr, $mb, $ml] = $this->edges($s['margin'], $w);
        $tw = $s['width'] !== null ? $this->px($s['width'], $w) : null;
        $th = is_float($s['height']) ? $s['height'] : null;

        $rendered = SvgRenderer::render($el, $x + $ml, $this->y + $this->pending + $mt, $tw, $th, $this->pageH);
        $this->pending = max($this->pending, $mt);
        $this->ops[] = $rendered['ops'];
        $this->y += $rendered['h'];
        $this->pending = max($this->pending, $mb);
    }

    private function svgItem(DOMElement $el, array $s, float $w): ?array
    {
        $tw = $s['width'] !== null ? $this->px($s['width'], $w) : null;
        $th = is_float($s['height']) ? $s['height'] : null;
        $rendered = SvgRenderer::render($el, 0, 0, $tw, $th, $this->pageH);
        return ['t' => 'svg', 'w' => $rendered['w'], 'h' => $rendered['h'], 'el' => $el, 's' => $s, 'k' => 'svg'];
    }

    private function imageItem(DOMElement $el, array $s, float $w): ?array
    {
        $source = $el->getAttribute('src');
        if (preg_match('/\.svg(?:[?#].*)?$/i', $source) || str_starts_with($source, 'data:image/svg+xml')) {
            $svg = $this->readSource($source);
            if ($svg === null)
                return null;
            $width = $s['width'] === null ? null : $this->px($s['width'], $w);
            $height = is_float($s['height']) ? $s['height'] : null;
            $rendered = SvgRenderer::render($svg, 0, 0, $width, $height, $this->pageH);
            return ['t' => 'svg', 'el' => $svg, 'w' => $rendered['w'], 'h' => $rendered['h'], 's' => $s, 'k' => 'svg'];
        }
        $img = $this->loadImage($el->getAttribute('src'));
        if ($img === null)
            return null;
        $natW = $img['w'] * self::PX;
        $natH = $img['h'] * self::PX;
        $tw = $s['width'] === null ? null : $this->px($s['width'], $w);
        $th = is_float($s['height']) ? $s['height'] : null;

        if ($tw !== null && $th === null) {
            $th = $tw * $natH / $natW;
        } elseif ($th !== null && $tw === null) {
            $tw = $th * $natW / $natH;
        } elseif ($tw === null) {
            [$tw, $th] = [$natW, $natH];
        }
        if ($tw > $w && $w > 0) {
            $th *= $w / $tw;
            $tw = $w;
        }
        if ($s['maxWidth'] !== null && $tw > $this->px($s['maxWidth'], $w)) {
            $maxWidth = max(0.0, $this->px($s['maxWidth'], $w));
            $th *= $maxWidth / $tw;
            $tw = $maxWidth;
        }
        if (is_float($s['maxHeight']) && $th > $s['maxHeight']) {
            $tw *= max(0.0, $s['maxHeight']) / $th;
            $th = max(0.0, $s['maxHeight']);
        }
        $radius = is_string($s['radius']) ? (float) $s['radius'] / 100 * min($tw, $th) : (float) $s['radius'];

        return ['t' => 'img', 'w' => $tw, 'h' => $th, 'res' => $img['name'], 'r' => $radius, 's' => $s, 'k' => 'img'];
    }

    private function loadImage(string $src): ?array
    {
        $src = trim($src);
        if ($src === '')
            return null;
        if (array_key_exists($src, $this->imageCache))
            return $this->imageCache[$src];

        $data = $this->readSource($src);
        $info = $data !== null ? @getimagesizefromstring($data) : false;
        if (!$info)
            return $this->imageCache[$src] = null;

        [$width, $height] = $info;
        $name = 'I' . (count($this->images) + 1);
        $entry = ['name' => $name, 'w' => $width, 'h' => $height, 'smask' => null];

        if ($info[2] === IMAGETYPE_JPEG) {
            $channels = $info['channels'] ?? 3;
            $space = match ($channels) { 1 => '/DeviceGray', 4 => '/DeviceCMYK', default => '/DeviceRGB'};
            $entry['dict'] = "/Type /XObject /Subtype /Image /Width $width /Height $height /ColorSpace $space /BitsPerComponent 8 /Filter /DCTDecode"
                . ($channels === 4 ? ' /Decode [1 0 1 0 1 0 1 0]' : '');
            $entry['data'] = $data;
        } else {
            $decoded = $this->decodeWithGd($data, $width, $height);
            if ($decoded === null)
                return $this->imageCache[$src] = null;
            [$rgb, $alpha] = $decoded;
            $entry['dict'] = "/Type /XObject /Subtype /Image /Width $width /Height $height /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode";
            $entry['data'] = gzcompress($rgb, 6);
            $entry['smask'] = $alpha === null ? null : gzcompress($alpha, 6);
        }

        $this->images[$src] = $entry;
        return $this->imageCache[$src] = $entry;
    }

    private function decodeWithGd(string $data, int $width, int $height): ?array
    {
        if (!function_exists('imagecreatefromstring'))
            return null;
        $src = @imagecreatefromstring($data);
        if ($src === false)
            return null;
        $im = imagecreatetruecolor($width, $height);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 0));
        imagecopy($im, $src, 0, 0, 0, 0, $width, $height);

        $rgb = '';
        $alpha = '';
        $hasAlpha = false;
        for ($y = 0; $y < $height; $y++) {
            $rowRgb = [];
            $rowA = [];
            for ($x = 0; $x < $width; $x++) {
                $c = imagecolorat($im, $x, $y);
                $rowRgb[] = ($c >> 16) & 255;
                $rowRgb[] = ($c >> 8) & 255;
                $rowRgb[] = $c & 255;
                $a = 255 - (int) round((($c >> 24) & 127) * 255 / 127);
                $rowA[] = $a;
                $hasAlpha = $hasAlpha || $a < 255;
            }
            $rgb .= pack('C*', ...$rowRgb);
            $alpha .= pack('C*', ...$rowA);
        }
        return [$rgb, $hasAlpha ? $alpha : null];
    }

    private function readSource(string $src): ?string
    {
        if (str_starts_with($src, 'data:')) {
            $comma = strpos($src, ',');
            if ($comma === false)
                return null;
            $payload = substr($src, $comma + 1);
            $data = str_contains(substr($src, 0, $comma), ';base64') ? base64_decode($payload, false) : rawurldecode($payload);
            return $data === false || $data === '' ? null : $data;
        }
        if (preg_match('~^https?://~i', $src)) {
            if (!$this->allowRemote)
                return null;
            $data = @file_get_contents($src, false, stream_context_create(['http' => ['timeout' => 10]]));
            return $data === false ? null : $data;
        }
        $path = str_starts_with($src, 'file://') ? substr($src, 7) : $src;
        if (str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path))
            return null;
        if ($this->basePath !== null && !preg_match('~^(/|[A-Za-z]:[\\\\/])~', $path)) {
            $path = rtrim($this->basePath, '/\\') . '/' . $path;
        }
        if (!is_file($path) || !is_readable($path))
            return null;
        $data = @file_get_contents($path);
        return $data === false ? null : $data;
    }

    // ------------------------------------------------------ PDF primitives

    private function boxOps(float $x, float $y, float $w, float $h, array $s, array $sides = [true, true, true, true], bool $centered = false): string
    {
        if ($w <= 0 || $h <= 0)
            return '';
        $out = '';
        $radius = is_string($s['radius']) ? (float) $s['radius'] / 100 * min($w, $h) : (float) $s['radius'];

        $hasAlpha = (($s['opacity'] ?? 1.0) < 0.999);
        if ($hasAlpha) {
            $gsName = $this->graphicState->getAlphaState((float) $s['opacity'], (float) $s['opacity']);
            $out .= "q\n/$gsName gs\n";
        }

        if ($s['bg'] !== null) {
            $background = $s['bg'];
            $background[3] = ($background[3] ?? 1.0) * $s['opacity'];
            $out .= $radius > 0
                ? sprintf("q %s%s rg %s f Q\n", $this->colorAlpha($background), $this->rg($background), $this->roundRect($x, $y, $w, $h, $radius))
                : $this->fillOp($x, $y, $w, $h, $background);
        }

        $b = $s['border'];
        foreach ($b as &$border) {
            $border['c'][3] = ($border['c'][3] ?? 1.0) * $s['opacity'];
        }
        unset($border);
        $uniform = $b[0]['w'] > 0 && $b[0] == $b[1] && $b[1] == $b[2] && $b[2] == $b[3];
        if ($radius > 0 && $uniform && !in_array(false, $sides, true)) {
            $bw = $b[0]['w'];
            $out .= sprintf(
                "q %s%s RG %s w %s %s S Q\n",
                $this->colorAlpha($b[0]['c']),
                $this->rg($b[0]['c']),
                $this->n($bw),
                $this->dash($b[0]['s'], $bw),
                $this->roundRect($x + $bw / 2, $y + $bw / 2, $w - $bw, $h - $bw, max(0.0, $radius - $bw / 2)),
            );
        } else {
            foreach ($b as $i => $side) {
                $bw = $side['w'];
                if ($bw <= 0 || !$sides[$i])
                    continue;
                $o = $centered ? 0.0 : $bw / 2;
                [$x1, $y1, $x2, $y2] = match ($i) {
                    0 => [$x, $y + $o, $x + $w, $y + $o],
                    1 => [$x + $w - $o, $y, $x + $w - $o, $y + $h],
                    2 => [$x, $y + $h - $o, $x + $w, $y + $h - $o],
                    default => [$x + $o, $y, $x + $o, $y + $h],
                };
                $out .= sprintf(
                    "q %s%s RG %s w %s %s %s m %s %s l S Q\n",
                    $this->colorAlpha($side['c']),
                    $this->rg($side['c']),
                    $this->n($bw),
                    $this->dash($side['s'], $bw),
                    $this->n($x1),
                    $this->n($this->pageH - $y1),
                    $this->n($x2),
                    $this->n($this->pageH - $y2),
                );
            }
        }

        if ($hasAlpha) {
            $out .= "Q\n";
        }

        return $out;
    }

    private function dash(string $style, float $w): string
    {
        return match ($style) {
            'dashed' => sprintf('[%s %s] 0 d', $this->n($w * 3), $this->n($w * 3)),
            'dotted' => sprintf('[%s %s] 0 d', $this->n($w), $this->n($w)),
            default => '',
        };
    }

    private function fillOp(float $x, float $y, float $w, float $h, array $color): string
    {
        return sprintf("q %s%s rg %s %s %s %s re f Q\n", $this->colorAlpha($color), $this->rg($color), $this->n($x), $this->n($this->pageH - $y - $h), $this->n($w), $this->n($h));
    }

    private function colorAlpha(array $color): string
    {
        if (($color[3] ?? 1.0) >= 1.0)
            return '';
        $name = $this->graphicState->getAlphaState($color[3]);
        return "/$name gs ";
    }

    private function roundRect(float $x, float $y, float $w, float $h, float $r): string
    {
        $r = max(0.0, min($r, $w / 2, $h / 2));
        $k = $r * 0.5523;
        $top = $this->pageH - $y;
        $bottom = $this->pageH - $y - $h;
        $right = $x + $w;
        $n = fn(float $v): string => $this->n($v);

        return sprintf(
            '%s %s m %s %s l %s %s %s %s %s %s c %s %s l %s %s %s %s %s %s c %s %s l %s %s %s %s %s %s c %s %s l %s %s %s %s %s %s c h',
            $n($x + $r),
            $n($top),
            $n($right - $r),
            $n($top),
            $n($right - $r + $k),
            $n($top),
            $n($right),
            $n($top - $r + $k),
            $n($right),
            $n($top - $r),
            $n($right),
            $n($bottom + $r),
            $n($right),
            $n($bottom + $r - $k),
            $n($right - $r + $k),
            $n($bottom),
            $n($right - $r),
            $n($bottom),
            $n($x + $r),
            $n($bottom),
            $n($x + $r - $k),
            $n($bottom),
            $n($x),
            $n($bottom + $r - $k),
            $n($x),
            $n($bottom + $r),
            $n($x),
            $n($top - $r),
            $n($x),
            $n($top - $r + $k),
            $n($x + $r - $k),
            $n($top),
            $n($x + $r),
            $n($top),
        );
    }

    private function imageOp(array $item, float $x, float $top): string
    {
        if ($item['t'] === 'svg') {
            return SvgRenderer::render($item['el'], $x, $top, $item['w'], $item['h'], $this->pageH)['ops'];
        }
        $clip = $item['r'] > 0 ? $this->roundRect($x, $top, $item['w'], $item['h'], $item['r']) . ' W n ' : '';
        return sprintf(
            "q %s%s 0 0 %s %s %s cm /%s Do Q\n",
            $clip,
            $this->n($item['w']),
            $this->n($item['h']),
            $this->n($x),
            $this->n($this->pageH - $top - $item['h']),
            $item['res'],
        );
    }

    private function textOp(string $text, float $x, float $baseline, array $s, float $wordSpace = 0.0): string
    {
        $font = $this->fontManager->getFont($s['family'], $s['bold'], $s['italic'], $text);
        $fontRes = $this->fontManager->getResourceName($font);

        $charSpaceOp = ($s['letter-spacing'] ?? 0.0) != 0.0 ? $this->n($s['letter-spacing']) . ' Tc ' : '';
        $effectiveWordSpace = $wordSpace + ($s['word-spacing'] ?? 0.0);
        $wordSpaceOp = $effectiveWordSpace != 0.0 ? $this->n($effectiveWordSpace) . ' Tw ' : '';
        $showText = $font->encode($text) . ' Tj';
        if ($font instanceof \Spark\Pdf\Font\TrueTypeFont && $effectiveWordSpace != 0.0 && $s['size'] > 0) {
            $parts = explode(' ', $text);
            $encoded = [];
            foreach ($parts as $i => $part) {
                if ($i > 0) {
                    $encoded[] = $font->encode(' ');
                    $encoded[] = $this->n(-1000 * $effectiveWordSpace / $s['size']);
                }
                $encoded[] = $font->encode($part);
            }
            $showText = '[' . implode(' ', $encoded) . '] TJ';
            $wordSpaceOp = '';
        }

        $operation = sprintf(
            "q BT /%s %s Tf %s rg %s %s Td %s%s%s ET Q\n",
            $fontRes,
            $this->n($s['size']),
            $this->rg($s['color']),
            $this->n($x),
            $this->n($this->pageH - $baseline),
            $charSpaceOp,
            $wordSpaceOp,
            $showText,
        );
        if (($s['color'][3] ?? 1.0) < 1.0) {
            $color = $s['color'];
            $color[3] *= $s['opacity'];
            $operation = 'q ' . $this->colorAlpha($color) . $operation . "Q\n";
        }
        if ($font instanceof \Spark\Pdf\Font\TrueTypeFont) {
            $actualText = strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')));
            $operation = "/Span << /ActualText <FEFF$actualText> >> BDC\n" . $operation . "EMC\n";
        }
        return $operation;
    }

    private function n(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private function rg(array $c): string
    {
        return $this->n($c[0] / 255) . ' ' . $this->n($c[1] / 255) . ' ' . $this->n($c[2] / 255);
    }
}
