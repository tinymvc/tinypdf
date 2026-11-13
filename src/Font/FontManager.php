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

    /** @var array<string, string> list of all registered custom font paths */
    private array $allCustomFonts = [];

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
        $this->allCustomFonts[$family] = $path;

        return $this;
    }

    /**
     * Resolves the appropriate font for given family, style, and text content.
     * Supports font stacks (comma-separated), intelligent script fallback, and automatic system fonts.
     * If text is Latin and no custom font is requested, returns standard Type 1 core font (0 KB overhead).
     */
    public function getFont(string $family, bool $bold = false, bool $italic = false, string $text = ''): FontInterface
    {
        $candidates = array_filter(
            array_map(static fn($f) => strtolower(trim($f, " \t\n\r\0\x0B'\"")), explode(',', $family)),
            static fn($f) => $f !== ''
        );
        if (empty($candidates)) {
            $candidates = ['helvetica'];
        }

        $styleKey = ($bold ? 'b' : '') . ($italic ? 'i' : '');
        $firstCustomFont = null;

        // 1. Iterate over candidate font families in the CSS font stack
        foreach ($candidates as $cand) {
            if (isset($this->customPaths[$cand])) {
                $path = $this->customPaths[$cand][$styleKey]
                    ?? $this->customPaths[$cand]['']
                    ?? reset($this->customPaths[$cand]);
                $font = $this->loadTrueType($cand, $path, $bold, $italic);
                $firstCustomFont ??= $font;

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

        // If a font in the user's stack was found and text has no non-Latin script, return it
        if ($firstCustomFont !== null && !self::isNonLatinText($text)) {
            return $firstCustomFont;
        }

        // 2. Script-based routing across all registered custom fonts
        if (!empty($this->allCustomFonts)) {
            // Check if text is Bengali / Indic
            if (BengaliShaper::containsBengali($text)) {
                foreach ($this->allCustomFonts as $name => $path) {
                    if (str_contains($name, 'bangla') || str_contains($name, 'kalpurush') || str_contains($name, 'solaiman')) {
                        return $this->loadTrueType($name, $path, $bold, $italic);
                    }
                }
            }

            // Check if text is Arabic
            if (ArabicShaper::containsArabic($text)) {
                foreach ($this->allCustomFonts as $name => $path) {
                    if (str_contains($name, 'arabic') || str_contains($name, 'amiri') || str_contains($name, 'scheherazade')) {
                        return $this->loadTrueType($name, $path, $bold, $italic);
                    }
                }
            }

            // Check if any registered custom font supports the glyphs in this text
            foreach ($this->allCustomFonts as $name => $path) {
                $font = $this->loadTrueType($name, $path, $bold, $italic);
                if ($font->supportsText($text)) {
                    return $font;
                }
            }

            // If non-Latin and user has registered fonts, use the first custom font
            if (self::isNonLatinText($text)) {
                $firstFamily = array_key_first($this->allCustomFonts);
                return $this->loadTrueType($firstFamily, $this->allCustomFonts[$firstFamily], $bold, $italic);
            }
        }

        // 3. System font discovery for non-Latin scripts as automatic convenience
        if (self::isNonLatinText($text)) {
            $systemFont = $this->findSystemFont($text);
            if ($systemFont !== null) {
                return $this->loadTrueType('sys_font', $systemFont, $bold, $italic);
            }
        }

        // 4. Default to Standard Adobe Core Type 1 Fonts (Helvetica, Times, Courier)
        // Keeps PDF file size microscopic (~2KB) with zero font embedding overhead
        $primaryFamily = $candidates[0] ?? 'helvetica';
        return $this->loadCoreFont($primaryFamily, $bold, $italic);
    }

    private static function isNonLatinText(string $text): bool
    {
        if ($text === '') {
            return false;
        }
        // Non-Latin Unicode scripts: Cyrillic, Hebrew, Arabic, Devanagari, Bengali, Gurmukhi,
        // Gujarati, Oriya, Tamil, Telugu, Kannada, Malayalam, Thai, CJK, Hangul, etc.
        return (bool) preg_match('/[\x{0400}-\x{04FF}\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{0900}-\x{097F}\x{0980}-\x{09FF}\x{0A00}-\x{0A7F}\x{0A80}-\x{0AFF}\x{0B00}-\x{0B7F}\x{0B80}-\x{0BFF}\x{0C00}-\x{0C7F}\x{0C80}-\x{0CFF}\x{0D00}-\x{0D7F}\x{0E00}-\x{0E7F}\x{1000}-\x{109F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $text);
    }

    private function isCoreFontFamily(string $family): bool
    {
        return (bool) preg_match('/^(helvetica|arial|sans-serif|times|georgia|garamond|palatino|serif|courier|mono|consolas|menlo|monospace)$/i', $family);
    }

    private function findSystemFont(string $text): ?string
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

        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
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
        $key = sprintf('ttf_%s_%d_%d', $family, (int) $bold, (int) $italic);
        if (!isset($this->fonts[$key])) {
            $this->fonts[$key] = new TrueTypeFont($path, $bold, $italic);
        }
        return $this->fonts[$key];
    }

    private function loadCoreFont(string $family, bool $bold, bool $italic): FontInterface
    {
        $baseFamily = match (true) {
            (bool) preg_match('/courier|mono|consolas|menlo/', $family) => 'courier',
            (bool) preg_match('/times|georgia|garamond|palatino|serif/', $family) => 'times',
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
