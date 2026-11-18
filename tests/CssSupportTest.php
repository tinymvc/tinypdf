<?php

declare(strict_types=1);

use Spark\Pdf\Css\Length;
use Spark\Pdf\Css\Selector;
use Spark\Pdf\Graphics\BackgroundImage;
use Spark\Pdf\HtmlPdf;
use Spark\Pdf\Layout\ListMarker;

function cssSupportStyles(HtmlPdf $pdf): array
{
    $styles = [];
    foreach ((new ReflectionProperty($pdf, 'styleCache'))->getValue($pdf) as $entry) {
        if ($entry['el']->hasAttribute('id'))
            $styles[$entry['el']->getAttribute('id')] = $entry['s'];
    }
    return $styles;
}

test('CSS selector attributes, sibling combinators and structural functions match correctly', function () {
    $doc = new DOMDocument();
    $doc->loadHTML('<main><p id="first"></p><span></span><p id="target" data-label="A B" class="chosen"></p><p></p></main>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $target = $doc->getElementById('target');
    foreach (['span + p', '#first ~ p[data-label="a b" i]', 'p:nth-of-type(2)', 'p:nth-last-child(2)', ':is(.chosen, #other):not(:first-child)', ':where(main) > p.chosen'] as $selector)
        check(Selector::matches($target, Selector::parse($selector)), 'Must match ' . $selector);
    foreach (['#first + p', '[data-label^=""]', 'p:first-of-type', 'p:only-child', 'p:not(.chosen)'] as $selector)
        check(!Selector::matches($target, Selector::parse($selector)), 'Must reject ' . $selector);
    same([0, 1, 0], Selector::specificity(':where(#id) .chosen'));
    same([1, 1, 0], Selector::specificity(':is(#id, p).chosen'));
});

test('Stylesheet selectors preserve specificity and important precedence in generated PDF', function () {
    $pdf = (new HtmlPdf())->addHtml('<style>:where(#target){color:red} p[data-x]{color:blue} p + p:not(.skip){background:yellow!important}</style><p>A</p><p id="target" data-x style="background:red">B</p>');
    $style = cssSupportStyles($pdf)['target'];
    same([0.0, 0.0, 255.0], array_map('floatval', array_slice($style['color'], 0, 3)));
    same([255.0, 255.0, 0.0], array_map('floatval', array_slice($style['bg'], 0, 3)));
    check(str_contains(getDecompressedStreams($pdf->output()), '1 1 0 rg'), 'Winning background must paint');
});

test('Linked and imported stylesheets resolve local assets and ignore screen-only rules', function () {
    $directory = sys_get_temp_dir() . '/tinypdf-css-' . bin2hex(random_bytes(5));
    mkdir($directory);
    mkdir($directory . '/css');
    file_put_contents($directory . '/css/base.css', '@import "theme.css" print; @import "screen.css" screen; .box{background-image:url(tile.gif)}');
    file_put_contents($directory . '/css/theme.css', '@import "base.css"; .box { color: blue; width:40pt; height:20pt; }');
    file_put_contents($directory . '/css/screen.css', '.box{display:none}');
    file_put_contents($directory . '/css/tile.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
    try {
        $pdf = (new HtmlPdf(basePath: $directory))->addHtml('<link rel="stylesheet" href="css/base.css"><div class="box" id="box">Linked</div>');
        $style = cssSupportStyles($pdf)['box'];
        same($directory . '/css/tile.gif', $style['bgImg']);
        same(40.0, $style['width']);
        same('block', $style['display']);
        check(str_contains(logicalText(getDecompressedStreams($pdf->output())), 'Linked'), 'Imported print rule must render');
    } finally {
        foreach (glob($directory . '/css/*') as $file)
            unlink($file);
        rmdir($directory . '/css');
        rmdir($directory);
    }
});

test('CSS arithmetic and additional units resolve against their containing dimensions', function () {
    $parse = static fn(string $value) => Length::parse($value, 10, 12, 600, 800);
    same(140.0, Length::resolve($parse('calc(50% - 10pt)'), 300));
    same(72.0, $parse('6pc'));
    same(72.0, $parse('101.6q'));
    same(60.0, $parse('10vw'));
    same(80.0, Length::resolve($parse('clamp(30pt, calc(50% + 10pt), 80pt)'), 200));
    same(30.0, Length::resolve($parse('min(20%, max(30pt, 2em))'), 200));
    same(null, $parse('calc(1pt / 0)'));
    same(null, $parse('calc(10px + bogus)'));
    $pdf = (new HtmlPdf(margin: 0))->addHtml('<div style="width:200pt"><div style="width:calc(100% - 20pt);height:20pt;background:red"></div></div>')->output();
    check(str_contains(getDecompressedStreams($pdf), '180 20 re f'), 'Calculated width must reach the PDF');
});

test('Background shorthand and image position handle sizes, keyword order and edge offsets', function () {
    $pdf = (new HtmlPdf())->addHtml('<div id="box" style="background:url(missing.png) top right / 2em auto no-repeat red;height:20pt"></div>');
    $style = cssSupportStyles($pdf)['box'];
    same('top right', $style['bgPos']);
    same('20.00000000pt auto', $style['bgSize']);
    same('no-repeat', $style['bgRepeat']);
    same([80.0, 0.0], (new BackgroundImage('I', 20, 10, position: 'top right'))->calculatePosition(0, 0, 100, 50, 20, 10));
    same([75.0, 33.0], (new BackgroundImage('I', 20, 10, position: 'right 5pt bottom 7pt'))->calculatePosition(0, 0, 100, 50, 20, 10));
});

test('Object fit retains the bordered image box and fits or crops its contents', function () {
    if (!extension_loaded('gd'))
        skip('GD unavailable');
    $bitmap = imagecreatetruecolor(20, 10);
    imagefill($bitmap, 0, 0, imagecolorallocate($bitmap, 255, 0, 0));
    ob_start();
    imagepng($bitmap);
    $source = 'data:image/png;base64,' . base64_encode(ob_get_clean());
    if (PHP_VERSION_ID < 80500) {
        @imagedestroy($bitmap);
    }
    foreach (['contain' => '100 0 0 50 2 ', 'cover' => '200 0 0 100 -48 ', 'none' => '15 0 0 7.5 44.5 ', 'scale-down' => '15 0 0 7.5 44.5 '] as $fit => $matrix) {
        $pdf = (new HtmlPdf(margin: 0))->addHtml('<img src="' . $source . '" style="display:block;width:100pt;height:100pt;border:2pt solid blue;object-fit:' . $fit . '">')->output();
        $stream = getDecompressedStreams($pdf);
        check(str_contains($stream, $matrix), 'Incorrect ' . $fit . ' transform');
        same(4, substr_count($stream, '0 0 1 RG 2 w'), 'Border must use the image box');
        check(str_contains($stream, ' W n'), 'Image content must be clipped');
    }
});

test('Visibility preserves space and visible overrides without leaking hidden extraction or links', function () {
    $html = '<div style="height:30pt;visibility:hidden"><a href="https://example.com/hidden">SECRET</a><span style="visibility:visible">Shown</span></div>'
        . '<span style="display:inline-block;border:1pt solid;visibility:hidden">PRIVATE<span style="visibility:visible">Visible child</span></span><p>After</p>';
    $pdf = (new HtmlPdf(margin: 0))->addHtml($html)->output();
    $text = logicalText(getDecompressedStreams($pdf));
    check(!str_contains($text, 'SECRET') && !str_contains($text, 'PRIVATE'), 'Hidden text must not be extracted');
    check(str_contains($text, 'Shown') && str_contains($text, 'Visible child') && str_contains($text, 'After'), 'Visible descendants must remain');
    check(!str_contains($pdf, '/URI (https://example.com/hidden)'), 'Hidden link must not be clickable');
});

test('Hidden headings and vertical links do not create visible PDF navigation', function () {
    $pdf = (new HtmlPdf())->addHtml('<h1 style="visibility:hidden">PRIVATE HEADING</h1><div style="writing-mode:vertical-rl;height:150pt;width:100pt;visibility:hidden"><a href="https://example.com/hidden">Secret link</a></div>')->output();
    check(!str_contains($pdf, '/Outlines'), 'Hidden heading must not appear in bookmarks');
    check(!str_contains($pdf, '/Subtype /Link'), 'Hidden vertical link must not remain active');
});

test('Overflow clips drawings and link rectangles without creating extra pages', function () {
    $pdf = (new HtmlPdf(margin: 0))->addHtml('<div style="height:20pt;overflow:hidden;border:1pt solid;width:100pt"><a href="https://example.com/clip">' . str_repeat('Line<br>', 100) . '</a></div><p>After</p>')->output();
    same(1, preg_match_all('/\/Type \/Page\b/', $pdf));
    $stream = getDecompressedStreams($pdf);
    check(str_contains($stream, ' W n'), 'Overflow must install a clipping path');
    foreach (textPositions($stream) as $position) {
        if (trim($position['text']) === 'After')
            check($position['y'] > 780, 'Clipped content must not push subsequent content down');
    }
});

test('Image-only lines omit empty ActualText markers', function () {
    $html = '<svg width="20" height="20"><rect width="20" height="20" fill="red"/></svg>';
    $stream = getDecompressedStreams((new HtmlPdf())->addHtml($html)->output());
    check(!str_contains($stream, '/ActualText <FEFF>'), 'An image must not emit an unpositioned empty text marker');
});

test('Flex wrapping, order, basis and row gaps produce separate aligned lines', function () {
    $html = '<div style="display:flex;flex-wrap:wrap;width:100pt;gap:10pt 5pt">'
        . '<div style="flex:0 0 45pt;height:20pt;order:2;background:red">A</div>'
        . '<div style="flex:0 0 45pt;height:20pt;order:1;background:blue">B</div>'
        . '<div style="flex:0 0 45pt;height:20pt;order:3;background:green">C</div></div>';
    $stream = getDecompressedStreams((new HtmlPdf(margin: 0))->addHtml($html)->output());
    $positions = [];
    foreach (textPositions($stream) as $position)
        $positions[trim($position['text'])] = $position;
    check($positions['B']['x'] < $positions['A']['x'], 'Order must change item positions');
    check(abs($positions['B']['y'] - $positions['A']['y']) < .01, 'First two items share a row');
    check(abs($positions['B']['y'] - $positions['C']['y'] - 30) < .01, 'Wrapped row must include the row gap');
});

test('Column flex grows children to their allocated heights', function () {
    $stream = getDecompressedStreams((new HtmlPdf(margin: 0))->addHtml('<div style="display:flex;flex-direction:column;height:100pt;width:80pt;gap:10pt"><div style="flex:1;background:red">A</div><div style="flex:1;background:blue">B</div></div>')->output());
    same(2, substr_count($stream, '80 45 re f'), 'Both flex children must paint their allocated height');
});

test('Column flex with an automatic container height honors absolute flex bases', function () {
    $stream = getDecompressedStreams((new HtmlPdf(margin: 0))->addHtml('<div style="display:flex;flex-direction:column;width:80pt;gap:10pt"><div style="flex:0 0 40pt;background:red">A</div><div style="flex:0 0 40pt;background:blue">B</div></div>')->output());
    same(2, substr_count($stream, '80 40 re f'), 'Absolute bases must determine the automatic container height');
});

test('Single wrapped flex line honors align-content', function () {
    $stream = getDecompressedStreams((new HtmlPdf(margin: 0))->addHtml('<div style="display:flex;flex-wrap:wrap;align-content:flex-end;width:100pt;height:100pt"><div style="width:50pt;height:20pt">Bottom</div></div>')->output());
    $positions = textPositions($stream);
    check($positions[0]['y'] < 762 && $positions[0]['y'] > 740, 'The line must sit at the bottom of the container');
});

test('Display contents preserves block flow and inherited styles', function () {
    $pdf = (new HtmlPdf(margin: 0))->addHtml('<div style="display:contents;color:red"><div>First</div><div>Second</div></div>')->output();
    $positions = textPositions(getDecompressedStreams($pdf));
    check(count($positions) >= 2 && $positions[0]['y'] > $positions[1]['y'], 'Flattened blocks must retain separate lines');
    check(str_contains(getDecompressedStreams($pdf), '1 0 0 rg'), 'Contents must inherit text color');
});

test('Fixed tables honor colgroup spans and render bottom captions', function () {
    $html = '<table style="width:200pt;table-layout:fixed;border-spacing:0;caption-side:bottom"><caption>Caption</caption><colgroup span="2" style="width:50pt"></colgroup><tr><td>A</td><td>B</td><td>C</td></tr><tr><td>Wide unbreakable content</td><td>D</td><td>E</td></tr></table>';
    $positions = textPositions(getDecompressedStreams((new HtmlPdf(margin: 0))->addHtml($html)->output()));
    $byText = [];
    foreach ($positions as $position)
        $byText[trim($position['text'])] = $position;
    check(abs($byText['B']['x'] - $byText['A']['x'] - 50) < .01, 'First fixed column must stay 50pt');
    check(abs($byText['C']['x'] - $byText['B']['x'] - 50) < .01, 'Colgroup span must apply to both columns');
    check($byText['Caption']['y'] < $byText['E']['y'], 'Caption must render below rows');
});

test('Lists support reversed numbering, value overrides, Roman and extended alphabetic markers', function () {
    same('aa.', ListMarker::text(27, 'lower-alpha'));
    same('XXIX.', ListMarker::text(29, 'upper-roman'));
    same('03.', ListMarker::text(3, 'decimal-leading-zero'));
    $text = logicalText(getDecompressedStreams((new HtmlPdf())->addHtml('<ol reversed type="I" style="list-style-position:inside"><li>First</li><li value="8">Second</li><li>Third</li></ol>')->output()));
    foreach (['III. First', 'VIII. Second', 'VII. Third'] as $line)
        check(str_contains($text, $line), 'Missing list label ' . $line);
});

test('Image alternative text, legacy border, percentage height and aspect ratio render', function () {
    $html = '<div style="height:80pt"><img src="missing.png" alt="Missing image" border="2" style="display:block;height:50%;aspect-ratio:2/1"></div>';
    $stream = getDecompressedStreams((new HtmlPdf(margin: 0))->addHtml($html)->output());
    check(str_contains(logicalText($stream), 'Missing image'), 'Missing image must show its alternative text');
    same(4, substr_count($stream, 'RG 1.5 w'), 'Legacy image border must render');
    check(str_contains($stream, '1.5 840.39 m 81.5 840.39 l'), 'Percentage image height and aspect ratio must set the content box');
});

test('Double borders paint two strokes per side and preserve rounded corners', function () {
    $pdf = (new HtmlPdf())->addHtml('<div style="width:100pt;height:30pt;border:6pt double red">A</div><div style="width:100pt;height:30pt;border:6pt double blue;border-radius:10pt">B</div>')->output();
    $stream = getDecompressedStreams($pdf);
    same(2, substr_count($stream, '1 0 0 RG 2 w'), 'Square double border requires two closed paths');
    same(2, substr_count($stream, '0 0 1 RG 2 w'), 'Rounded double border requires two closed paths');
});

test('Absolute calc lengths retain borders and text spacing', function () {
    same(4.0, Length::parse('calc(2pt + 2pt)', 10, 10, 600, 800));
    $pdf = (new HtmlPdf())->addHtml('<div id="box" style="border:calc(1pt + 1pt) solid red;letter-spacing:calc(1pt + 1pt);border-spacing:calc(2pt + 2pt)">Calculated</div>');
    $style = cssSupportStyles($pdf)['box'];
    same(2.0, $style['border'][0]['w']);
    same(2.0, $style['letter-spacing']);
    same(4.0, $style['spacing']);
});

test('Relative stylesheet base paths resolve only once', function () {
    $doc = new DOMDocument();
    $doc->loadHTML('<link rel="stylesheet" href="theme.css">');
    $requested = [];
    Spark\Pdf\Css\Stylesheet::collect($doc, function (string $path) use (&$requested): ?string {
        $requested[] = $path;
        return null;
    }, static fn(string $media): bool => true, 'assets');
    same(getcwd() . '/assets/theme.css', $requested[0]);
});

test('Wrap reverse reverses cross-axis alignment within each line', function () {
    $item = ['el' => new DOMElement('div'), 's' => ['width' => 50.0, 'height' => 20.0], 'naturalW' => 50.0, 'naturalH' => 20.0];
    foreach (['flex-start' => 80.0, 'center' => 40.0, 'flex-end' => 0.0] as $align => $expectedY) {
        $layout = Spark\Pdf\Layout\FlexEngine::layout([$item], 0, 0, 100, ['flex-wrap' => 'wrap-reverse', 'contentHeight' => 100.0, 'align-items' => $align]);
        same($expectedY, $layout['items'][0]['y'], 'Reverse alignment for ' . $align);
    }
});

test('Flex percentage heights use the container when measuring alignment', function () {
    $html = '<div style="display:flex;align-items:center;width:100pt;height:100pt"><div style="height:50%;width:50pt;background:red">Half</div></div>';
    $stream = getDecompressedStreams((new HtmlPdf(margin: 0))->addHtml($html)->output());
    $position = textPositions($stream)[0];
    check($position['y'] > 800 && $position['y'] < 820, 'Half-height child must be centered inside the 100pt container');
});

test('Column flex applies its allocated height to image items', function () {
    $html = '<div style="display:flex;flex-direction:column;width:100pt"><svg width="100" height="40" style="flex:0 0 80pt;width:100pt;height:40pt;background:red"><rect width="100" height="40" fill="blue"/></svg></div>';
    $stream = getDecompressedStreams((new HtmlPdf(margin: 0))->addHtml($html)->output());
    check(str_contains($stream, '100 80 re f'), 'Image border box must receive the 80pt flex allocation');
});
