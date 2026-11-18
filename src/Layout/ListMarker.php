<?php

declare(strict_types=1);

namespace Spark\Pdf\Layout;

final class ListMarker
{
    public static function text(int $number, string $style): string
    {
        if ($style === 'none')
            return '';
        if (in_array($style, ['lower-alpha', 'upper-alpha', 'lower-latin', 'upper-latin'], true) && $number > 0) {
            $label = '';
            for ($value = $number; $value > 0; $value = intdiv($value - 1, 26))
                $label = chr(97 + ($value - 1) % 26) . $label;
            return (str_starts_with($style, 'upper') ? strtoupper($label) : $label) . '.';
        }
        if (in_array($style, ['lower-roman', 'upper-roman'], true) && $number > 0 && $number < 4000) {
            $label = '';
            foreach ([1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'] as $value => $letters) {
                while ($number >= $value) {
                    $label .= $letters;
                    $number -= $value;
                }
            }
            return ($style === 'lower-roman' ? strtolower($label) : $label) . '.';
        }
        return match ($style) {
            'disc' => "\u{2022}",
            'circle' => "\u{25E6}",
            'square' => "\u{25AA}",
            'decimal-leading-zero' => ($number >= 0 && $number < 10 ? '0' : '') . $number . '.',
            default => $number . '.',
        };
    }
}
