<?php

declare(strict_types=1);

namespace Spark\Pdf\Graphics;

final class BorderStyle
{
    /** @return list<array{0: float, 1: float, 2: array}> Offset, thickness and color of each stroke band. */
    public static function bands(string $style, float $width, array $color, int $side): array
    {
        if ($style === 'double')
            return [[0.0, $width / 3, $color], [$width * 2 / 3, $width / 3, $color]];
        if (!in_array($style, ['inset', 'outset', 'groove', 'ridge'], true))
            return [[0.0, $width, $color]];
        $dark = $light = $color;
        for ($i = 0; $i < 3; $i++) {
            $dark[$i] *= 0.6;
            $light[$i] += (255 - $light[$i]) * 0.4;
        }
        $firstDark = in_array($side, [0, 3], true) === in_array($style, ['inset', 'groove'], true);
        $first = $firstDark ? $dark : $light;
        $second = $firstDark ? $light : $dark;
        return in_array($style, ['groove', 'ridge'], true)
            ? [[0.0, $width / 2, $first], [$width / 2, $width / 2, $second]]
            : [[0.0, $width, $first]];
    }
}
