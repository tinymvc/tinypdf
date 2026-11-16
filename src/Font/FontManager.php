<?php

declare(strict_types=1);

namespace Spark\Pdf\Font;

use Spark\Pdf\Text\NativeHelper;
use Spark\Pdf\Text\ShapedRun;
use Spark\Pdf\TextOptions;

final class FontManager
{
    private NativeHelper $helper;
    private array $registrations = [];
    private array $fallbacks = [];
    private array $fonts = [];
    private array $instances = [];
    private array $resources = [];
    private array $cache = [];
    private int $cacheBytes = 0;

    public function __construct(TextOptions $options = new TextOptions())
    {
        $this->helper = new NativeHelper($options);
        $root = dirname(__DIR__, 2) . '/assets/fonts/';
        $this->addFont('tinypdf sans', $root . 'TinyPDFSans.ttf');
        $this->addFont('tinypdf sans', $root . 'TinyPDFSans-Italic.ttf', italic: true);
    }

    public function addFont(string $family, string $path, bool $bold = false, bool $italic = false, int $faceIndex = 0, array $variations = []): self
    {
        $family = strtolower(trim($family, " \t\n\r\0\x0B'\""));
        if ($family === '' || !is_file($path) || !is_readable($path) || $faceIndex < 0) {
            throw new \InvalidArgumentException("Invalid font registration: $family ($path).");
        }
        foreach ($variations as $axis => $value) {
            if (!preg_match('/^[\x20-\x7E]{4}$/D', (string) $axis) || !is_numeric($value) || !is_finite((float) $value)) {
                throw new \InvalidArgumentException('Font variations must map four-character axis tags to finite coordinates.');
            }
        }
        $key = ($bold ? 'b' : '') . ($italic ? 'i' : '');
        $this->registrations[$family][$key] = ['path' => realpath($path), 'faceIndex' => $faceIndex, 'variations' => $variations];
        $this->cache = [];
        $this->cacheBytes = 0;
        return $this;
    }

    public function setFallbackFonts(array $families): self
    {
        foreach ($families as $family) {
            if (!is_string($family) || trim($family) === '') throw new \InvalidArgumentException('Fallback families must be nonempty strings.');
        }
        $this->fallbacks = array_map(static fn($name) => strtolower(trim($name, " \t\n\r\0\x0B'\"")), $families);
        $this->cache = [];
        $this->cacheBytes = 0;
        return $this;
    }

    public function helper(): NativeHelper { return $this->helper; }
    public function byId(int $id): NativeFont { return $this->fonts[$id]; }

    private function instance(array $registration, array $style): NativeFont
    {
        $baseKey = json_encode($registration, JSON_THROW_ON_ERROR);
        if (!isset($this->instances[$baseKey])) {
            $metadata = $this->helper->request('font', ['variations' => (object) $registration['variations']] + $registration);
            $font = new NativeFont($this->helper, $metadata);
            $this->fonts[$font->id()] = $font;
            $this->instances[$baseKey] = $font;
        }
        $base = $this->instances[$baseKey];
        $derived = ['wght' => $style['weight'] ?? (($style['bold'] ?? false) ? 700 : 400),
            'wdth' => $style['stretch'] ?? 100, 'ital' => ($style['italic'] ?? false) ? 1 : 0,
            'slnt' => ($style['italic'] ?? false) ? -12 : 0];
        if (($style['opticalSizing'] ?? 'auto') === 'auto') $derived['opsz'] = $style['size'] ?? 10;
        $variations = array_intersect_key(array_replace($derived, $registration['variations'], $style['variations'] ?? []), $base->metadata['axes']);
        if ($variations === []) return $base;
        ksort($variations);
        $key = $baseKey . json_encode($variations, JSON_THROW_ON_ERROR);
        if (!isset($this->instances[$key])) {
            $metadata = $this->helper->request('font', ['variations' => (object) $variations] + $registration);
            $font = new NativeFont($this->helper, $metadata);
            $this->fonts[$font->id()] = $font;
            $this->instances[$key] = $font;
        }
        return $this->instances[$key];
    }

    private function stack(array $style): array
    {
        $families = array_map(static fn($name) => strtolower(trim($name, " \t\n\r\0\x0B'\"")), explode(',', $style['family'] ?? 'tinypdf sans'));
        $families = array_unique([...$families, ...$this->fallbacks, 'tinypdf sans']);
        $ids = [];
        $key = (!empty($style['bold']) ? 'b' : '') . (!empty($style['italic']) ? 'i' : '');
        foreach ($families as $family) {
            // Generic/core-family names have a deterministic bundled Unicode replacement.
            if (!isset($this->registrations[$family]) && preg_match('/^(helvetica|arial|sans-serif|times|times new roman|georgia|garamond|palatino|serif|courier|mono|consolas|menlo|monospace)$/', $family)) {
                $family = 'tinypdf sans';
            }
            if (!isset($this->registrations[$family])) continue;
            $styles = $this->registrations[$family];
            $registration = $styles[$key] ?? $styles[!empty($style['italic']) ? 'i' : ''] ?? $styles[''] ?? reset($styles);
            $ids[] = $this->instance($registration, $style)->id();
        }
        return [array_values(array_unique($ids)), implode(', ', $families)];
    }

    public function getFont(string $family, bool $bold = false, bool $italic = false, string $text = ''): FontInterface
    {
        [$ids] = $this->stack(['family' => $family, 'bold' => $bold, 'italic' => $italic]);
        foreach ($ids as $id) {
            if ($text === '' || $this->fonts[$id]->supportsText($text)) return $this->fonts[$id];
        }
        throw new \RuntimeException('No single font covers this text; use the paragraph shaping pipeline for mixed fonts.');
    }

    public function layout(array $items, float $width, array $paragraph = []): array
    {
        $vertical = ($paragraph['writingMode'] ?? 'horizontal-tb') !== 'horizontal-tb';
        $text = '';
        $spans = [];
        foreach ($items as $i => $item) {
            $s = $item['s'];
            $object = in_array($item['t'], ['img', 'svg', 'inline-box'], true);
            $value = $object ? "\u{FFFC}" : ($item['t'] === 'br' ? "\n" : ($item['x'] ?? ''));
            if ($value === '') continue;
            if (!mb_check_encoding($value, 'UTF-8')) throw new \InvalidArgumentException('Text must be valid UTF-8.');
            [$fonts, $families] = $this->stack($s);
            $style = ['fonts' => $fonts, 'families' => $families, 'size' => $s['size'],
                'language' => $s['lang'] ?? $this->helper->options->defaultLanguage,
                'features' => $s['features'] ?? [], 'letterSpacing' => $s['letter-spacing'] ?? 0.0,
                'wordSpacing' => $s['word-spacing'] ?? 0.0, 'wrap' => $s['wrap'] ?? true,
                'preserve' => $s['pre'] ?? false, 'wordBreak' => $s['wordBreak'] ?? 'normal',
                'overflowWrap' => $s['overflowWrap'] ?? 'normal', 'lineBreak' => $s['lineBreak'] ?? 'auto',
                'orientation' => $s['textOrientation'] ?? 'mixed'];
            if ($object) $style['object'] = $vertical ? $item['h'] : $item['w'];
            $start = strlen($text);
            $text .= $value;
            $spans[] = ['start' => $start, 'end' => strlen($text), 'item' => $i, 'style' => $style];
        }
        if ($text === '') return [];
        $arguments = ['text' => $text, 'spans' => $spans, 'width' => max(0.0, $width),
            'language' => $paragraph['lang'] ?? $this->helper->options->defaultLanguage,
            'direction' => ($paragraph['unicodeBidi'] ?? 'normal') === 'plaintext' ? 'auto' : ($paragraph['direction'] ?? 'ltr'),
            'vertical' => $vertical];
        $key = hash('sha256', json_encode($arguments, JSON_THROW_ON_ERROR));
        if (!isset($this->cache[$key])) {
            $result = $this->helper->request('layout', $arguments);
            $bytes = strlen(serialize($result));
            if ($this->cacheBytes + $bytes > 8388608 || count($this->cache) >= 256) {
                $this->cache = [];
                $this->cacheBytes = 0;
            }
            $this->cacheBytes += $bytes;
            $this->cache[$key] = $result;
        }
        $lines = [];
        foreach ($this->cache[$key]['lines'] as $line) {
            $output = [];
            foreach ($line['items'] as $run) {
                $item = $items[$run['item']];
                $item['advance'] = $run['width'];
                if (!isset($run['object']) || !$vertical) $item['w'] = $run['width'];
                if (!isset($run['object'])) {
                    $item['t'] = 'w';
                    $item['shaped'] = new ShapedRun($run);
                }
                $output[] = $item;
            }
            $logical = $line['text'];
            foreach (array_reverse($spans) as $span) {
                if (!isset($span['style']['object']) || $span['start'] < $line['start'] || $span['start'] >= $line['end']) continue;
                $object = $items[$span['item']];
                $replacement = $object['t'] === 'inline-box' ? $object['el']->textContent : '';
                $logical = substr_replace($logical, $replacement, $span['start'] - $line['start'], 3);
            }
            $lines[] = ['items' => $output, 'w' => $line['width'], 'hard' => $line['hard'],
                'text' => preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $logical),
                'direction' => $line['direction']];
        }
        return $lines;
    }

    public function getResourceName(FontInterface $font): string
    {
        $key = spl_object_id($font);
        return $this->resources[$key] ??= 'F' . (count($this->resources) + 1);
    }

    public function buildPdfResources(array &$objects, int &$nextId): string
    {
        $resources = '';
        foreach ($this->fonts as $font) {
            if (!isset($this->resources[spl_object_id($font)])) continue;
            $name = $this->getResourceName($font);
            $fontObjects = $font->getPdfFontObjects($nextId, $name);
            $primary = array_key_last($fontObjects);
            $objects += $fontObjects;
            $resources .= "/$name $primary 0 R ";
        }
        return $resources;
    }
}
