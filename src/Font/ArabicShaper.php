<?php

declare(strict_types=1);

namespace Spark\Pdf\Font;

use function count;

/**
 * Arabic, Persian & Urdu cursive script shaper and bidirectional (BiDi) reorderer.
 * Maps letters to Isolated, Initial, Medial, and Final presentation forms.
 */
final class ArabicShaper
{
    /** @var array<int, array{0: int, 1: ?int, 2: ?int, 3: ?int}> isolated, final, initial, medial */
    private const FORMS = [
        0x0621 => [0xFE80, null, null, null],     // HAMZA
        0x0622 => [0xFE81, 0xFE82, null, null],   // ALEF WITH MADDA ABOVE
        0x0623 => [0xFE83, 0xFE84, null, null],   // ALEF WITH HAMZA ABOVE
        0x0624 => [0xFE85, 0xFE86, null, null],   // WAW WITH HAMZA ABOVE
        0x0625 => [0xFE87, 0xFE88, null, null],   // ALEF WITH HAMZA BELOW
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C], // YEH WITH HAMZA ABOVE
        0x0627 => [0xFE8D, 0xFE8E, null, null],   // ALEF
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92], // BEH
        0x0629 => [0xFE93, 0xFE94, null, null],   // TEH MARBUTA
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98], // TEH
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C], // THEH
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0], // JEEM
        0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4], // HAH
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8], // KHAH
        0x062F => [0xFEA9, 0xFEAA, null, null],   // DAL
        0x0630 => [0xFEAB, 0xFEAC, null, null],   // THAL
        0x0631 => [0xFEAD, 0xFEAE, null, null],   // REH
        0x0632 => [0xFEAF, 0xFEB0, null, null],   // ZAIN
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4], // SEEN
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8], // SHEEN
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC], // SAD
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0], // DAD
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4], // TAH
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8], // ZAH
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC], // AIN
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0], // GHAIN
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4], // FEH
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8], // QAF
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC], // KAF
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0], // LAM
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4], // MEEM
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8], // NOON
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC], // HEH
        0x0648 => [0xFEED, 0xFEEE, null, null],   // WAW
        0x0649 => [0xFEEF, 0xFEF0, null, null],   // ALEF MAKSURA
        0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4], // YEH
    ];

    public static function containsArabic(string $text): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text);
    }

    public static function isDiacritic(int $code): bool
    {
        return ($code >= 0x064B && $code <= 0x065F) || $code === 0x0670;
    }

    /**
     * Shapes Arabic letters to their contextual cursive presentation forms.
     */
    public static function shape(string $text): string
    {
        if (!self::containsArabic($text)) {
            return $text;
        }

        $chars = mb_str_split($text);
        $count = count($chars);
        $codes = array_map(static fn($c) => mb_ord($c), $chars);
        $shaped = [];

        for ($i = 0; $i < $count; $i++) {
            $code = $codes[$i];
            if (self::isDiacritic($code)) {
                $shaped[] = $chars[$i];
                continue;
            }
            if (!isset(self::FORMS[$code])) {
                $shaped[] = $chars[$i];
                continue;
            }

            // Find next non-diacritic letter
            $nextIdx = $i + 1;
            while ($nextIdx < $count && self::isDiacritic($codes[$nextIdx])) {
                $nextIdx++;
            }
            $nextCode = $nextIdx < $count ? $codes[$nextIdx] : null;

            // Find previous non-diacritic letter
            $prevIdx = $i - 1;
            while ($prevIdx >= 0 && self::isDiacritic($codes[$prevIdx])) {
                $prevIdx--;
            }
            $prevCode = $prevIdx >= 0 ? $codes[$prevIdx] : null;

            // Check Lam-Alif ligatures: LAM (0x0644) + ALEF variants
            if ($code === 0x0644 && $nextCode !== null) {
                $lamAlif = match ($nextCode) {
                    0x0622 => 0xFEF5, // MADDA
                    0x0623 => 0xFEF7, // HAMZA ABOVE
                    0x0625 => 0xFEF9, // HAMZA BELOW
                    0x0627 => 0xFEFB, // PLAIN
                    default => null,
                };
                if ($lamAlif !== null) {
                    $prevConnects = ($prevCode !== null) && self::connectsRight($prevCode);
                    $finalForm = $prevConnects ? ($lamAlif + 1) : $lamAlif;
                    $shaped[] = mb_chr($finalForm);
                    // Copy any intermediate diacritics between Lam and Alif
                    for ($d = $i + 1; $d < $nextIdx; $d++) {
                        $shaped[] = $chars[$d];
                    }
                    $i = $nextIdx; // skip past Alif
                    continue;
                }
            }

            $prevConnects = ($prevCode !== null) && self::connectsRight($prevCode);
            $nextConnects = ($nextCode !== null) && self::connectsLeft($nextCode);

            $form = self::FORMS[$code];
            $target = null;

            if ($prevConnects && $nextConnects && $form[3] !== null) {
                $target = $form[3]; // Medial
            } elseif ($nextConnects && $form[2] !== null) {
                $target = $form[2]; // Initial
            } elseif ($prevConnects && $form[1] !== null) {
                $target = $form[1]; // Final
            } else {
                $target = $form[0]; // Isolated
            }

            $shaped[] = mb_chr($target ?? $code);
        }

        return self::bidiReorder(implode('', $shaped));
    }

    private static function connectsLeft(int $code): bool
    {
        return isset(self::FORMS[$code]) && (self::FORMS[$code][1] !== null || self::FORMS[$code][3] !== null);
    }

    private static function connectsRight(int $code): bool
    {
        return isset(self::FORMS[$code]) && (self::FORMS[$code][2] !== null || self::FORMS[$code][3] !== null);
    }

    /**
     * Simple BiDi visual reordering for RTL text runs, preserving LTR digits.
     */
    public static function bidiReorder(string $text): string
    {
        // Split into runs of RTL vs LTR
        $chars = mb_str_split($text);
        $runs = [];
        $currentRun = [];
        $currentIsRtl = false;

        foreach ($chars as $c) {
            $isRtl = (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $c);
            if ($currentRun === []) {
                $currentIsRtl = $isRtl;
                $currentRun[] = $c;
            } elseif ($isRtl === $currentIsRtl) {
                $currentRun[] = $c;
            } else {
                $runs[] = ['rtl' => $currentIsRtl, 'text' => implode('', $currentRun)];
                $currentIsRtl = $isRtl;
                $currentRun = [$c];
            }
        }
        if ($currentRun !== []) {
            $runs[] = ['rtl' => $currentIsRtl, 'text' => implode('', $currentRun)];
        }

        $out = '';
        foreach ($runs as $run) {
            if ($run['rtl']) {
                $out .= self::reverseRtlPreservingDigits($run['text']);
            } else {
                $out .= $run['text'];
            }
        }

        return $out;
    }

    private static function reverseRtlPreservingDigits(string $rtlText): string
    {
        // Tokenize into digit sequences and individual non-digit characters
        $parts = preg_split('/([\d\x{0660}-\x{0669}\x{06F0}-\x{06F9}]+)/u', $rtlText, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $reversed = [];
        for ($k = count($parts) - 1; $k >= 0; $k--) {
            $part = $parts[$k];
            // If digit run, preserve Left-to-Right order
            if (preg_match('/^[\d\x{0660}-\x{0669}\x{06F0}-\x{06F9}]+$/u', $part)) {
                $reversed[] = $part;
            } else {
                $reversed[] = implode('', array_reverse(mb_str_split($part)));
            }
        }
        return implode('', $reversed);
    }
}
