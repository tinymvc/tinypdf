<?php

declare(strict_types=1);

use Spark\Pdf\HtmlPdf;

test('HtmlPdf renders SVG elements and clickable hyperlinks', function () {
    $pdf = new HtmlPdf('A4');
    $html = <<<'HTML'
    <h1>Document with SVG and Links</h1>
    <svg width="60" height="60" viewBox="0 0 100 100">
        <circle cx="50" cy="50" r="40" fill="#2563eb" stroke="#1d4ed8" stroke-width="4" />
        <rect x="30" y="30" width="40" height="40" fill="#ffffff" />
    </svg>
    <p>
        Visit our <a href="https://example.com" style="color: #2563eb; font-weight: bold;">Official Website</a>.
    </p>
    <p>
        Status: <span style="background: #dcfce7; color: #15803d; padding: 3px 8px; border-radius: 9999px; font-size: 9pt;">Active User</span>
    </p>
HTML;
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_starts_with($bytes, '%PDF-1.7'), 'Must start with PDF header');
    check(str_contains($bytes, '/Subtype /Link'), 'Must contain clickable Link annotation');
    check(str_contains($bytes, '(https://example.com)'), 'Must contain target URI');
});

test('SVG quadratic and arc paths terminate and produce curves', function () {
    $svg = '<svg width="100" height="100"><path d="M10 50 Q30 10 50 50 T90 50 A20 20 0 0 1 50 90 Z"/></svg>';
    $result = \Spark\Pdf\Graphics\SvgRenderer::render($svg, 0, 0, 100, 100, 800);
    check(substr_count($result['ops'], ' c ') >= 3, 'Quadratic and arc segments must produce cubic PDF paths');
    foreach (['10 20', 'M10', 'M10 20Z1', 'M0 0Q1 2Z', 'M0 0A1 2 3'] as $path) {
        $result = \Spark\Pdf\Graphics\SvgRenderer::render('<svg><path d="' . $path . '"/></svg>', 0, 0, 100, 100, 800);
        check(strlen($result['ops']) < 1000, 'Malformed paths must terminate without generating unbounded output');
    }
});
