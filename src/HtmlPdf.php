<?php

declare(strict_types=1);

namespace Spark\Pdf;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use InvalidArgumentException;
use RuntimeException;
use Spark\Pdf\Css\Length;
use Spark\Pdf\Css\Selector;
use Spark\Pdf\Css\Stylesheet;
use Spark\Pdf\Font\FontManager;
use Spark\Pdf\Graphics\BackgroundImage;
use Spark\Pdf\Graphics\BorderStyle;
use Spark\Pdf\Graphics\Color;
use Spark\Pdf\Graphics\GraphicState;
use Spark\Pdf\Graphics\SvgRenderer;
use Spark\Pdf\Layout\FlexEngine;
use Spark\Pdf\Layout\InlineBox;
use Spark\Pdf\Layout\ListMarker;
use Spark\Pdf\Layout\TableEngine;
use Spark\Pdf\Text\ShapedRun;
use Spark\Pdf\Text\LogicalText;
use function array_key_exists;
use function array_slice;
use function chr;
use function count;
use function in_array;
use function is_array;
use function is_float;
use function is_string;
use function sprintf;
use function strlen;

/**
 * HTML/CSS renderer for a documented subset of HTML/CSS document templates.
 * See README.md for font, shaping, layout, and resource-trust limits.
 *
 * @author Shahin Moyshan <shahin.moyshan2@gmail.com>
 */
final class HtmlPdf
{
    public const VERSION = '1.2.2';
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
        'caption' => ['text-align' => 'center'],
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
        'lineHeightLength',
        'under',
        'strike',
        'transform',
        'list',
        'listPosition',
        'captionSide',
        'pre',
        'wrap',
        'direction',
        'letter-spacing',
        'word-spacing',
        'wordBreak',
        'overflowWrap',
        'lineBreak',
        'lang',
        'weight',
        'stretch',
        'opticalSizing',
        'variations',
        'features',
        'writingMode',
        'textOrientation',
        'whiteSpace',
        'visibility',
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
        'visibility' => 'visible',
        'objectFit' => 'fill',
        'objectPosition' => '50% 50%',
        'aspectRatio' => null,
        'overflow' => 'visible',
        'tableLayout' => 'auto',
        'captionSide' => 'top',
        'listPosition' => 'outside',
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
        'flex-wrap' => 'nowrap',
        'align-self' => 'auto',
        'align-content' => 'stretch',
        'order' => 0,
        'row-gap' => null,
        'column-gap' => null,
        'flex-basis' => null,
        'flex-shrink' => 1.0,
        'justify-content' => 'flex-start',
        'align-items' => 'stretch',
        'gap' => 0.0,
        'flex' => null,
        'flex-grow' => null,
        'direction' => 'ltr',
        'letter-spacing' => 0.0,
        'word-spacing' => 0.0,
        'wordBreak' => 'normal',
        'overflowWrap' => 'normal',
        'lineBreak' => 'auto',
        'lang' => 'und',
        'weight' => 400,
        'stretch' => 100,
        'opticalSizing' => 'auto',
        'variations' => [],
        'features' => [],
        'writingMode' => 'horizontal-tb',
        'textOrientation' => 'mixed',
        'unicodeBidi' => 'normal',
        'whiteSpace' => 'normal',
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
    private array $pendingDestinations = [];
    private float $rootFontSize;

    private FontManager $fontManager;
    private GraphicState $graphicState;
    private LogicalText $logicalText;
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
        private ?TextOptions $textOptions = null,
    ) {
        if (!is_finite($fontSize) || $fontSize <= 0) {
            throw new InvalidArgumentException('Font size must be positive and finite.');
        }
        $this->formatSpec = is_array($format) ? $format : [$format];
        $this->orientationSpec = $orientation;
        $this->pageSize($format, $orientation);
        $this->margin = $this->parseMargin($margin);
        $this->rootFontSize = $fontSize;
        $this->textOptions ??= new TextOptions();
        $this->fontManager = new FontManager($this->textOptions);
        $this->graphicState = new GraphicState();
        $this->logicalText = new LogicalText($this->fontManager->helper());
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

    public function addFont(string $family, string $path, bool $bold = false, bool $italic = false, int $faceIndex = 0, array $variations = []): static
    {
        $this->fontManager->addFont($family, $path, $bold, $italic, $faceIndex, $variations);
        return $this;
    }

    public function setFallbackFonts(array $families): static
    {
        $this->fontManager->setFallbackFonts($families);
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
        $visible = $root['display'] !== 'none' && $style['display'] !== 'none';
        $this->bodyBackground = $visible ? $style : null;
        $this->beginPage($this->pageSize($spec, $orientation ?? $this->orientationSpec));
        if (!$visible)
            return $this;
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

        $extGStateRes .= $this->logicalText->buildResources($extraObjects, $id, $fontRes, $imageRes, $extGStateRes);

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
            $this->frames[$i]['annot'] = 0;
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
                $style = ['size' => 8.0, 'color' => [0.43, 0.43, 0.43]] + $this->rootStyle();
                $width = $this->textItem($text, $style, 'w')['w'];
                $savedHeight = $this->pageH;
                $this->pageH = $page['h'];
                try {
                    $op = $this->textOp($text, ($page['w'] - $width) / 2, $page['h'] - $margin[2] / 2 + 3, $style);
                } finally {
                    $this->pageH = $savedHeight;
                }
                $page['ops'][] = $op;
            }

            // HTML Header
            if ($this->headerHtml !== null && $this->headerHtml !== '' && !($isFirst && $this->excludeHeaderFirstPage)) {
                $hHtml = str_replace(['{page}', '{pages}'], [(string) ($i + 1), (string) $total], $this->headerHtml);
                $headerY = max(0.0, ($margin[0] / 2) - 4.0 * self::MM);
                $header = $this->renderIsolatedHtml($hHtml, $page['w'], $page['h'], $margin[3], $headerY, $page['w'] - $margin[1] - $margin[3]);
                if ($header['ops']) {
                    array_unshift($page['ops'], ...$header['ops']);
                }
                $page['annots'] = [...$page['annots'], ...$header['annots']];
            }

            // HTML Footer
            if ($this->footerHtml !== null && $this->footerHtml !== '' && !($isFirst && $this->excludeFooterFirstPage)) {
                $fHtml = str_replace(['{page}', '{pages}'], [(string) ($i + 1), (string) $total], $this->footerHtml);
                $footerY = $page['h'] - ($margin[2] / 2) - 4.0 * self::MM;
                $footer = $this->renderIsolatedHtml($fHtml, $page['w'], $page['h'], $margin[3], $footerY, $page['w'] - $margin[1] - $margin[3]);
                if ($footer['ops']) {
                    $page['ops'] = [...$page['ops'], ...$footer['ops']];
                }
                $page['annots'] = [...$page['annots'], ...$footer['annots']];
            }
        }
        unset($page);
        return $pages;
    }

    private function renderIsolatedHtml(string $html, float $pageW, float $pageH, float $x, float $y, float $maxW): array
    {
        $saved = get_object_vars($this);

        $this->ops = [];
        $this->pageAnnots = [];
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
        $this->pendingDestinations = [];
        $this->styleCache = [];

        try {
            $doc = $this->parse($html);
            $this->rules = $this->collectRules($doc);
            $this->rootFontSize = $this->fontSize;
            $root = $doc->documentElement instanceof DOMElement
                ? $this->computeStyle($doc->documentElement, $this->rootStyle())
                : $this->rootStyle();
            $this->rootFontSize = $root['size'];
            $body = $doc->getElementsByTagName('body')->item(0);
            if ($body instanceof DOMElement) {
                $style = $this->computeStyle($body, $root);
                if ($root['display'] !== 'none' && $style['display'] !== 'none')
                    $this->renderBox($body, $style, $x, $maxW);
            }
            return ['ops' => $this->ops, 'annots' => $this->pageAnnots];
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
        if (!mb_check_encoding($html, 'UTF-8')) {
            throw new InvalidArgumentException('HTML must be valid UTF-8.');
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }

    private function collectRules(DOMDocument $doc): array
    {
        $css = Stylesheet::collect($doc, $this->readSource(...), $this->printMedia(...), $this->basePath);
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
                    $parts = Selector::parse(trim($selector));
                    if ($parts) {
                        $rules[] = ['parts' => $parts, 'spec' => Selector::specificity($selector), 'decl' => $decl, 'important' => $important, 'order' => $order];
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

    private function tag(DOMElement $el): string
    {
        return strtolower($el->tagName);
    }

    private function rootStyle(): array
    {
        return [
            'lang' => $this->textOptions->defaultLanguage,
            'color' => [0, 0, 0],
            'family' => $this->fontFamily,
            'size' => $this->fontSize,
            'bold' => false,
            'italic' => false,
            'align' => 'start',
            'lh' => null,
            'lineHeightLength' => null,
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
        if (in_array($tag, self::HIDDEN_TAGS, true) || $el->hasAttribute('hidden')) {
            $s['display'] = 'none';
        }
        if (in_array($tag, ['tr', 'thead', 'tbody', 'tfoot', 'td', 'th'], true)) {
            $s['valign'] = $parent['valign'] ?? null;
        }

        if ($el->hasAttribute('lang'))
            $s['lang'] = $el->getAttribute('lang') ?: $s['lang'];
        if ($tag === 'bdi') {
            $s['unicodeBidi'] = 'isolate';
            $s['direction'] = 'auto';
        }
        if ($tag === 'bdo')
            $s['unicodeBidi'] = 'bidi-override';
        if ($el->hasAttribute('dir') && in_array($el->getAttribute('dir'), ['ltr', 'rtl', 'auto'], true)) {
            $s['unicodeBidi'] = $tag === 'bdo' ? 'bidi-override' : 'isolate';
        }
        $decl = $this->normalize(self::UA[$tag] ?? []);
        $decl = array_replace($decl, $this->normalize($this->attributeDeclarations($el, $tag)));
        $important = [];
        foreach ($this->rules as $rule) {
            if (Selector::matches($el, $rule['parts'], count($rule['parts']) - 1)) {
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
        if ($s['lineHeightLength'] !== null && $s['size'] > 0)
            $s['lh'] = $s['lineHeightLength'] / $s['size'];
        $s['opacity'] *= $parent['opacity'];

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
            if (in_array($v, ['rtl', 'ltr', 'auto'], true)) {
                $d['direction'] = $v;
            }
        }

        if (($v = $attr('width')) !== '' && in_array($tag, ['table', 'td', 'th', 'img', 'col', 'colgroup', 'svg'], true)) {
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
            case 'img':
                if (ctype_digit($attr('border')))
                    $d['border'] = $attr('border') . 'px solid';
                break;
            case 'ol':
            case 'ul':
            case 'li':
                $type = $attr('type');
                if (in_array($type, ['1', 'a', 'A', 'i', 'I', 'disc', 'circle', 'square'], true))
                    $d['list-style-type'] = match ($type) { '1' => 'decimal', 'a' => 'lower-alpha', 'A' => 'upper-alpha', 'i' => 'lower-roman', 'I' => 'upper-roman', default => $type };
                break;
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
        return Selector::split($css, $delimiter);
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
                case 'flex':
                    $parts = $this->tokens(strtolower($value));
                    if (in_array($value, ['none', 'auto', 'initial'], true)) {
                        $out['flex-grow'] = $value === 'auto' ? '1' : '0';
                        $out['flex-shrink'] = $value === 'none' ? '0' : '1';
                        $out['flex-basis'] = 'auto';
                    } elseif (isset($parts[0]) && is_numeric($parts[0])) {
                        $out['flex-grow'] = $parts[0];
                        $out['flex-shrink'] = isset($parts[1]) && is_numeric($parts[1]) ? $parts[1] : '1';
                        $out['flex-basis'] = $parts[2] ?? (isset($parts[1]) && !is_numeric($parts[1]) ? $parts[1] : '0%');
                    } else {
                        $out['flex-grow'] = '1';
                        $out['flex-shrink'] = '1';
                        $out['flex-basis'] = $value;
                    }
                    break;
                case 'gap':
                    $parts = $this->tokens($value);
                    $out['row-gap'] = $parts[0] ?? '0';
                    $out['column-gap'] = $parts[1] ?? $parts[0] ?? '0';
                    break;
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
                        $out["border-$side-color"] = $color ?? 'currentcolor';
                    }
                    break;
                case 'background':
                    $out['background-color'] = 'transparent';
                    $out['background-image'] = 'none';
                    $out['background-repeat'] = 'repeat';
                    $out['background-position'] = '0% 0%';
                    $out['background-size'] = 'auto';
                    if (preg_match('/url\(([\'"]?)(.*?)\1\)/i', $value, $m)) {
                        $out['background-image'] = $m[2];
                    }
                    $position = [];
                    $size = [];
                    $afterSlash = false;
                    $background = preg_replace('/url\(([\'"]?)(.*?)\1\)/i', '', $value);
                    foreach ($this->tokens(str_replace('/', ' / ', $background)) as $token) {
                        if ($this->color($token) !== null || strtolower($token) === 'transparent') {
                            $out['background-color'] = $token;
                        } elseif (in_array(strtolower($token), ['repeat', 'repeat-x', 'repeat-y', 'no-repeat'], true)) {
                            $out['background-repeat'] = strtolower($token);
                        } elseif ($token === '/') {
                            $afterSlash = true;
                        } elseif ($this->length($token, $this->fontSize) !== null || in_array(strtolower($token), ['left', 'right', 'top', 'bottom', 'center', 'auto', 'cover', 'contain'], true)) {
                            if ($afterSlash)
                                $size[] = $token;
                            else
                                $position[] = $token;
                        }
                    }
                    if ($position)
                        $out['background-position'] = implode(' ', $position);
                    if ($size)
                        $out['background-size'] = implode(' ', $size);
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
            } elseif (in_array($l, ['thin', 'medium', 'thick'], true) || preg_match('/^(?:[\d.]|(?:calc|min|max|clamp)\()/', $l)) {
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
                    $s['weight'] = is_numeric($l) ? max(1, min(1000, (float) $l)) : (in_array($l, ['bold', 'bolder'], true) ? 700 : 400);
                    $s['bold'] = $l === 'bold' || $l === 'bolder' || (ctype_digit($l) && (int) $l >= 600);
                    break;
                case 'font-style':
                    $s['italic'] = $l === 'italic' || $l === 'oblique';
                    break;
                case 'text-align':
                    if (in_array($l, ['left', 'right', 'center', 'justify', 'start', 'end'], true))
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
                    $s['whiteSpace'] = $l;
                    $s['pre'] = in_array($l, ['pre', 'pre-wrap', 'break-spaces'], true);
                    $s['wrap'] = !in_array($l, ['pre', 'nowrap'], true);
                    break;
                case 'line-height':
                    $s['lh'] = $this->lineHeight($l, $fs);
                    $s['lineHeightLength'] = is_numeric($l) || $s['lh'] === null ? null : $s['lh'] * $fs;
                    break;
                case 'list-style':
                case 'list-style-type':
                    foreach ($this->tokens($l) as $token) {
                        if (in_array($token, ['none', 'disc', 'circle', 'square', 'decimal', 'decimal-leading-zero', 'lower-alpha', 'upper-alpha', 'lower-latin', 'upper-latin', 'lower-roman', 'upper-roman'], true)) {
                            $s['list'] = $token;
                        }
                        if (in_array($token, ['inside', 'outside'], true))
                            $s['listPosition'] = $token;
                    }
                    break;
                case 'list-style-position':
                    $s['listPosition'] = $l === 'inside' ? 'inside' : 'outside';
                    break;
                case 'background-color':
                    $s['bg'] = $this->color($value);
                    break;
                case 'background-image':
                    $s['bgImg'] = $l === 'none' ? null : $value;
                    break;
                case 'background-repeat':
                    $s['bgRepeat'] = $l;
                    break;
                case 'background-position':
                    $s['bgPos'] = $this->positionLengths($value, $fs);
                    break;
                case 'background-size':
                    $s['bgSize'] = $this->positionLengths($value, $fs);
                    break;
                case 'opacity':
                    $s['opacity'] = max(0.0, min(1.0, (float) $value));
                    break;
                case 'visibility':
                    if (in_array($l, ['visible', 'hidden', 'collapse'], true))
                        $s['visibility'] = $l;
                    break;
                case 'object-fit':
                    if (in_array($l, ['fill', 'contain', 'cover', 'none', 'scale-down'], true))
                        $s['objectFit'] = $l;
                    break;
                case 'object-position':
                    $s['objectPosition'] = $this->positionLengths($value, $fs);
                    break;
                case 'aspect-ratio':
                    if (preg_match('/^(\d*\.?\d+)(?:\s*\/\s*(\d*\.?\d+))?$/', $l, $ratio) && (float) ($ratio[2] ?? 1) > 0)
                        $s['aspectRatio'] = (float) $ratio[1] > 0 ? (float) $ratio[1] / (float) ($ratio[2] ?? 1) : null;
                    break;
                case 'overflow':
                    if (in_array($l, ['visible', 'hidden', 'clip'], true))
                        $s['overflow'] = $l;
                    break;
                case 'table-layout':
                    $s['tableLayout'] = $l === 'fixed' ? 'fixed' : 'auto';
                    break;
                case 'caption-side':
                    $s['captionSide'] = $l === 'bottom' ? 'bottom' : 'top';
                    break;
                case 'display':
                    $s['display'] = $this->displayOf($l);
                    break;
                case 'flex-direction':
                    $s['flex-direction'] = $l;
                    break;
                case 'flex-wrap':
                    if (in_array($l, ['nowrap', 'wrap', 'wrap-reverse'], true))
                        $s['flex-wrap'] = $l;
                    break;
                case 'align-self':
                case 'align-content':
                    $s[$name] = $l;
                    break;
                case 'order':
                    $s['order'] = (int) $value;
                    break;
                case 'row-gap':
                case 'column-gap':
                    $s[$name] = $this->length($value, $fs) ?? 0.0;
                    break;
                case 'flex-basis':
                    $s['flex-basis'] = $this->length($value, $fs);
                    break;
                case 'flex-shrink':
                    $s['flex-shrink'] = max(0.0, (float) $value);
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
                    if (in_array($l, ['ltr', 'rtl', 'auto'], true)) {
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
                    if (in_array($l, ['break-all', 'keep-all', 'break-word', 'normal'], true))
                        $s['wordBreak'] = $l;
                    break;
                case 'overflow-wrap':
                case 'word-wrap':
                    if (in_array($l, ['anywhere', 'break-word', 'normal'], true))
                        $s['overflowWrap'] = $l;
                    break;
                case 'line-break':
                    if (in_array($l, ['auto', 'normal', 'loose', 'strict', 'anywhere'], true))
                        $s['lineBreak'] = $l;
                    break;
                case 'unicode-bidi':
                    if (in_array($l, ['normal', 'embed', 'isolate', 'bidi-override', 'isolate-override', 'plaintext'], true))
                        $s['unicodeBidi'] = $l;
                    break;
                case 'writing-mode':
                    if (in_array($l, ['horizontal-tb', 'vertical-rl', 'vertical-lr'], true))
                        $s['writingMode'] = $l;
                    break;
                case 'text-orientation':
                    if (in_array($l, ['mixed', 'upright', 'sideways'], true))
                        $s['textOrientation'] = $l;
                    break;
                case 'font-stretch':
                    $s['stretch'] = str_ends_with($l, '%') ? max(50, min(200, (float) $l)) : match ($l) {
                        'ultra-condensed' => 50, 'extra-condensed' => 62.5, 'condensed' => 75, 'semi-condensed' => 87.5,
                        'semi-expanded' => 112.5, 'expanded' => 125, 'extra-expanded' => 150, 'ultra-expanded' => 200, default => 100,
                    };
                    break;
                case 'font-optical-sizing':
                    $s['opticalSizing'] = $l === 'none' ? 'none' : 'auto';
                    break;
                case 'font-variation-settings':
                    $s['variations'] = [];
                    if (preg_match_all('/[\'"]([A-Za-z0-9 ]{4})[\'"]\s+(-?\d+(?:\.\d+)?)/', $value, $axes, PREG_SET_ORDER)) {
                        foreach ($axes as $axis)
                            $s['variations'][$axis[1]] = (float) $axis[2];
                    }
                    break;
                case 'font-feature-settings':
                    $s['features'] = [];
                    if (preg_match_all('/[\'"]([A-Za-z0-9 ]{4})[\'"](?:\s+(on|off|\d+))?/', $value, $features, PREG_SET_ORDER)) {
                        foreach ($features as $feature) {
                            $setting = $feature[2] ?? 'on';
                            $s['features'][] = $feature[1] . '=' . ($setting === 'off' ? 0 : ($setting === 'on' ? 1 : (int) $setting));
                        }
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

    private function positionLengths(string $value, float $fontSize): string
    {
        return implode(' ', array_map(function (string $token) use ($fontSize): string {
            $length = $this->length($token, $fontSize);
            return is_float($length) ? sprintf('%.8Fpt', $length) : ($length ?? $token);
        }, $this->tokens($value)));
    }

    private function displayOf(string $v): string
    {
        return match ($v) {
            'none', 'inline', 'list-item', 'table', 'table-row', 'table-cell', 'table-row-group',
            'table-header-group', 'table-footer-group', 'flex', 'inline-flex', 'inline-block', 'contents', 'flow-root' => $v,
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
        return is_string($len) && $fs > 0 ? $this->px($len, $fs) / $fs : 1.2;
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
            is_string($len) => $this->px($len, $parent),
            default => $parent,
        };
    }

    private function length(string $v, float $fs): float|string|null
    {
        $format = count($this->formatSpec) === 1 ? $this->formatSpec[0] : $this->formatSpec;
        [$width, $height] = $this->pageSize($format, $this->orientationSpec);
        return Length::parse($v, $fs, $this->rootFontSize, $width * self::MM, $height * self::MM);
    }

    private function px(float|string|null $v, float $base): float
    {
        return Length::resolve($v, $base);
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
        if ($this->marker !== null && $s['display'] === 'list-item' && $s['listPosition'] === 'inside') {
            $this->collectText($this->marker['text'] . ' ', $textStyle, $items);
            $this->marker = null;
        }

        // If parent is flex container, dispatch to FlexEngine
        if (in_array($s['display'], ['flex', 'inline-flex'], true)) {
            $this->renderFlexContainer($parent, $s, $x, $w);
            return;
        }

        foreach ($this->flowChildren($parent, $s) as [$node, $cs]) {
            if ($node instanceof DOMText) {
                $this->collectText($node->nodeValue ?? '', array_intersect_key($cs, array_flip(self::INHERITED)) + $textStyle, $items);
                continue;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ($cs['display'] === 'none') {
                continue;
            }
            if ($cs['position'] === 'absolute') {
                $this->renderAbsolute($node, $cs, $x, $w);
                continue;
            }
            if (!in_array($cs['display'], ['inline', 'inline-block', 'inline-flex'], true)) {
                $this->flushLines($items, $x, $w, $s);
                $this->renderBlock($node, $cs, $x, $w);
                continue;
            }
            $this->collectInline($node, $cs, $items, $w);
        }
        $this->flushLines($items, $x, $w, $s);
    }

    private function flowChildren(DOMNode $parent, array $style): \Generator
    {
        foreach ($parent->childNodes as $node) {
            $childStyle = $node instanceof DOMElement ? $this->computeStyle($node, $style) : $style;
            if ($childStyle['display'] === 'contents' && $node instanceof DOMElement) {
                yield from $this->flowChildren($node, $childStyle);
            } else {
                yield [$node, $childStyle];
            }
        }
    }

    private function renderFlexContainer(DOMNode $parent, array $s, float $x, float $w): void
    {
        $isRow = !str_starts_with($s['flex-direction'], 'column');
        $s['contentHeight'] = $this->frames ? $this->frames[array_key_last($this->frames)]['contentH'] : null;
        foreach (['row-gap', 'column-gap'] as $gap)
            $s[$gap] = $this->px($s[$gap] ?? $s['gap'], $w);
        $children = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMText && trim($node->nodeValue ?? '') !== '') {
                $anonymous = $parent->ownerDocument->createElement('span');
                $anonymous->appendChild($node->cloneNode());
                $node = $anonymous;
                $cs = array_intersect_key($s, array_flip(self::INHERITED)) + self::RESET;
                $cs['display'] = 'block';
                $cs['opacity'] = $s['opacity'];
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

            $cs['padding'] = $this->edges($cs['padding'], $w);
            $cs['margin'] = $this->edges($cs['margin'], $w);
            foreach (['width', 'minWidth', 'maxWidth'] as $dimension) {
                if (is_string($cs[$dimension]))
                    $cs[$dimension] = $this->px($cs[$dimension], $w);
            }
            foreach (['height', 'minHeight', 'maxHeight'] as $dimension) {
                if (is_string($cs[$dimension])) {
                    $cs[$dimension] = $s['contentHeight'] === null && str_contains($cs[$dimension], '%')
                        ? null : $this->px($cs[$dimension], $s['contentHeight'] ?? 0.0);
                }
            }
            $naturalH = $this->measure(fn() => $this->renderElement($node, $cs, 0.0, $w));
            $naturalW = $cs['width'] !== null ? $this->outerWidth($cs, $w) : $this->maxContent(['el' => $node, 's' => $cs]);
            if ($cs['width'] === null && in_array($this->tag($node), ['img', 'svg'], true)) {
                $item = $this->tag($node) === 'img' ? $this->imageItem($node, $cs, $w) : $this->svgItem($node, $cs, $w);
                $naturalW = $item['w'] ?? 0.0;
            } elseif ($cs['width'] === null) {
                [, $pr, , $pl] = $this->edges($cs['padding'], $w);
                [, $br, , $bl] = array_column($cs['border'], 'w');
                $naturalW += $pl + $pr + $bl + $br;
            }
            [, $mr, , $ml] = $this->edges($cs['margin'], $w);
            $naturalW += $ml + $mr;
            $basis = $cs['flex-basis'];
            if ($basis !== null && ($isRow || $s['contentHeight'] !== null || is_float($basis) || !str_contains($basis, '%'))) {
                $base = $this->px($basis, $isRow ? $w : ($s['contentHeight'] ?? 0.0));
                $edges = $cs['padding'];
                $borders = array_column($cs['border'], 'w');
                $extra = $cs['sizing'] === 'border' ? 0.0 : ($isRow ? $edges[1] + $edges[3] + $borders[1] + $borders[3] : $edges[0] + $edges[2] + $borders[0] + $borders[2]);
                $base += $extra + ($isRow ? $ml + $mr : $cs['margin'][0] + $cs['margin'][2]);
            } else {
                $base = $isRow ? $naturalW : $naturalH;
            }

            $children[] = [
                'el' => $node,
                's' => $cs,
                'naturalW' => max(1.0, $naturalW),
                'naturalH' => max(1.0, $naturalH),
                'basis' => max(0.0, $base),
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
            if (in_array($this->tag($item['el']), ['img', 'svg'], true)) {
                [, $mr, , $ml] = $this->edges($childStyle['margin'], $w);
                [, $pr, , $pl] = $this->edges($childStyle['padding'], $w);
                [, $br, , $bl] = array_column($childStyle['border'], 'w');
                $extra = $childStyle['sizing'] === 'border' ? 0.0 : $pl + $pr + $bl + $br;
                $childStyle['width'] = max(0.0, $item['w'] - $ml - $mr - $extra);
            }
            $children[$i]['naturalH'] = $this->measure(fn() => $this->renderElement($item['el'], $childStyle, 0.0, $item['w']));
            if (!$isRow && $childStyle['flex-basis'] === null)
                $children[$i]['basis'] = $children[$i]['naturalH'];
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

        $this->placeDestinations();
        foreach ($layout['items'] as $it) {
            $savedY = $this->y;
            $savedPending = $this->pending;
            $this->y = $it['y'];
            $this->pending = 0.0;
            $childStyle = $it['s'];
            $childStyle['width'] = null;
            $isImage = in_array($this->tag($it['el']), ['img', 'svg'], true);
            if ($isImage) {
                [, $mr, , $ml] = $this->edges($childStyle['margin'], $w);
                [, $pr, , $pl] = $this->edges($childStyle['padding'], $w);
                [, $br, , $bl] = array_column($childStyle['border'], 'w');
                $extra = $childStyle['sizing'] === 'border' ? 0.0 : $pl + $pr + $bl + $br;
                $childStyle['width'] = max(0.0, $it['w'] - $ml - $mr - $extra);
            }
            if (!$isRow || (!$isImage && ($it['stretch'] ?? false))) {
                [$mt, , $mb] = $childStyle['margin'];
                [$pt, , $pb] = $childStyle['padding'];
                [$bt, , $bb] = array_column($childStyle['border'], 'w');
                $extra = $childStyle['sizing'] === 'border' ? 0.0 : $pt + $pb + $bt + $bb;
                $childStyle[$isRow ? 'minHeight' : 'height'] = max(0.0, $it['h'] - $mt - $mb - $extra);
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

        $isLink = $tag === 'a' && $el->hasAttribute('href');
        if ($isLink)
            $this->activeLinks[] = $el->getAttribute('href');

        $destination = [];
        // Record anchor for internal page links
        if ($el->hasAttribute('id') && !$this->measuring) {
            $id = $el->getAttribute('id');
            $this->anchors[$id] = [
                'page' => count($this->pages),
                'y' => max(0.0, $this->pageH - ($this->y + $this->pending)),
            ];
            $destination['anchor'] = $id;
        }

        // Automatic PDF bookmarks for h1-h6 headings
        if (preg_match('/^h([1-6])$/', $tag, $hm) && !$this->measuring) {
            $hText = trim($this->inlineText($el, $s));
            if ($hText !== '') {
                $this->addBookmark($hText, (int) $hm[1]);
                $destination['bookmark'] = array_key_last($this->bookmarks);
            }
        }
        $destinationIndex = null;
        if ($destination) {
            $this->pendingDestinations[] = $destination;
            $destinationIndex = array_key_last($this->pendingDestinations);
        }

        if ($tag === 'img' || $tag === 'svg') {
            $this->renderElement($el, $s, $x, $w);
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
        if ($isLink)
            array_pop($this->activeLinks);
        if ($destinationIndex !== null)
            unset($this->pendingDestinations[$destinationIndex]);
    }

    private function placeDestinations(): void
    {
        if ($this->measuring || !$this->pendingDestinations)
            return;
        $position = ['page' => count($this->pages), 'y' => max(0.0, $this->pageH - $this->y)];
        foreach ($this->pendingDestinations as $destination) {
            if (isset($destination['anchor']))
                $this->anchors[$destination['anchor']] = $position;
            if (isset($destination['bookmark']))
                $this->bookmarks[$destination['bookmark']] = array_replace($this->bookmarks[$destination['bookmark']], $position);
        }
        $this->pendingDestinations = [];
    }

    private function renderElement(DOMElement $el, array $s, float $x, float $w): void
    {
        $offsetY = 0.0;
        if ($s['position'] === 'relative') {
            $x += $s['pos'][3] !== null ? $this->px($s['pos'][3], $w) : -$this->px($s['pos'][1], $w);
            $height = $this->containingBlock()['h'] ?? 0.0;
            $offsetY = $s['pos'][0] !== null ? $this->px($s['pos'][0], $height) : -$this->px($s['pos'][2], $height);
            $this->y += $offsetY;
        }
        if (in_array($this->tag($el), ['img', 'svg'], true)) {
            $this->renderImageBlock($el, $s, $x, $w);
        } elseif ($s['display'] === 'table' && $this->tag($el) === 'table') {
            $this->renderTable($el, $s, $x, $w);
        } else {
            $this->renderBox($el, $s, $x, $w);
        }
        $this->y -= $offsetY;
    }

    private function renderBox(DOMElement $el, array $s, float $x, float $w, ?callable $inner = null): void
    {
        $parentHeight = $this->frames ? $this->frames[array_key_last($this->frames)]['contentH'] : $this->pageBottom - $this->pageTop;
        foreach (['height', 'minHeight', 'maxHeight'] as $dimension) {
            if (is_string($s[$dimension]) && ($parentHeight !== null || !str_contains($s[$dimension], '%')))
                $s[$dimension] = $this->px($s[$dimension], $parentHeight ?? 0.0);
        }
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

        if ($s['maxWidth'] !== null) {
            $cw = max(0.0, min($cw, $this->px($s['maxWidth'], $w) - ($s['sizing'] === 'border' ? $extra : 0.0)));
        }
        if ($s['minWidth'] !== null) {
            $cw = max($cw, $this->px($s['minWidth'], $w) - ($s['sizing'] === 'border' ? $extra : 0.0));
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
        $hb = is_float($s['height']) ? max($vertical, $s['height'] + ($s['sizing'] === 'border' ? 0.0 : $vertical)) : null;
        $minHb = is_float($s['minHeight']) ? max($vertical, $s['minHeight'] + ($s['sizing'] === 'border' ? 0.0 : $vertical)) : null;
        $maxHb = is_float($s['maxHeight']) ? max($vertical, $s['maxHeight'] + ($s['sizing'] === 'border' ? 0.0 : $vertical)) : null;
        if ($hb === null && $s['aspectRatio'] !== null)
            $hb = $s['sizing'] === 'border' ? max($vertical, ($cw + $extra) / $s['aspectRatio']) : $cw / $s['aspectRatio'] + $vertical;
        if ($hb !== null)
            $hb = max(min($hb, $maxHb ?? INF), $minHb ?? 0.0);

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
            'contentH' => $hb === null ? null : max(0.0, $hb - $vertical),
            'clipH' => $hb ?? ($maxHb === null ? null : max($maxHb, $minHb ?? 0.0)),
            'annot' => count($this->pageAnnots),
        ];
        if ($pt + $bt > 0) {
            $this->settle();
            $this->y += $bt + $pt;
        }

        $tag = $this->tag($el);
        $isList = $tag === 'ul' || $tag === 'ol';
        if ($isList) {
            $step = $tag === 'ol' && $el->hasAttribute('reversed') ? -1 : 1;
            $count = 0;
            foreach ($el->childNodes as $child)
                $count += (int) ($child instanceof DOMElement && $this->tag($child) === 'li');
            $start = $el->hasAttribute('start') ? (int) $el->getAttribute('start') : ($step === -1 ? $count : 1);
            $this->listStack[] = ['n' => $start - $step, 'step' => $step];
        }
        if ($s['display'] === 'list-item') {
            $last = array_key_last($this->listStack);
            if ($last !== null && $el->hasAttribute('value'))
                $this->listStack[$last]['n'] = (int) $el->getAttribute('value') - $this->listStack[$last]['step'];
            $this->prepareMarker($s);
        }

        $cx = $x + $ml + $bl + $pl;
        $savedBreak = $this->allowBreak;
        if ($s['overflow'] !== 'visible' && ($hb !== null || $maxHb !== null))
            $this->allowBreak = false;
        if ($inner !== null) {
            $inner($cx, $cw);
        } else {
            $this->renderChildren($el, $s, $cx, $cw);
        }
        $this->allowBreak = $savedBreak;

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
            if ($maxHb !== null)
                $this->y = min($this->y, $f['start'] + max($maxHb, $minHb ?? 0.0));
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
            $this->listStack[$last]['n'] += $this->listStack[$last]['step'];
            $n = $this->listStack[$last]['n'];
        }
        $text = ListMarker::text($n, $s['list']);
        $this->marker = $text === '' ? null : ['text' => $text, 's' => ['bg' => null] + $s];
    }

    private function paintFrame(array $f, float $endY, bool $last): void
    {
        $h = $endY - $f['start'];
        if ($h <= 0 && $f['s']['overflow'] === 'visible') {
            return;
        }

        $ops = $this->boxOps($f['x'], $f['start'], $f['w'], $h, $f['s'], [$f['first'], true, $last, true]);

        // Background Image if present
        if ($f['s']['bgImg'] !== null && $f['s']['visibility'] === 'visible') {
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
                $radius = $this->px($f['s']['radius'], min($f['w'], $h));
                $fill = ['border' => self::RESET['border']] + $f['s'];
                $border = ['bg' => null] + $f['s'];
                $ops = $this->boxOps($f['x'], $f['start'], $f['w'], $h, $fill)
                    . $bg->render($f['x'], $f['start'], $f['w'], $h, $radius, $this->pageH, $this->graphicState)
                    . $this->boxOps($f['x'], $f['start'], $f['w'], $h, $border, [$f['first'], true, $last, true]);
            }
        }

        if ($f['s']['overflow'] !== 'visible') {
            [$top, $right, $bottom, $left] = array_column($f['s']['border'], 'w');
            $clipX = $f['x'] + $left;
            $clipY = $f['start'] + ($f['first'] ? $top : 0.0);
            $clipW = max(0.0, $f['w'] - $left - $right);
            $clipH = max(0.0, $endY - $clipY - ($last ? $bottom : 0.0));
            $radius = max(0.0, $this->px($f['s']['radius'], min($f['w'], $h)) - min($top, $right, $bottom, $left));
            $content = array_splice($this->ops, $f['op']);
            $this->ops[] = $ops . "q\n" . $this->roundRect($clipX, $clipY, $clipW, $clipH, $radius) . " W n\n";
            array_push($this->ops, ...$content);
            $this->ops[] = "Q\n";
            for ($i = $f['annot']; $i < count($this->pageAnnots); $i++) {
                [$ax, $ay, $aw, $ah] = $this->pageAnnots[$i]['rect'];
                $left = max($ax, $clipX);
                $top = max($ay, $clipY);
                $this->pageAnnots[$i]['rect'] = [$left, $top, max(0.0, min($ax + $aw, $clipX + $clipW) - $left), max(0.0, min($ay + $ah, $clipY + $clipH) - $top)];
            }
        } elseif ($ops !== '') {
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
        $s2['padding'] = $this->edges($s2['padding'], $cb['w']);
        foreach (['width', 'minWidth', 'maxWidth'] as $dimension) {
            if (is_string($s2[$dimension]))
                $s2[$dimension] = $this->px($s2[$dimension], $cb['w']);
        }
        $tag = $this->tag($el);
        $isImage = $tag === 'img' || $tag === 'svg';

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

        $item = null;
        if ($isImage)
            $item = $tag === 'svg' ? $this->svgItem($el, $s2, $pw) : $this->imageItem($el, $s2, $pw);
        if ($isImage && $item === null)
            return;
        if ($item !== null && $left === null && $right !== null)
            $bx = $cb['x'] + $cb['w'] - $right - $item['w'];

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
        $fixed = $s['width'] !== null ? max(0.0, $this->px($s['width'], $w) - ($s['sizing'] === 'border' ? $extra : 0.0)) : null;

        $widths = $s['tableLayout'] === 'fixed' && $fixed !== null
            ? TableEngine::fixedWidths($gridData, $fixed, $spacing, $this->px(...))
            : $this->columnWidths($rows, $columns, $spacing, $fixed, $available);
        $s['width'] = array_sum($widths) + $spacing * ($columns + 1);
        $s['sizing'] = 'content';
        $grid = ['cols' => $widths, 'spacing' => $spacing, 'collapse' => $s['collapse'], 'head' => $head];

        $captions = [];
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement && $this->tag($child) === 'caption') {
                $captionStyle = $this->computeStyle($child, $s);
                if ($captionStyle['display'] !== 'none')
                    $captions[] = ['el' => $child, 's' => $captionStyle];
            }
        }
        $this->renderBox($el, $s, $x, $w, function (float $cx, float $cw) use ($rows, $grid, $spacing, $captions): void {
            foreach ($captions as $caption) {
                if ($caption['s']['captionSide'] === 'top')
                    $this->renderBlock($caption['el'], $caption['s'], $cx, $cw);
            }
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
            foreach ($captions as $caption) {
                if ($caption['s']['captionSide'] === 'bottom')
                    $this->renderBlock($caption['el'], $caption['s'], $cx, $cw);
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
                        $spec[$c] = ['value' => $cs['width']];
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
                $fixed[$c] = isset($sp['value']) ? $this->px($sp['value'], $base) : $sp['abs'];
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
                foreach ($this->fontManager->layout($items, 0.0, $style) as $line)
                    $width = max($width, $line['w']);
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

        $this->placeDestinations();
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
        if ($tag === 'wbr') {
            $this->collectText("\u{200B}", $s, $items);
            return;
        }
        if ($tag === 'img' || $tag === 'svg') {
            $margin = $this->edges($s['margin'], $w);
            $item = $tag === 'img' ? $this->imageItem($el, $s, $w) : $this->svgItem($el, $s, $w);
            if ($item !== null) {
                $item['margin'] = $margin;
                $item['w'] += $margin[1] + $margin[3];
                $item['h'] += $margin[0] + $margin[2];
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
            || $s['bgImg'] !== null || array_sum(array_column($s['border'], 'w')) > 0 || in_array($s['display'], ['inline-block', 'inline-flex'], true);

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
                'logicalText' => $this->inlineText($el, $s),
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

        $bidi = $s['unicodeBidi'];
        $open = match ($bidi) {
            'isolate', 'plaintext' => $s['direction'] === 'auto' ? "\u{2068}" : ($s['direction'] === 'rtl' ? "\u{2067}" : "\u{2066}"),
            'embed' => $s['direction'] === 'rtl' ? "\u{202B}" : "\u{202A}",
            'bidi-override' => $s['direction'] === 'rtl' ? "\u{202E}" : "\u{202D}",
            'isolate-override' => "\u{2068}" . ($s['direction'] === 'rtl' ? "\u{202E}" : "\u{202D}"),
            default => '',
        };
        if ($open !== '')
            $items[] = ['t' => 'w', 'x' => $open, 'w' => 0.0, 's' => $s, 'k' => ''];
        foreach ($el->childNodes as $node) {
            if ($node instanceof DOMText) {
                $this->collectText($node->nodeValue ?? '', $s, $items);
            } elseif ($node instanceof DOMElement) {
                $cs = $this->computeStyle($node, $s);
                if ($cs['display'] === 'none')
                    continue;
                $this->collectInline($node, $cs, $items, $w);
                if (!in_array($cs['display'], ['inline', 'inline-block', 'inline-flex', 'contents'], true)) {
                    $items[] = ['t' => 'br', 'w' => 0.0, 's' => $s, 'k' => 'br'];
                }
            }
        }

        if ($open !== '') {
            $close = match ($bidi) {
                'isolate', 'plaintext' => "\u{2069}",
                'isolate-override' => "\u{202C}\u{2069}",
                default => "\u{202C}",
            };
            $items[] = ['t' => 'w', 'x' => $close, 'w' => 0.0, 's' => $s, 'k' => ''];
        }
        if ($isLink) {
            array_pop($this->activeLinks);
        }
    }

    private function inlineText(DOMElement $element, array $style): string
    {
        $text = '';
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMText && $style['visibility'] === 'visible') {
                $text .= $child->nodeValue;
            } elseif ($child instanceof DOMElement) {
                $childStyle = $this->computeStyle($child, $style);
                if ($childStyle['display'] !== 'none')
                    $text .= $this->tag($child) === 'br' ? "\n" : $this->inlineText($child, $childStyle);
            }
        }
        return $text;
    }

    private function collectText(string $text, array $s, array &$items): void
    {
        if (!mb_check_encoding($text, 'UTF-8'))
            throw new InvalidArgumentException('Text must be valid UTF-8.');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (!$s['pre']) {
            $pattern = $s['whiteSpace'] === 'pre-line' ? '/[ \t\f]+/' : '/[ \t\r\n\f]+/';
            $text = preg_replace($pattern, ' ', $text) ?? $text;
            $last = $items ? $items[array_key_last($items)] : null;
            if ($last === null || ($last['t'] === 'br') || (isset($last['x']) && str_ends_with($last['x'], ' '))) {
                $text = ltrim($text, ' ');
            }
        } else {
            $text = str_replace("\t", '    ', $text);
        }
        if ($text === '')
            return;
        $items[] = [
            't' => 'w',
            'x' => $this->transform($text, $s),
            'w' => 0.0,
            's' => $s,
            'k' => '',
            'link' => $this->activeLinks ? end($this->activeLinks) : null
        ];
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
        $item = [
            't' => $type,
            'x' => $text,
            'w' => 0.0,
            's' => $s,
            'k' => '',
            'link' => $this->activeLinks ? end($this->activeLinks) : null
        ];
        $lines = $this->fontManager->layout([$item], 1e9, $s);
        $item['w'] = $lines[0]['w'] ?? 0.0;
        return $item;
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
        if ($s['writingMode'] !== 'horizontal-tb') {
            $this->drawVerticalText($items, $x, $w, $s);
            $items = [];
            return;
        }
        $lines = $this->breakLines($items, $w, $s);
        $items = [];
        $align ??= $s['align'];
        $last = array_key_last($lines);
        foreach ($lines as $i => $line) {
            $this->drawLine($line, $x, $w, $align, !$line['hard'] && $i !== $last);
        }
    }

    private function breakLines(array $items, float $w, array $s = []): array
    {
        return $this->fontManager->layout($items, $w, $s);
    }

    private function drawLine(array $line, float $x, float $w, string $align, bool $justify): void
    {
        if ($align === 'start' || $align === 'end') {
            $right = ($line['direction'] ?? 'ltr') === 'rtl';
            $align = ($right === ($align === 'start')) ? 'right' : 'left';
        }
        $ascent = 0.0;
        $descent = 0.0;
        foreach ($line['items'] as $it) {
            if ($it['t'] === 'img' || $it['t'] === 'svg' || $it['t'] === 'inline-box') {
                $ascent = max($ascent, $it['h'] - ($it['baseline'] ?? 0.0));
                $descent = max($descent, $it['baseline'] ?? 0.0);
                continue;
            }
            $size = $it['s']['size'];
            $metrics = $it['shaped']->data;
            $fontAscent = $metrics['ascent'];
            $fontDescent = $metrics['descent'];
            foreach ($metrics['glyphs'] as $glyph) {
                $fontAscent = max($fontAscent, $glyph['dy'] + $glyph['ink'][1]);
                $fontDescent = max($fontDescent, -$glyph['dy'] - $glyph['ink'][1] - $glyph['ink'][3]);
            }
            $lineHeight = $it['s']['lh'] === null ? $fontAscent + $fontDescent + $metrics['lineGap'] : $size * $it['s']['lh'];
            $half = ($lineHeight - $fontAscent - $fontDescent) / 2;
            $ascent = max($ascent, $half + $fontAscent + $it['s']['shift']);
            $descent = max($descent, $half + $fontDescent - $it['s']['shift']);
        }
        $height = $ascent + $descent;

        if ($this->allowBreak && $this->y + $height > $this->pageBottom + 0.01 && $this->y > $this->pageTop + 0.01) {
            $this->newPage();
        }
        $this->placeDestinations();
        $base = $this->y + $ascent;
        if ($this->lineOutsideClip($line['items'], $base)) {
            $this->y += $height;
            $this->marker = null;
            return;
        }
        $this->extent = max($this->extent, $x + $line['w']);

        if ($this->marker !== null) {
            $m = $this->marker;
            $this->marker = null;
            $mx = $x - $this->textItem($m['text'], $m['s'], 'w')['w'] - 0.4 * $m['s']['size'];
            $this->ops[] = $this->textOp($m['text'], $mx, $base, $m['s']);
        }

        $free = max(0.0, $w - $line['w']);
        $spaces = 0;
        foreach ($line['items'] as $item) {
            foreach ($item['shaped']->data['glyphs'] ?? [] as $glyph)
                $spaces += (int) $glyph['space'];
        }
        $extra = ($justify && $align === 'justify' && $spaces > 0) ? $free / $spaces : 0.0;
        $cx = $x + match ($align) {
            'center' => $free / 2,
            'right' => $free,
            default => 0.0,
        };

        $start = count($this->ops);
        $origin = $cx;
        $hasText = ($line['text'] ?? '') !== '';
        if ($hasText)
            $this->ops[] = $this->actualTextStart($line['text']);
        $run = null;
        foreach ($line['items'] as $it) {
            if ($it['t'] === 'br')
                continue;
            $spaceCount = 0;
            foreach ($it['shaped']->data['glyphs'] ?? [] as $glyph)
                $spaceCount += (int) $glyph['space'];
            $itemW = $it['w'] + $spaceCount * $extra;

            if ($it['t'] === 'img' || $it['t'] === 'svg') {
                $this->flushRun($run, $base);
                $run = null;
                $this->ops[] = $this->imageOp($it, $cx, $base - $it['h']);
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
            } else {
                $this->flushRun([
                    's' => $it['s'],
                    'text' => $it['x'] ?? '',
                    'shaped' => $it['shaped'],
                    'x' => $cx,
                    'w' => $itemW,
                    'extra' => $extra,
                    'link' => $it['link'] ?? null
                ], $base);
            }
            $cx += $itemW;
        }
        $this->flushRun($run, $base);
        if ($hasText)
            $this->ops[] = "EMC Q\n";
        if (!$this->measuring) {
            $appearance = implode('', array_splice($this->ops, $start));
            $this->ops[] = $this->logicalText->wrap(
                $line['text'] ?? '',
                $appearance,
                $origin,
                $this->pageH - $base,
                $cx - $origin,
                $ascent,
                $descent
            );
        }
        $this->y += $height;
    }

    private function lineOutsideClip(array $items, float $baseline): bool
    {
        $clips = array_filter($this->frames, static fn(array $frame): bool => $frame['s']['overflow'] !== 'visible' && $frame['clipH'] !== null && $frame['start'] !== null);
        if (!$clips)
            return false;
        $top = INF;
        $bottom = -INF;
        foreach ($items as $item) {
            if (isset($item['shaped'])) {
                foreach ($item['shaped']->data['glyphs'] as $glyph) {
                    if ($glyph['ink'][3] == 0)
                        continue;
                    $inkTop = $baseline - $item['s']['shift'] - $glyph['dy'] - $glyph['ink'][1];
                    $top = min($top, $inkTop);
                    $bottom = max($bottom, $inkTop - $glyph['ink'][3]);
                }
            } elseif (isset($item['h'])) {
                $top = min($top, $baseline - $item['h']);
                $bottom = max($bottom, $baseline + ($item['baseline'] ?? 0.0));
            }
        }
        foreach ($clips as $frame) {
            $clipTop = $frame['start'] + $frame['s']['border'][0]['w'];
            $clipBottom = $frame['start'] + $frame['clipH'] - $frame['s']['border'][2]['w'];
            if ($top >= $clipBottom || $bottom <= $clipTop)
                return true;
        }
        return false;
    }

    private function flushRun(?array $run, float $base): void
    {
        if ($run === null || $run['s']['visibility'] !== 'visible')
            return;
        $s = $run['s'];
        $size = $s['size'];
        $b = $base - $s['shift'];
        $out = '';

        // Scoped opacity ExtGState
        $hasAlpha = (($s['opacity'] ?? 1.0) < 0.999);
        if ($hasAlpha) {
            $gsName = $this->graphicState->getAlphaState((float) $s['opacity'], (float) $s['opacity']);
            $out .= "/$gsName gs\n";
        }

        // Inline background / Badge rendering
        if ($s['bg'] !== null || $s['radius'] > 0) {
            $badgeH = 1.15 * $size + ($s['padding'][0] ?? 0.0) + ($s['padding'][2] ?? 0.0);
            $badgeY = $b - 0.85 * $size - ($s['padding'][0] ?? 0.0);
            $badgeW = $run['w'] + ($s['padding'][1] ?? 0.0) + ($s['padding'][3] ?? 0.0);
            $badgeX = $run['x'] - ($s['padding'][3] ?? 0.0);
            $out .= InlineBox::renderBox($badgeX, $badgeY, $badgeW, $badgeH, $s, $this->pageH);
        }

        $out .= $this->shapedTextOp($run['shaped'], $run['x'], $b, $s, $run['extra']);
        $thickness = max(0.4, 0.06 * $size);
        if ($s['under']) {
            $out .= $this->fillOp($run['x'], $b + 0.1 * $size, $run['w'], $thickness, $s['color']);
        }
        if ($s['strike']) {
            $out .= $this->fillOp($run['x'], $b - 0.3 * $size, $run['w'], $thickness, $s['color']);
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
        $item = $this->tag($el) === 'svg' ? $this->svgItem($el, $s, $w) : $this->imageItem($el, $s, $w);
        if ($item === null)
            return;
        $align = $s['mAutoL'] && $s['mAutoR'] ? 'center' : ($s['mAutoL'] ? 'right' : 'left');
        $this->pending = max($this->pending, $mt);
        $items = [$item];
        $this->flushLines($items, $x + $ml, $w - $ml - $mr, $s, $align);
        $this->pending = max($this->pending, $mb);
    }

    private function svgItem(string|DOMElement $el, array $s, float $w): ?array
    {
        $rendered = SvgRenderer::render($el, 0, 0, null, null, $this->pageH);
        return $this->imageBox(['t' => 'svg', 'el' => $el, 'k' => 'svg'], $s, $w, $rendered['w'], $rendered['h']);
    }

    private function imageItem(DOMElement $el, array $s, float $w): ?array
    {
        $source = $el->getAttribute('src');
        if (preg_match('/\.svg(?:[?#].*)?$/i', $source) || str_starts_with($source, 'data:image/svg+xml')) {
            $svg = $this->readSource($source);
            if ($svg !== null)
                return $this->svgItem($svg, $s, $w);
        }
        $img = $this->loadImage($el->getAttribute('src'));
        if ($img === null) {
            $alt = $el->getAttribute('alt');
            if ($alt === '')
                return null;
            $width = $this->textItem($alt, $s, 'w')['w'];
            return $this->imageBox(['t' => 'img', 'alt' => $alt, 'k' => 'alt'], $s, $w, max(1.0, $width), $s['size'] * 1.4);
        }
        return $this->imageBox(['t' => 'img', 'res' => $img['name'], 'k' => 'img'], $s, $w, $img['w'] * self::PX, $img['h'] * self::PX);
    }

    private function imageBox(array $item, array $s, float $w, float $natW, float $natH): ?array
    {
        if ($natW <= 0 || $natH <= 0)
            return null;
        $parentHeight = $this->frames ? $this->frames[array_key_last($this->frames)]['contentH'] : $this->pageBottom - $this->pageTop;
        foreach (['height', 'minHeight', 'maxHeight'] as $dimension) {
            if (is_string($s[$dimension]) && ($parentHeight !== null || !str_contains($s[$dimension], '%')))
                $s[$dimension] = $this->px($s[$dimension], $parentHeight ?? 0.0);
        }
        [$pt, $pr, $pb, $pl] = $this->edges($s['padding'], $w);
        [$bt, $br, $bb, $bl] = array_column($s['border'], 'w');
        $horizontal = $pl + $pr + $bl + $br;
        $vertical = $pt + $pb + $bt + $bb;
        $widthExtra = $s['sizing'] === 'border' ? $horizontal : 0.0;
        $heightExtra = $s['sizing'] === 'border' ? $vertical : 0.0;
        $tw = $s['width'] === null ? null : max(0.0, $this->px($s['width'], $w) - $widthExtra);
        $th = is_float($s['height']) ? max(0.0, $s['height'] - $heightExtra) : null;
        $ratio = $s['aspectRatio'] ?? ($natW / $natH);

        if ($tw !== null && $th === null) {
            $th = $tw / $ratio;
        } elseif ($th !== null && $tw === null) {
            $tw = $th * $ratio;
        } elseif ($tw === null) {
            [$tw, $th] = [$natW, $natH];
        }
        $maxWidth = $s['maxWidth'] === null ? INF : max(0.0, $this->px($s['maxWidth'], $w) - $widthExtra);
        if ($s['width'] === null) {
            [, $mr, , $ml] = $this->edges($s['margin'], $w);
            $maxWidth = min($maxWidth, max(0.0, $w - $horizontal - $ml - $mr));
        }
        $minWidth = $s['minWidth'] === null ? 0.0 : max(0.0, $this->px($s['minWidth'], $w) - $widthExtra);
        $width = max($minWidth, min($tw, $maxWidth));
        if (!is_float($s['height']) && $tw > 0)
            $th *= $width / $tw;
        $tw = $width;
        $maxHeight = is_float($s['maxHeight']) ? max(0.0, $s['maxHeight'] - $heightExtra) : INF;
        $minHeight = is_float($s['minHeight']) ? max(0.0, $s['minHeight'] - $heightExtra) : 0.0;
        $height = max($minHeight, min($th, $maxHeight));
        if ($s['width'] === null && $th > 0)
            $tw *= $height / $th;
        $th = $height;

        return $item + [
            'w' => $tw + $horizontal,
            'h' => $th + $vertical,
            'contentW' => $tw,
            'contentH' => $th,
            'naturalW' => $natW,
            'naturalH' => $natH,
            'boxW' => $tw + $horizontal,
            'boxH' => $th + $vertical,
            'dx' => $pl + $bl,
            'dy' => $pt + $bt,
            's' => $s,
            'link' => $this->activeLinks ? end($this->activeLinks) : null,
        ];
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
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
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
        if ($w <= 0 || $h <= 0 || ($s['visibility'] ?? 'visible') !== 'visible')
            return '';
        $out = '';
        $radius = $this->px($s['radius'], min($w, $h));

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
        if (($radius > 0 || $b[0]['s'] === 'double') && $uniform && !in_array(false, $sides, true) && !in_array($b[0]['s'], ['inset', 'outset', 'groove', 'ridge'], true)) {
            $bw = $b[0]['w'];
            foreach (BorderStyle::bands($b[0]['s'], $bw, $b[0]['c'], 0) as [$offset, $stroke, $color]) {
                $inset = $offset + $stroke / 2;
                $out .= sprintf(
                    "q %s%s RG %s w %s %s S Q\n",
                    $this->colorAlpha($color),
                    $this->rg($color),
                    $this->n($stroke),
                    $this->dash($b[0]['s'], $bw),
                    $this->roundRect($x + $inset, $y + $inset, max(0, $w - 2 * $inset), max(0, $h - 2 * $inset), max(0.0, $radius - $inset)),
                );
            }
        } else {
            foreach ($b as $i => $side) {
                $bw = $side['w'];
                if ($bw <= 0 || !$sides[$i])
                    continue;
                foreach (BorderStyle::bands($side['s'], $bw, $side['c'], $i) as [$offset, $stroke, $color]) {
                    $o = $offset + $stroke / 2 - ($centered ? $bw / 2 : 0.0);
                    [$x1, $y1, $x2, $y2] = match ($i) {
                        0 => [$x, $y + $o, $x + $w, $y + $o],
                        1 => [$x + $w - $o, $y, $x + $w - $o, $y + $h],
                        2 => [$x, $y + $h - $o, $x + $w, $y + $h - $o],
                        default => [$x + $o, $y, $x + $o, $y + $h],
                    };
                    $out .= sprintf(
                        "q %s%s RG %s w %s %s %s m %s %s l S Q\n",
                        $this->colorAlpha($color),
                        $this->rg($color),
                        $this->n($stroke),
                        $this->dash($side['s'], $bw),
                        $this->n($x1),
                        $this->n($this->pageH - $y1),
                        $this->n($x2),
                        $this->n($this->pageH - $y2),
                    );
                }
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
        $s = $item['s'];
        if ($s['visibility'] !== 'visible')
            return '';
        $x += $item['margin'][3] ?? 0.0;
        $top += $item['margin'][0] ?? 0.0;
        $width = $item['boxW'];
        $height = $item['boxH'];
        $radius = $this->px($s['radius'], min($width, $height));
        $out = $this->boxOps($x, $top, $width, $height, ['border' => self::RESET['border']] + $s);
        if ($s['bgImg'] !== null && ($background = $this->loadImage($s['bgImg'])) !== null) {
            $bg = new BackgroundImage(
                $background['name'],
                $background['w'] * self::PX,
                $background['h'] * self::PX,
                $s['bgRepeat'],
                $s['bgPos'],
                $s['bgSize'],
                $s['opacity']
            );
            $out .= $bg->render($x, $top, $width, $height, $radius, $this->pageH, $this->graphicState);
        }
        if ($item['contentW'] > 0 && $item['contentH'] > 0) {
            $contentX = $x + $item['dx'];
            $contentY = $top + $item['dy'];
            $drawW = $item['contentW'];
            $drawH = $item['contentH'];
            if ($s['objectFit'] !== 'fill') {
                $scale = match ($s['objectFit']) {
                    'cover' => max($drawW / $item['naturalW'], $drawH / $item['naturalH']),
                    'contain' => min($drawW / $item['naturalW'], $drawH / $item['naturalH']),
                    'scale-down' => min(1.0, $drawW / $item['naturalW'], $drawH / $item['naturalH']),
                    default => 1.0,
                };
                $drawW = $item['naturalW'] * $scale;
                $drawH = $item['naturalH'] * $scale;
            }
            $position = new BackgroundImage('', $drawW, $drawH, position: $s['objectPosition']);
            [$drawX, $drawY] = $position->calculatePosition($contentX, $contentY, $item['contentW'], $item['contentH'], $drawW, $drawH);
            $state = $this->graphicState->getAlphaState($s['opacity'], $s['opacity']);
            $out .= "q /$state gs\n";
            $contentRadius = max(0.0, $radius - max($item['dx'], $item['dy']));
            $out .= $this->roundRect($contentX, $contentY, $item['contentW'], $item['contentH'], $contentRadius) . " W n\n";
            if (isset($item['alt'])) {
                $font = $this->fontManager->getFont($s['family'], $s['bold'], $s['italic']);
                $out .= $this->textOp($item['alt'], $contentX, $contentY + $font->getAscender($s['size']), $s);
            } elseif ($item['t'] === 'svg') {
                $out .= SvgRenderer::render($item['el'], $drawX, $drawY, $drawW, $drawH, $this->pageH)['ops'];
            } else {
                $out .= sprintf(
                    "%s 0 0 %s %s %s cm /%s Do\n",
                    $this->n($drawW),
                    $this->n($drawH),
                    $this->n($drawX),
                    $this->n($this->pageH - $drawY - $drawH),
                    $item['res']
                );
            }
            $out .= "Q\n";
        }
        $out .= $this->boxOps($x, $top, $width, $height, ['bg' => null] + $s);
        if (!empty($item['link']) && !$this->measuring) {
            $this->pageAnnots[] = ['rect' => [$x, $top, $width, $height], 'url' => $item['link']];
        }
        return $out;
    }

    private function actualTextStart(string $text): string
    {
        return 'q /Span << /ActualText <FEFF' . strtoupper(bin2hex(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8'))) . "> >> BDC\n";
    }

    private function textOp(string $text, float $x, float $baseline, array $s, float $wordSpace = 0.0): string
    {
        if ($s['visibility'] !== 'visible')
            return '';
        $item = ['t' => 'w', 'x' => $text, 'w' => 0.0, 's' => $s, 'k' => ''];
        $lines = $this->fontManager->layout([$item], 1e9, $s);
        $origin = $x;
        $out = $this->actualTextStart($text);
        foreach ($lines as $line) {
            foreach ($line['items'] as $run) {
                $out .= $this->shapedTextOp($run['shaped'], $x, $baseline, $s, $wordSpace);
                $x += $run['w'];
            }
        }
        $out .= "EMC Q\n";
        return $this->measuring ? $out : $this->logicalText->wrap(
            $text,
            $out,
            $origin,
            $this->pageH - $baseline,
            $x - $origin,
            $s['size'],
            $s['size'] * .4
        );
    }

    private function shapedTextOp(ShapedRun $run, float $x, float $baseline, array $s, float $wordSpace = 0.0): string
    {
        if ($s['visibility'] !== 'visible')
            return '';
        $data = $run->data;
        $font = $this->fontManager->byId($data['font']);
        $resource = $this->fontManager->getResourceName($font);
        $alpha = ($s['color'][3] ?? 1.0) * ($s['opacity'] ?? 1.0);
        $state = $this->graphicState->getAlphaState($alpha, $alpha);
        $out = "/$state gs\n";
        $out .= 'BT /' . $resource . ' ' . $this->n($s['size']) . ' Tf ' . $this->rg($s['color']) . " rg 0 Tc 0 Tw\n";
        $cursor = 0.0;
        $crossCursor = 0.0;
        $seen = [];
        $images = '';
        foreach ($data['glyphs'] as $glyph) {
            $cluster = $glyph['cluster'];
            $unicode = isset($seen[$cluster]) ? '' : $glyph['text'];
            $seen[$cluster] = true;
            if ($data['vertical']) {
                $gx = $x + $crossCursor + $glyph['dx'];
                $gy = $this->pageH - $baseline - $cursor + $glyph['dy'];
                $matrix = '1 0 0 1';
            } elseif ($data['sideways']) {
                $gx = $x + $crossCursor + $glyph['dy'] - 0.35 * $s['size'];
                $gy = $this->pageH - $baseline - $cursor - $glyph['dx'];
                $matrix = '0 -1 1 0';
            } else {
                $gx = $x + $cursor + $glyph['dx'];
                $gy = $this->pageH - $baseline + $crossCursor + $glyph['dy'];
                $matrix = '1 0 0 1';
            }
            $color = $font->metadata['color'] ? $this->colorGlyphImage($data['font'], $glyph['gid'], $s) : null;
            $cid = $font->cid($glyph['gid'], $unicode);
            $out .= ($color === null ? '0' : '3') . ' Tr ' . $matrix . ' ' . $this->n($gx) . ' ' . $this->n($gy) . " Tm $cid Tj\n";
            if ($color !== null) {
                $images .= sprintf(
                    "q %s %s %s cm %s 0 0 %s %s %s cm /%s Do Q\n",
                    $matrix,
                    $this->n($gx),
                    $this->n($gy),
                    $this->n($color['drawW']),
                    $this->n($color['drawH']),
                    $this->n($color['left']),
                    $this->n(-$color['top'] - $color['drawH']),
                    $color['name']
                );
            }
            $cursor += $glyph['advance'] + ($glyph['space'] ? $wordSpace : 0.0);
            $crossCursor += $data['vertical'] ? $glyph['ax'] : $glyph['ay'];
        }
        return $out . "ET\n" . $images;
    }

    private function colorGlyphImage(int $font, int $glyph, array $s): ?array
    {
        $key = 'color:' . hash('sha256', json_encode([$font, $glyph, $s['size'], $s['color'], $this->textOptions->colorGlyphDpi], JSON_THROW_ON_ERROR));
        if (array_key_exists($key, $this->imageCache))
            return $this->imageCache[$key];
        $result = $this->fontManager->helper()->request('color', [
            'font' => $font,
            'gid' => $glyph,
            'size' => $s['size'],
            'dpi' => $this->textOptions->colorGlyphDpi,
            'color' => array_slice($s['color'], 0, 3)
        ]);
        if ($result === null)
            return $this->imageCache[$key] = null;
        $width = $result['width'];
        $height = $result['height'];
        $rgb = base64_decode($result['rgb'], true);
        $alpha = base64_decode($result['alpha'], true);
        if ($rgb === false || $alpha === false || strlen($rgb) !== $width * $height * 3 || strlen($alpha) !== $width * $height) {
            throw new RuntimeException('Invalid color glyph raster.');
        }
        $entry = [
            'name' => 'I' . (count($this->images) + 1),
            'w' => $width,
            'h' => $height,
            'data' => gzcompress($rgb, 6),
            'smask' => gzcompress($alpha, 6),
            'dict' => "/Type /XObject /Subtype /Image /Width $width /Height $height /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode",
            'left' => $result['left'],
            'top' => $result['top'],
            'drawW' => $result['w'],
            'drawH' => $result['h']
        ];
        $this->images[$key] = $entry;
        return $this->imageCache[$key] = $entry;
    }

    private function drawVerticalText(array $items, float $x, float $width, array $s): void
    {
        $inlineSize = $s['height'] !== null ? $this->px($s['height'], $this->pageH) : max($s['size'], $this->pageBottom - $this->y);
        $lines = $this->fontManager->layout($items, $inlineSize, $s);
        $top = $this->y;
        $used = 0.0;
        $extent = 0.0;
        $blockStart = count($this->ops);
        $blockText = '';
        $flushColumns = function () use (&$blockStart, &$blockText, &$used, &$extent, &$top, $x, $width, $s): void {
            if ($this->measuring || $blockText === '')
                return;
            $appearance = implode('', array_splice($this->ops, $blockStart));
            $left = $s['writingMode'] === 'vertical-rl' ? $x + $width - $used : $x;
            $this->ops[] = $this->logicalText->wrap(
                $blockText,
                $appearance,
                $left,
                $this->pageH - $top - $extent,
                $used,
                $extent,
                0
            );
        };
        foreach ($lines as $line) {
            $column = $s['size'] * ($s['lh'] ?? 1.4);
            foreach ($line['items'] as $item) {
                $column = max($column, $item['s']['size'] * ($item['s']['lh'] ?? 1.4));
                if (!isset($item['shaped']))
                    $column = max($column, $item['w']);
            }
            if ($used > 0 && $used + $column > $width + .01) {
                if (!$this->allowBreak)
                    throw new RuntimeException('Vertical text exceeds the available block width.');
                $flushColumns();
                $this->newPage();
                $blockStart = count($this->ops);
                $blockText = '';
                $top = $this->y;
                $used = 0.0;
                $extent = 0.0;
            }
            $cx = $s['writingMode'] === 'vertical-rl' ? $x + $width - $used - $column / 2 : $x + $used + $column / 2;
            $free = max(0.0, $inlineSize - $line['w']);
            $cy = $top + match ($s['align']) { 'center' => $free / 2, 'right' => $free, default => 0.0};
            $blockText .= $line['text'] . $line['separator'];
            $this->ops[] = $this->actualTextStart($line['text']);
            foreach ($line['items'] as $item) {
                if (isset($item['shaped'])) {
                    $this->ops[] = $this->shapedTextOp($item['shaped'], $cx, $cy, $item['s']);
                    if (!empty($item['link']) && !$this->measuring && $item['s']['visibility'] === 'visible') {
                        $this->pageAnnots[] = ['rect' => [$cx - $column / 2, $cy, $column, $item['w']], 'url' => $item['link']];
                    }
                } elseif ($item['t'] === 'img' || $item['t'] === 'svg') {
                    $this->ops[] = $this->imageOp($item, $cx - $item['w'] / 2, $cy);
                } elseif ($item['t'] === 'inline-box') {
                    $saved = [$this->y, $this->pending, $this->allowBreak];
                    $this->y = $cy;
                    $this->pending = 0.0;
                    $this->allowBreak = false;
                    if ($item['link'] !== null)
                        $this->activeLinks[] = $item['link'];
                    try {
                        $this->renderBox($item['el'], $item['s'], $cx - $item['w'] / 2, $item['w']);
                    } finally {
                        if ($item['link'] !== null)
                            array_pop($this->activeLinks);
                        [$this->y, $this->pending, $this->allowBreak] = $saved;
                    }
                }
                $cy += $item['advance'];
            }
            $this->ops[] = "EMC Q\n";
            $used += $column;
            $extent = max($extent, $cy - $top);
        }
        $flushColumns();
        $this->extent = max($this->extent, $x + $used);
        $this->y = $top + $extent;
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
