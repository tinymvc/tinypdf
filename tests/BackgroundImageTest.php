<?php

declare(strict_types=1);

use Spark\Pdf\Graphics\BackgroundImage;
use Spark\Pdf\Graphics\GraphicState;

test('BackgroundImage calculates cover size and position', function () {
    $bg = new BackgroundImage('I1', 200, 100, 'no-repeat', 'center', 'cover');
    $gs = new GraphicState();
    $ops = $bg->render(10, 10, 300, 300, 8.0, 842.0, $gs);

    check(str_contains($ops, '/I1 Do'), 'Must draw image resource');
    check(str_contains($ops, 'W n'), 'Must clip to rounded rectangle');
});

test('BackgroundImage calculates repeat-x tiling', function () {
    $bg = new BackgroundImage('I1', 50, 50, 'repeat-x', 'left top', 'auto');
    $ops = $bg->render(0, 0, 200, 50, 0, 842.0);

    $count = substr_count($ops, '/I1 Do');
    check($count >= 4, "Must repeat horizontally across width (found $count tiles)");
});
