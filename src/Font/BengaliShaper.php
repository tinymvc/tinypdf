<?php

declare(strict_types=1);

namespace Spark\Pdf\Font;

/**
 * Bengali (বাংলা) & Indic complex script shaper.
 * Handles syllable clustering, pre-base matra reordering (ি, ে, ৈ),
 * two-part vowel splitting (ো, ৌ), reph (র্) repositioning, and conjuncts.
 */
final class BengaliShaper
{
    // Dependent vowel signs (কার)
    public const SIGN_AA = "\u{09BE}"; // া
    public const SIGN_I  = "\u{09BF}"; // ি (pre-base)
    public const SIGN_II = "\u{09C0}"; // ী
    public const SIGN_U  = "\u{09C1}"; // ু
    public const SIGN_UU = "\u{09C2}"; // ূ
    public const SIGN_R  = "\u{09C3}"; // ৃ
    public const SIGN_RR = "\u{09C4}"; // ৄ
    public const SIGN_E  = "\u{09C7}"; // ে (pre-base)
    public const SIGN_AI = "\u{09C8}"; // ৈ (pre-base)
    public const SIGN_O  = "\u{09CB}"; // ো (split into ে + া)
    public const SIGN_AU = "\u{09CC}"; // ৌ (split into ে + ৌ/ৗ)
    public const SIGN_AU_LENGTH = "\u{09D7}"; // ৗ

    // Special signs
    public const VIRAMA = "\u{09CD}"; // ্ (Hasanta)
    public const CANDRABINDU = "\u{0981}"; // ঁ
    public const ANUSVARA = "\u{0982}"; // ং
    public const VISARGA = "\u{0983}"; // ঃ
    public const NUKTA = "\u{09BC}"; // ়
    public const RA = "\u{09B0}"; // র
    public const YA = "\u{09AF}"; // য
    public const KHANDA_TA = "\u{09CE}"; // ৎ

    /**
     * Checks if the text contains any Bengali characters (U+0980 - U+09FF).
     */
    public static function containsBengali(string $text): bool
    {
        return (bool) preg_match('/[\x{0980}-\x{09FF}]/u', $text);
    }

    /**
     * Shapes Bengali text for visual rendering.
     * Reorders pre-base vowel signs and splits two-part vowels.
     *
     * @return list<string> sequence of Unicode characters / clusters ready for glyph mapping
     */
    public static function shape(string $text): array
    {
        if (!self::containsBengali($text)) {
            return mb_str_split($text);
        }

        $chars = mb_str_split($text);
        $count = count($chars);
        $output = [];
        $i = 0;

        while ($i < $count) {
            $char = $chars[$i];
            $code = mb_ord($char);

            // If not in Bengali block, pass through directly
            if ($code < 0x0980 || $code > 0x09FF) {
                $output[] = $char;
                $i++;
                continue;
            }

            // Extract orthographic syllable
            $syllable = self::extractSyllable($chars, $i);
            $shaped = self::shapeSyllable($syllable);
            foreach ($shaped as $c) {
                $output[] = $c;
            }
        }

        return $output;
    }

    /**
     * @param list<string> $chars
     * @return list<string>
     */
    private static function extractSyllable(array $chars, int &$index): array
    {
        $count = count($chars);
        $syllable = [];

        // Check for initial Reph: RA + VIRAMA
        if (
            $index + 1 < $count &&
            $chars[$index] === self::RA &&
            $chars[$index + 1] === self::VIRAMA
        ) {
            $syllable[] = $chars[$index++];
            $syllable[] = $chars[$index++];
        }

        // Base consonant(s) with virama
        while ($index < $count) {
            $c = $chars[$index];
            $syllable[] = $c;
            $index++;

            // If next is virama, it connects to the next consonant
            if ($index < $count && $chars[$index] === self::VIRAMA) {
                $syllable[] = $chars[$index++];
                // Continue to consume the joined consonant
                continue;
            }

            // Consume nukta if present
            if ($index < $count && $chars[$index] === self::NUKTA) {
                $syllable[] = $chars[$index++];
            }

            // Consume vowel signs
            if ($index < $count && self::isVowelSign($chars[$index])) {
                $syllable[] = $chars[$index++];
            }

            // Consume modifiers (Anusvara, Visarga, Candrabindu)
            while ($index < $count && self::isModifier($chars[$index])) {
                $syllable[] = $chars[$index++];
            }

            break;
        }

        return $syllable;
    }

    /**
     * Reorders vowels and reph within an extracted syllable.
     *
     * @param list<string> $syllable
     * @return list<string>
     */
    private static function shapeSyllable(array $syllable): array
    {
        $n = count($syllable);
        if ($n <= 1) {
            return $syllable;
        }

        $consonants = [];
        $preVowel = null;
        $postVowels = [];
        $hasReph = false;
        $modifiers = [];

        $i = 0;
        // Check for leading Reph (RA + VIRAMA)
        if ($n >= 2 && $syllable[0] === self::RA && $syllable[1] === self::VIRAMA) {
            $hasReph = true;
            $i = 2;
        }

        for (; $i < $n; $i++) {
            $c = $syllable[$i];

            // Handle two-part vowels
            if ($c === self::SIGN_O) {
                $preVowel = self::SIGN_E;
                $postVowels[] = self::SIGN_AA;
                continue;
            }
            if ($c === self::SIGN_AU) {
                $preVowel = self::SIGN_E;
                $postVowels[] = self::SIGN_AU_LENGTH;
                continue;
            }

            // Handle pre-base vowels: ি, ে, ৈ
            if ($c === self::SIGN_I || $c === self::SIGN_E || $c === self::SIGN_AI) {
                $preVowel = $c;
                continue;
            }

            // Other vowel signs (aa, ii, u, uu, r, etc.)
            if (self::isVowelSign($c)) {
                $postVowels[] = $c;
                continue;
            }

            // Modifiers (ঁ, ং, ঃ)
            if (self::isModifier($c)) {
                $modifiers[] = $c;
                continue;
            }

            // Consonants and virama
            $consonants[] = $c;
        }

        $result = [];

        // 1. Pre-base vowel goes first (to the left of the consonant cluster)
        if ($preVowel !== null) {
            $result[] = $preVowel;
        }

        // 2. Reph (if present) + Consonants
        // In OpenType Indic specification, Reph can be emitted before or after the cluster
        // depending on font GSUB. We keep Reph as [RA, VIRAMA] leading the cluster
        // or trailing for standard GSUB rphf substitution.
        if ($hasReph) {
            $result[] = self::RA;
            $result[] = self::VIRAMA;
        }

        foreach ($consonants as $c) {
            $result[] = $c;
        }

        // 3. Post-base vowel signs
        foreach ($postVowels as $v) {
            $result[] = $v;
        }

        // 4. Modifiers
        foreach ($modifiers as $m) {
            $result[] = $m;
        }

        return $result;
    }

    private static function isVowelSign(string $char): bool
    {
        $code = mb_ord($char);
        return ($code >= 0x09BE && $code <= 0x09CC) || $code === 0x09D7;
    }

    private static function isModifier(string $char): bool
    {
        $code = mb_ord($char);
        return $code >= 0x0981 && $code <= 0x0983;
    }
}
