<?php

declare(strict_types=1);

use Spark\Pdf\HtmlPdf;

test('HtmlPdf renders table with rowspan and colspan', function () {
    $pdf = new HtmlPdf('A4');
    $html = <<<'HTML'
    <table border="1" cellpadding="6" style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th colspan="2">Merged Header</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td rowspan="2" style="background: #fef3c7;">Category Spanning 2 Rows</td>
                <td>Item 1</td>
                <td>$100.00</td>
            </tr>
            <tr>
                <td>Item 2</td>
                <td>$150.00</td>
            </tr>
            <tr>
                <td>Standalone</td>
                <td>Item 3</td>
                <td>$200.00</td>
            </tr>
        </tbody>
    </table>
HTML;
    $pdf->addHtml($html);
    $bytes = $pdf->output();

    check(str_starts_with($bytes, '%PDF-1.4'), 'Must start with PDF header');
    check(strlen($bytes) > 500, 'Must produce valid PDF');
});
