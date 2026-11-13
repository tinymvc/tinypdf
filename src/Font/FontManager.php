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
     * If text is Latin and no custom font is requested, returns standard Type 1 core font (0 KB overhead).
     */
    public function getFont(string $family, bool $bold = false, bool $italic = false, string $text = ''): FontInterface
    {
        $cleanFamily = strtolower(trim($family));

        // 1. Direct match with user-registered custom font family
        if (isset($this->customPaths[$cleanFamily])) {
            $styleKey = ($bold ? 'b' : '') . ($italic ? 'i' : '');
            $path = $this->customPaths[$cleanFamily][$styleKey]
                ?? $this->customPaths[$cleanFamily]['']
                ?? reset($this->customPaths[$cleanFamily]);
            return $this->loadTrueType($cleanFamily, $path, $bold, $italic);
        }

        // 2. Check if text contains non-Latin scripts (Bangla, Arabic, CJK, Devanagari, etc.)
        $isNonLatin = (bool) preg_match('/[^\x00-\x7F\xA0-\xFF]/u', $text);
        if ($isNonLatin) {
            // If user has registered any custom font, use it as fallback
            if (!empty($this->allCustomFonts)) {
                // If text is Bengali and user registered a font with bangla/kalpurush in name, pick it
                if (BengaliShaper::containsBengali($text)) {
                    foreach ($this->allCustomFonts as $name => $path) {
                        if (str_contains($name, 'bangla') || str_contains($name, 'kalpurush') || str_contains($name, 'solaiman')) {
                            return $this->loadTrueType($name, $path, $bold, $italic);
                        }
                    }
                }
                // Otherwise use the first registered custom font
                $firstFamily = array_key_first($this->allCustomFonts);
                return $this->loadTrueType($firstFamily, $this->allCustomFonts[$firstFamily], $bold, $italic);
            }

            // Check if OS has system font as automatic convenience
            $systemFont = $this->findSystemFont($text);
            if ($systemFont !== null) {
                return $this->loadTrueType('sys_font', $systemFont, $bold, $italic);
            }
        }

        // 3. Default to Standard Adobe Core Type 1 Fonts (Helvetica, Times, Courier)
        // Keeps PDF file size microscopic (~2KB) with zero font embedding overhead
        return $this->loadCoreFont($cleanFamily, $bold, $italic);
    }

    private function findSystemFont(string $text): ?string
    {
        if (BengaliShaper::containsBengali($text)) {
            if (is_file('C:/Windows/Fonts/kalpurush.ttf')) return 'C:/Windows/Fonts/kalpurush.ttf';
            if (is_file('C:/Windows/Fonts/vrinda.ttf')) return 'C:/Windows/Fonts/vrinda.ttf';
        }
        if (ArabicShaper::containsArabic($text)) {
            if (is_file('C:/Windows/Fonts/arial.ttf')) return 'C:/Windows/Fonts/arial.ttf';
            if (is_file('C:/Windows/Fonts/segoeui.ttf')) return 'C:/Windows/Fonts/segoeui.ttf';
        }
        // Chinese / Japanese / Korean
        if (preg_match('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{AC00}-\x{D7AF}]/u', $text)) {
            if (is_file('C:/Windows/Fonts/msyh.ttc')) return 'C:/Windows/Fonts/msyh.ttc';
            if (is_file('C:/Windows/Fonts/simsun.ttc')) return 'C:/Windows/Fonts/simsun.ttc';
            if (is_file('C:/Windows/Fonts/YuGothR.ttc')) return 'C:/Windows/Fonts/YuGothR.ttc';
            if (is_file('C:/Windows/Fonts/malgun.ttf')) return 'C:/Windows/Fonts/malgun.ttf';
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
