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

    check(str_starts_with($bytes, '%PDF-1.4'), 'Must start with PDF header');
    check(str_contains($bytes, '/Subtype /Link'), 'Must contain clickable Link annotation');
    check(str_contains($bytes, '(https://example.com)'), 'Must contain target URI');
});
