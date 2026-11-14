<?php

declare(strict_types=1);

namespace Spark\Pdf\Graphics;

use DOMDocument;
use DOMElement;
use DOMNode;
use function count;
use function in_array;
use function sprintf;

/**
 * Pure PHP vector SVG renderer.
 * Converts <svg> elements and SVG shapes/paths directly into PDF drawing operators.
 */
final class SvgRenderer
{
    /**
     * Renders SVG XML or <svg> DOMElement into PDF graphics stream operators.
     *
     * @param string|DOMElement $svg XML string or DOMElement
     * @param float $x Target X position in pt
     * @param float $y Target Y position from top of page in pt
     * @param float|null $targetW Desired width in pt (scales viewBox to fit)
     * @param float|null $targetH Desired height in pt
     * @param float $pageH Total page height in pt
     * @return array{ops: string, w: float, h: float} PDF operators and actual dimensions
     */
    public static function render(
        string|DOMElement $svg,
        float $x,
        float $y,
        ?float $targetW,
        ?float $targetH,
        float $pageH
    ): array {
        $el = self::getSvgElement($svg);
        if ($el === null) {
            return ['ops' => '', 'w' => 0.0, 'h' => 0.0];
        }

        $viewBox = self::parseViewBox($el);
        $natW = (float) ($el->getAttribute('width') ?: ($viewBox['w'] ?? 100.0));
        $natH = (float) ($el->getAttribute('height') ?: ($viewBox['h'] ?? 100.0));

        // Scale to desired width and height
        if ($targetW !== null && $targetH === null) {
            $w = $targetW;
            $h = $natW > 0 ? $w * ($natH / $natW) : $natH;
        } elseif ($targetH !== null && $targetW === null) {
            $h = $targetH;
            $w = $natH > 0 ? $h * ($natW / $natH) : $natW;
        } elseif ($targetW !== null && $targetH !== null) {
            $w = $targetW;
            $h = $targetH;
        } else {
            $w = $natW * 0.75; // px to pt
            $h = $natH * 0.75;
        }

        $scaleX = $viewBox['w'] > 0 ? $w / $viewBox['w'] : 1.0;
        $scaleY = $viewBox['h'] > 0 ? $h / $viewBox['h'] : 1.0;
        if ($w <= 0 || $h <= 0)
            return ['ops' => '', 'w' => 0.0, 'h' => 0.0];
        $offsetX = $offsetY = 0.0;
        $aspect = $el->getAttribute('preserveAspectRatio') ?: $el->getAttribute('preserveaspectratio');
        if (strtolower(trim($aspect)) !== 'none') {
            $scale = str_contains($aspect, 'slice') ? max($scaleX, $scaleY) : min($scaleX, $scaleY);
            $offsetX = ($w - $viewBox['w'] * $scale) / 2;
            $offsetY = ($h - $viewBox['h'] * $scale) / 2;
            $scaleX = $scaleY = $scale;
        }

        $ops = "q\n";
        $ops .= sprintf("%.3F %.3F %.3F %.3F re W n\n", $x, $pageH - $y - $h, $w, $h);
        // SVG coordinate system: (0,0) top-left, Y points down
        // PDF coordinate system: (0,0) bottom-left, Y points up
        // Transform matrix: translate to (x, pageH - y), flip Y, scale
        $pdfOriginY = $pageH - $y;
        $ops .= sprintf("1 0 0 -1 %.3F %.3F cm\n", $x, $pdfOriginY);
        $ops .= sprintf("%.5F 0 0 %.5F %.3F %.3F cm\n", $scaleX, $scaleY, $offsetX - $viewBox['x'] * $scaleX, $offsetY - $viewBox['y'] * $scaleY);

        $defaultStyle = [
            'fill' => Color::parse($el->getAttribute('fill') ?: '#000000'),
            'stroke' => Color::parse($el->getAttribute('stroke') ?: null),
            'stroke-width' => (float) ($el->getAttribute('stroke-width') ?: 1.0),
        ];

        $ops .= self::renderChildren($el, $defaultStyle);
        $ops .= "Q\n";

        return ['ops' => $ops, 'w' => $w, 'h' => $h];
    }

    private static function getSvgElement(string|DOMElement $svg): ?DOMElement
    {
        if ($svg instanceof DOMElement) {
            return strtolower($svg->tagName) === 'svg' ? $svg : null;
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $clean = preg_replace('/<\?xml.*?\?>/i', '', trim($svg)) ?? trim($svg);
        $loaded = $doc->loadXML($clean, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded || !$doc->documentElement) {
            return null;
        }
        return strtolower($doc->documentElement->tagName) === 'svg' ? $doc->documentElement : null;
    }

    /**
     * @return array{x: float, y: float, w: float, h: float}
     */
    private static function parseViewBox(DOMElement $el): array
    {
        $vb = trim($el->getAttribute('viewBox') ?: $el->getAttribute('viewbox'));
        if ($vb !== '') {
            $parts = preg_split('/[\s,]+/', $vb);
            if (count($parts) >= 4) {
                return [
                    'x' => (float) $parts[0],
                    'y' => (float) $parts[1],
                    'w' => (float) $parts[2],
                    'h' => (float) $parts[3],
                ];
            }
        }
        $w = (float) ($el->getAttribute('width') ?: 100.0);
        $h = (float) ($el->getAttribute('height') ?: 100.0);
        return ['x' => 0.0, 'y' => 0.0, 'w' => $w, 'h' => $h];
    }

    private static function renderChildren(DOMNode $parent, array $parentStyle): string
    {
        $ops = '';
        foreach ($parent->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            $style = self::computeStyle($node, $parentStyle);

            $transform = trim($node->getAttribute('transform'));
            $hasTransform = $transform !== '';
            if ($hasTransform) {
                $ops .= "q\n" . self::parseTransform($transform);
            }

            $ops .= match ($tag) {
                'path' => self::renderPath($node, $style),
                'rect' => self::renderRect($node, $style),
                'circle' => self::renderCircle($node, $style),
                'ellipse' => self::renderEllipse($node, $style),
                'line' => self::renderLine($node, $style),
                'polyline' => self::renderPoly($node, $style, false),
                'polygon' => self::renderPoly($node, $style, true),
                'g' => self::renderChildren($node, $style),
                default => '',
            };

            if ($hasTransform) {
                $ops .= "Q\n";
            }
        }
        return $ops;
    }

    private static function computeStyle(DOMElement $el, array $parent): array
    {
        $s = $parent;
        if ($el->hasAttribute('fill')) {
            $s['fill'] = Color::parse($el->getAttribute('fill'));
        }
        if ($el->hasAttribute('stroke')) {
            $s['stroke'] = Color::parse($el->getAttribute('stroke'));
        }
        if ($el->hasAttribute('stroke-width')) {
            $s['stroke-width'] = (float) $el->getAttribute('stroke-width');
        }

        // Inline style overrides
        $inline = $el->getAttribute('style');
        if ($inline !== '') {
            foreach (explode(';', $inline) as $decl) {
                if (!str_contains($decl, ':'))
                    continue;
                [$k, $v] = explode(':', $decl, 2);
                $k = strtolower(trim($k));
                $v = trim($v);
                if ($k === 'fill')
                    $s['fill'] = Color::parse($v);
                if ($k === 'stroke')
                    $s['stroke'] = Color::parse($v);
                if ($k === 'stroke-width')
                    $s['stroke-width'] = (float) $v;
            }
        }
        return $s;
    }

    private static function parseTransform(string $transform): string
    {
        $ops = '';
        if (preg_match_all('/(translate|scale|rotate|matrix)\(([^)]+)\)/i', $transform, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $type = strtolower($m[1]);
                $args = preg_split('/[\s,]+/', trim($m[2]));
                if ($type === 'translate') {
                    $tx = (float) ($args[0] ?? 0);
                    $ty = (float) ($args[1] ?? 0);
                    $ops .= sprintf("1 0 0 1 %.3F %.3F cm\n", $tx, $ty);
                } elseif ($type === 'scale') {
                    $sx = (float) ($args[0] ?? 1);
                    $sy = (float) ($args[1] ?? $sx);
                    $ops .= sprintf("%.5F 0 0 %.5F 0 0 cm\n", $sx, $sy);
                } elseif ($type === 'rotate') {
                    $deg = (float) ($args[0] ?? 0);
                    $rad = deg2rad($deg);
                    $cos = cos($rad);
                    $sin = sin($rad);
                    $ops .= sprintf("%.5F %.5F %.5F %.5F 0 0 cm\n", $cos, $sin, -$sin, $cos);
                } elseif ($type === 'matrix' && count($args) >= 6) {
                    $ops .= sprintf(
                        "%.5F %.5F %.5F %.5F %.3F %.3F cm\n",
                        (float) $args[0],
                        (float) $args[1],
                        (float) $args[2],
                        (float) $args[3],
                        (float) $args[4],
                        (float) $args[5]
                    );
                }
            }
        }
        return $ops;
    }

    private static function renderPath(DOMElement $el, array $style): string
    {
        $d = trim($el->getAttribute('d'));
        if ($d === '')
            return '';

        $pathOps = self::compileSvgPath($d);
        if ($pathOps === '')
            return '';

        return self::paintPath($pathOps, $style);
    }

    private static function compileSvgPath(string $d): string
    {
        // Tokenize SVG path commands and numbers
        preg_match_all('/[+-]?(?:\d*\.\d+|\d+\.?\d*)(?:[eE][+-]?\d+)?|[a-zA-Z]/', $d, $tokens);
        $items = $tokens[0];
        if (!$items || !in_array($items[0], ['M', 'm'], true))
            return '';
        $count = count($items);
        $i = 0;

        $ops = '';
        $curX = 0.0;
        $curY = 0.0;
        $startX = 0.0;
        $startY = 0.0;
        $prevCmd = '';
        $lastCtrlX = 0.0;
        $lastCtrlY = 0.0;

        while ($i < $count) {
            $token = $items[$i++];
            if (preg_match('/^[MmLlHhVvCcSsQqTtAaZz]$/', $token)) {
                $cmd = $token;
            } else {
                // Continuation of previous command
                $cmd = $prevCmd === 'M' ? 'L' : ($prevCmd === 'm' ? 'l' : $prevCmd);
                $i--; // Push token back
            }
            $previous = strtoupper($prevCmd);
            $operandCount = match (strtoupper($cmd)) {
                'M', 'L', 'T' => 2, 'H', 'V' => 1, 'C' => 6, 'S', 'Q' => 4, 'A' => 7, 'Z' => 0,
                default => -1,
            };
            if ($operandCount < 0 || ($operandCount === 0 && !ctype_alpha($token)) || $i + $operandCount > $count) {
                return '';
            }
            for ($j = 0; $j < $operandCount; $j++) {
                if (!is_numeric($items[$i + $j]) || !is_finite((float) $items[$i + $j]))
                    return '';
            }
            $prevCmd = $cmd;

            switch ($cmd) {
                case 'M':
                case 'm':
                    $rel = $cmd === 'm';
                    $nx = (float) ($items[$i++] ?? 0) + ($rel ? $curX : 0.0);
                    $ny = (float) ($items[$i++] ?? 0) + ($rel ? $curY : 0.0);
                    $curX = $startX = $nx;
                    $curY = $startY = $ny;
                    $ops .= sprintf("%.3F %.3F m ", $curX, $curY);
                    break;
                case 'L':
                case 'l':
                    $rel = $cmd === 'l';
                    $curX = (float) ($items[$i++] ?? 0) + ($rel ? $curX : 0.0);
                    $curY = (float) ($items[$i++] ?? 0) + ($rel ? $curY : 0.0);
                    $ops .= sprintf("%.3F %.3F l ", $curX, $curY);
                    break;
                case 'H':
                case 'h':
                    $curX = (float) ($items[$i++] ?? 0) + ($cmd === 'h' ? $curX : 0.0);
                    $ops .= sprintf("%.3F %.3F l ", $curX, $curY);
                    break;
                case 'V':
                case 'v':
                    $curY = (float) ($items[$i++] ?? 0) + ($cmd === 'v' ? $curY : 0.0);
                    $ops .= sprintf("%.3F %.3F l ", $curX, $curY);
                    break;
                case 'C':
                case 'c':
                    $rel = $cmd === 'c';
                    $x1 = (float) ($items[$i++] ?? 0) + ($rel ? $curX : 0.0);
                    $y1 = (float) ($items[$i++] ?? 0) + ($rel ? $curY : 0.0);
                    $x2 = (float) ($items[$i++] ?? 0) + ($rel ? $curX : 0.0);
                    $y2 = (float) ($items[$i++] ?? 0) + ($rel ? $curY : 0.0);
                    $x = (float) ($items[$i++] ?? 0) + ($rel ? $curX : 0.0);
                    $y = (float) ($items[$i++] ?? 0) + ($rel ? $curY : 0.0);
                    $curX = $x;
                    $curY = $y;
                    $lastCtrlX = $x2;
                    $lastCtrlY = $y2;
                    $ops .= sprintf("%.3F %.3F %.3F %.3F %.3F %.3F c ", $x1, $y1, $x2, $y2, $x, $y);
                    break;
                case 'S':
                case 's':
                    $rel = $cmd === 's';
                    $x1 = in_array($previous, ['C', 'S'], true) ? 2 * $curX - $lastCtrlX : $curX;
                    $y1 = in_array($previous, ['C', 'S'], true) ? 2 * $curY - $lastCtrlY : $curY;
                    $x2 = (float) ($items[$i++] ?? 0) + ($rel ? $curX : 0.0);
                    $y2 = (float) ($items[$i++] ?? 0) + ($rel ? $curY : 0.0);
                    $x = (float) ($items[$i++] ?? 0) + ($rel ? $curX : 0.0);
                    $y = (float) ($items[$i++] ?? 0) + ($rel ? $curY : 0.0);
                    $curX = $x;
                    $curY = $y;
                    $lastCtrlX = $x2;
                    $lastCtrlY = $y2;
                    $ops .= sprintf("%.3F %.3F %.3F %.3F %.3F %.3F c ", $x1, $y1, $x2, $y2, $x, $y);
                    break;
                case 'Q':
                case 'q':
                case 'T':
                case 't':
                    $rel = ctype_lower($cmd);
                    if (strtoupper($cmd) === 'Q') {
                        $controlX = (float) $items[$i++] + ($rel ? $curX : 0.0);
                        $controlY = (float) $items[$i++] + ($rel ? $curY : 0.0);
                    } else {
                        $controlX = in_array($previous, ['Q', 'T'], true) ? 2 * $curX - $lastCtrlX : $curX;
                        $controlY = in_array($previous, ['Q', 'T'], true) ? 2 * $curY - $lastCtrlY : $curY;
                    }
                    $x = (float) $items[$i++] + ($rel ? $curX : 0.0);
                    $y = (float) $items[$i++] + ($rel ? $curY : 0.0);
                    $ops .= sprintf(
                        '%.3F %.3F %.3F %.3F %.3F %.3F c ',
                        $curX + 2 / 3 * ($controlX - $curX),
                        $curY + 2 / 3 * ($controlY - $curY),
                        $x + 2 / 3 * ($controlX - $x),
                        $y + 2 / 3 * ($controlY - $y),
                        $x,
                        $y
                    );
                    [$curX, $curY, $lastCtrlX, $lastCtrlY] = [$x, $y, $controlX, $controlY];
                    break;
                case 'A':
                case 'a':
                    $rx = abs((float) $items[$i++]);
                    $ry = abs((float) $items[$i++]);
                    $angle = (float) $items[$i++];
                    $large = (bool) (int) $items[$i++];
                    $sweep = (bool) (int) $items[$i++];
                    $x = (float) $items[$i++] + ($cmd === 'a' ? $curX : 0.0);
                    $y = (float) $items[$i++] + ($cmd === 'a' ? $curY : 0.0);
                    $ops .= self::arcPath($curX, $curY, $rx, $ry, $angle, $large, $sweep, $x, $y);
                    [$curX, $curY] = [$x, $y];
                    break;
                case 'Z':
                case 'z':
                    $ops .= "h ";
                    $curX = $startX;
                    $curY = $startY;
                    break;
            }
        }
        return trim($ops);
    }

    private static function arcPath(float $x1, float $y1, float $rx, float $ry, float $angle, bool $large, bool $sweep, float $x2, float $y2): string
    {
        if ($x1 === $x2 && $y1 === $y2)
            return '';
        if ($rx === 0.0 || $ry === 0.0)
            return sprintf('%.3F %.3F l ', $x2, $y2);
        // SVG endpoint-to-center conversion, followed by cubic segments of at most 90 degrees.
        $rotation = deg2rad(fmod($angle, 360));
        $cos = cos($rotation);
        $sin = sin($rotation);
        $dx = ($x1 - $x2) / 2;
        $dy = ($y1 - $y2) / 2;
        $px = $cos * $dx + $sin * $dy;
        $py = -$sin * $dx + $cos * $dy;
        $scale = sqrt(($px / $rx) ** 2 + ($py / $ry) ** 2);
        if ($scale > 1) {
            $rx *= $scale;
            $ry *= $scale;
        }
        $denominator = ($rx * $py) ** 2 + ($ry * $px) ** 2;
        if ($denominator <= 0 || !is_finite($denominator))
            return sprintf('%.3F %.3F l ', $x2, $y2);
        $factor = sqrt(max(0.0, (($rx * $ry) ** 2 - $denominator) / $denominator));
        if ($large === $sweep)
            $factor = -$factor;
        $centerX = $factor * $rx * $py / $ry;
        $centerY = -$factor * $ry * $px / $rx;
        $cx = $cos * $centerX - $sin * $centerY + ($x1 + $x2) / 2;
        $cy = $sin * $centerX + $cos * $centerY + ($y1 + $y2) / 2;
        $start = atan2(($py - $centerY) / $ry, ($px - $centerX) / $rx);
        $end = atan2((-$py - $centerY) / $ry, (-$px - $centerX) / $rx);
        $delta = $end - $start;
        if ($sweep && $delta < 0)
            $delta += 2 * M_PI;
        if (!$sweep && $delta > 0)
            $delta -= 2 * M_PI;
        $segments = max(1, (int) ceil(abs($delta) / (M_PI / 2)));
        $step = $delta / $segments;
        $point = static fn(float $x, float $y): array => [$cx + $rx * $cos * $x - $ry * $sin * $y, $cy + $rx * $sin * $x + $ry * $cos * $y];
        $ops = '';
        for ($i = 0; $i < $segments; $i++) {
            $a = $start + $i * $step;
            $b = $a + $step;
            $k = 4 / 3 * tan(($b - $a) / 4);
            $control1 = $point(cos($a) - $k * sin($a), sin($a) + $k * cos($a));
            $control2 = $point(cos($b) + $k * sin($b), sin($b) - $k * cos($b));
            $endPoint = $point(cos($b), sin($b));
            $ops .= sprintf('%.3F %.3F %.3F %.3F %.3F %.3F c ', ...array_merge($control1, $control2, $endPoint));
        }
        return $ops;
    }

    private static function renderRect(DOMElement $el, array $style): string
    {
        $x = (float) $el->getAttribute('x');
        $y = (float) $el->getAttribute('y');
        $w = (float) $el->getAttribute('width');
        $h = (float) $el->getAttribute('height');
        if ($w <= 0 || $h <= 0)
            return '';
        $pathOps = sprintf("%.3F %.3F %.3F %.3F re", $x, $y, $w, $h);
        return self::paintPath($pathOps, $style);
    }

    private static function renderCircle(DOMElement $el, array $style): string
    {
        $cx = (float) $el->getAttribute('cx');
        $cy = (float) $el->getAttribute('cy');
        $r = (float) $el->getAttribute('r');
        if ($r <= 0)
            return '';
        return self::renderEllipseHelper($cx, $cy, $r, $r, $style);
    }

    private static function renderEllipse(DOMElement $el, array $style): string
    {
        $cx = (float) $el->getAttribute('cx');
        $cy = (float) $el->getAttribute('cy');
        $rx = (float) $el->getAttribute('rx');
        $ry = (float) $el->getAttribute('ry');
        if ($rx <= 0 || $ry <= 0)
            return '';
        return self::renderEllipseHelper($cx, $cy, $rx, $ry, $style);
    }

    private static function renderEllipseHelper(float $cx, float $cy, float $rx, float $ry, array $style): string
    {
        $k = 0.5522847498;
        $kx = $rx * $k;
        $ky = $ry * $k;

        $path = sprintf(
            "%.3F %.3F m %.3F %.3F %.3F %.3F %.3F %.3F c %.3F %.3F %.3F %.3F %.3F %.3F c %.3F %.3F %.3F %.3F %.3F %.3F c %.3F %.3F %.3F %.3F %.3F %.3F c h",
            $cx + $rx,
            $cy,
            $cx + $rx,
            $cy + $ky,
            $cx + $kx,
            $cy + $ry,
            $cx,
            $cy + $ry,
            $cx - $kx,
            $cy + $ry,
            $cx - $rx,
            $cy + $ky,
            $cx - $rx,
            $cy,
            $cx - $rx,
            $cy - $ky,
            $cx - $kx,
            $cy - $ry,
            $cx,
            $cy - $ry,
            $cx + $kx,
            $cy - $ry,
            $cx + $rx,
            $cy - $ky,
            $cx + $rx,
            $cy
        );
        return self::paintPath($path, $style);
    }

    private static function renderLine(DOMElement $el, array $style): string
    {
        $x1 = (float) $el->getAttribute('x1');
        $y1 = (float) $el->getAttribute('y1');
        $x2 = (float) $el->getAttribute('x2');
        $y2 = (float) $el->getAttribute('y2');
        $path = sprintf("%.3F %.3F m %.3F %.3F l", $x1, $y1, $x2, $y2);
        return self::paintPath($path, $style);
    }

    private static function renderPoly(DOMElement $el, array $style, bool $close): string
    {
        $pts = preg_split('/[\s,]+/', trim($el->getAttribute('points')));
        if (count($pts) < 4)
            return '';

        $ops = sprintf("%.3F %.3F m", (float) $pts[0], (float) $pts[1]);
        for ($i = 2, $n = count($pts); $i < $n - 1; $i += 2) {
            $ops .= sprintf(" %.3F %.3F l", (float) $pts[$i], (float) $pts[$i + 1]);
        }
        if ($close) {
            $ops .= " h";
        }
        return self::paintPath($ops, $style);
    }

    private static function paintPath(string $pathOps, array $style): string
    {
        $hasFill = $style['fill'] !== null;
        $hasStroke = $style['stroke'] !== null && $style['stroke-width'] > 0;
        if (!$hasFill && !$hasStroke) {
            return '';
        }

        $out = "q\n";
        if ($hasFill) {
            $out .= $style['fill']->rg() . "\n";
        }
        if ($hasStroke) {
            $out .= $style['stroke']->rgStroke() . "\n";
            $out .= sprintf("%.3F w\n", $style['stroke-width']);
        }

        $out .= $pathOps . "\n";
        if ($hasFill && $hasStroke) {
            $out .= "B\n"; // fill and stroke
        } elseif ($hasFill) {
            $out .= "f\n"; // fill
        } else {
            $out .= "S\n"; // stroke
        }
        $out .= "Q\n";
        return $out;
    }
}
