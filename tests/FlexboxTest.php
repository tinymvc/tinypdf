<?php

declare(strict_types=1);

use Spark\Pdf\HtmlPdf;

test('HtmlPdf renders flex row with space-between and gap', function () {
    $pdf = new HtmlPdf('A4');
    $html = <<<'HTML'
    <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; background: #f3f4f6; padding: 10px;">
        <div style="width: 150px; background: #3b82f6; color: #fff; padding: 8px;">Left Box</div>
        <div style="flex: 1; background: #10b981; color: #fff; padding: 8px;">Middle Flex 1</div>
        <div style="width: 100px; background: #ef4444; color: #fff; padding: 8px;">Right Box</div>
    </div>
HTML;
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_starts_with($bytes, '%PDF-1.4'), 'Must start with PDF header');
    check(strlen($bytes) > 500, 'Must produce PDF content');
});

test('HtmlPdf renders flex column with gap', function () {
    $pdf = new HtmlPdf('A4');
    $html = <<<'HTML'
    <div style="display: flex; flex-direction: column; gap: 12px;">
        <div style="background: #e0f2fe; padding: 6px;">Row 1</div>
        <div style="background: #e0f2fe; padding: 6px;">Row 2</div>
    </div>
HTML;
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_starts_with($bytes, '%PDF-1.4'), 'Must produce valid PDF');
});
