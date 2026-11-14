<?php

declare(strict_types=1);

namespace Spark\Pdf\Font;

/**
 * Manages Core Adobe fonts, user-registered TrueType/OpenType fonts, and intelligent script routing.
 */
final class FontManager
{
    /** @var array<string, FontInterface> key: "family_bold_italic" => FontInterface */
    private array $fonts = [];

    /** @var array<string, string> key: "family_bold_italic" => PDF resource name (e.g. "F1") */
    private array $resources = [];

    /** @var array<string, array<string, string>> family => [styleKey => fontPath] */
    private array $customPaths = [];

    /**
     * Registers a custom TrueType/OpenType font family (e.g. for Bangla, Chinese, Japanese, Arabic, etc.).
     *
     * @param string $family Font family alias (e.g. 'Kalpurush', 'SimSun', 'Amiri', 'Roboto')
     * @param string $path Absolute or relative path to the .ttf/.otf/.ttc file
     * @param bool $bold Whether this file is the bold variant
     * @param bool $italic Whether this file is the italic variant
     */
    public function addFont(string $family, string $path, bool $bold = false, bool $italic = false): self
    {
        $family = strtolower(trim($family));
        $styleKey = ($bold ? 'b' : '') . ($italic ? 'i' : '');
        $this->customPaths[$family][$styleKey] = $path;

        return $this;
    }

    /**
     * Resolves the appropriate font for given family, style, and text content.
     * Supports font stacks (comma-separated), intelligent script fallback, and automatic system fonts.
     * If text is Latin and no custom font is requested, returns standard Type 1 core font (0 KB overhead).
     */
    public function getFont(string $family, bool $bold = false, bool $italic = false, string $text = ''): FontInterface
    {
        $candidates = array_values(array_filter(
            array_map(static fn($f) => strtolower(trim($f, " \t\n\r\0\x0B'\"")), explode(',', $family)),
            static fn($f) => $f !== ''
        ));
        if (empty($candidates)) {
            $candidates = ['helvetica'];
        }

        $styleKey = ($bold ? 'b' : '') . ($italic ? 'i' : '');

        // 1. Iterate over candidate font families in the CSS font stack
        foreach ($candidates as $cand) {
            if (isset($this->customPaths[$cand])) {
                $styles = $this->customPaths[$cand];
                $selected = $styleKey;
                if (!isset($styles[$selected])) {
                    $selected = isset($styles['']) ? '' : array_key_first($styles);
                }
                $font = $this->loadTrueType($cand, $styles[$selected], str_contains($selected, 'b'), str_contains($selected, 'i'));

                // If text is empty or this font supports the glyphs, choose it
                if ($text === '' || $font->supportsText($text)) {
                    return $font;
                }
            } elseif ($this->isCoreFontFamily($cand)) {
                $core = $this->loadCoreFont($cand, $bold, $italic);
                if ($text === '' || $core->supportsText($text)) {
                    return $core;
                }
            }
        }

        // Fallbacks must cover the text; a family name alone says nothing about coverage.
        foreach ($this->customPaths as $name => $styles) {
            $selected = $styleKey;
            if (!isset($styles[$selected])) {
                $selected = isset($styles['']) ? '' : array_key_first($styles);
            }
            $font = $this->loadTrueType($name, $styles[$selected], str_contains($selected, 'b'), str_contains($selected, 'i'));
            if ($font->supportsText($text)) {
                return $font;
            }
        }

        $primaryFamily = $candidates[0] ?? 'helvetica';
        $core = $this->loadCoreFont($primaryFamily, $bold, $italic);
        if ($core->supportsText($text)) {
            return $core;
        }
        foreach ($this->findSystemFonts($text) as $path) {
            try {
                $font = $this->loadTrueType('system', $path, false, false);
            } catch (\RuntimeException) {
                continue;
            }
            if ($font->supportsText($text)) {
                return $font;
            }
        }

        throw new \RuntimeException('No font covers the requested text. Register a matching font with addFont() or @font-face.');
    }

    private function isCoreFontFamily(string $family): bool
    {
        return (bool) preg_match('/^(helvetica|arial|sans-serif|times|times new roman|georgia|garamond|palatino|serif|courier|mono|consolas|menlo|monospace)$/i', $family);
    }

    private function findSystemFonts(string $text): array
    {
        $paths = [];

        if (BengaliShaper::containsBengali($text)) {
            $paths = [
                // Windows
                'C:/Windows/Fonts/kalpurush.ttf',
                'C:/Windows/Fonts/vrinda.ttf',
                // Linux
                '/usr/share/fonts/truetype/lohit-bengali/Lohit-Bengali.ttf',
                '/usr/share/fonts/truetype/noto/NotoSansBengali-Regular.ttf',
                '/usr/share/fonts/google-noto/NotoSansBengali-Regular.ttf',
                '/System/Library/Fonts/Supplemental/Bangla Sangam MN.ttc',
            ];
        } elseif (ArabicShaper::containsArabic($text)) {
            $paths = [
                // Windows
                'C:/Windows/Fonts/arial.ttf',
                'C:/Windows/Fonts/segoeui.ttf',
                // Linux
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf',
                // macOS
                '/System/Library/Fonts/GeezaPro.ttc',
            ];
        } elseif (BengaliShaper::containsDevanagari($text)) {
            $paths = [
                // Windows
                'C:/Windows/Fonts/mangal.ttf',
                'C:/Windows/Fonts/nirmala.ttf',
                // Linux
                '/usr/share/fonts/truetype/lohit-devanagari/Lohit-Devanagari.ttf',
                '/usr/share/fonts/truetype/noto/NotoSansDevanagari-Regular.ttf',
                '/System/Library/Fonts/Supplemental/Devanagari Sangam MN.ttc',
            ];
        } elseif (preg_match('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text)) {
            $paths = [
                // Windows
                'C:/Windows/Fonts/msyh.ttc',
                'C:/Windows/Fonts/simsun.ttc',
                'C:/Windows/Fonts/YuGothR.ttc',
                'C:/Windows/Fonts/malgun.ttf',
                // Linux
                '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
                '/usr/share/fonts/truetype/wqy/wqy-zenhei.ttc',
                '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
                // macOS
                '/System/Library/Fonts/PingFang.ttc',
                '/Library/Fonts/Arial Unicode.ttf',
            ];
        }

        $paths = array_merge($paths, [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
            '/Library/Fonts/Arial Unicode.ttf',
            'C:/Windows/Fonts/arial.ttf',
        ]);
        return array_values(array_filter($paths, 'is_file'));
    }

    public function getResourceName(FontInterface $font): string
    {
        $key = spl_object_hash($font);
        if (!isset($this->resources[$key])) {
            $name = 'F' . (count($this->resources) + 1);
            $this->resources[$key] = $name;
        }
        return $this->resources[$key];
    }

    private function loadTrueType(string $family, string $path, bool $bold, bool $italic): FontInterface
    {
        $key = sprintf('ttf_%s_%d_%d', $path, (int) $bold, (int) $italic);
        if (!isset($this->fonts[$key])) {
            $this->fonts[$key] = new TrueTypeFont($path, $bold, $italic);
        }
        return $this->fonts[$key];
    }

    private function loadCoreFont(string $family, bool $bold, bool $italic): FontInterface
    {
        $baseFamily = match (true) {
            (bool) preg_match('/courier|mono|consolas|menlo/', $family) => 'courier',
            (bool) preg_match('/^(times|times new roman|georgia|garamond|palatino|serif)$/', $family) => 'times',
            default => 'helvetica',
        };

        $baseName = match ($baseFamily) {
            'times' => match (true) {
                    $bold && $italic => 'Times-BoldItalic',
                    $bold => 'Times-Bold',
                    $italic => 'Times-Italic',
                    default => 'Times-Roman',
                },
            'courier' => match (true) {
                    $bold && $italic => 'Courier-BoldOblique',
                    $bold => 'Courier-Bold',
                    $italic => 'Courier-Oblique',
                    default => 'Courier',
                },
            default => match (true) {
                    $bold && $italic => 'Helvetica-BoldOblique',
                    $bold => 'Helvetica-Bold',
                    $italic => 'Helvetica-Oblique',
                    default => 'Helvetica',
                },
        };

        $key = 'core_' . $baseName;
        if (!isset($this->fonts[$key])) {
            $this->fonts[$key] = new CoreFont($baseName, $bold, $italic);
        }
        return $this->fonts[$key];
    }

    public function buildPdfResources(array &$objects, int &$nextId): string
    {
        $resStr = '';
        foreach ($this->fonts as $font) {
            if (!isset($this->resources[spl_object_hash($font)]))
                continue;
            $resName = $this->getResourceName($font);
            $fontObjs = $font->getPdfFontObjects($nextId, $resName);
            $primaryId = array_key_last($fontObjs);
            foreach ($fontObjs as $id => $body) {
                $objects[$id] = $body;
            }
            $resStr .= "/$resName $primaryId 0 R ";
        }
        return $resStr;
    }
}
