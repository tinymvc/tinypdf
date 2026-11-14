<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Spark\Pdf\HtmlPdf;

$pdf = new HtmlPdf('A4', margin: [16, 16, 18, 16], footer: 'Page {page} of {pages}');
$pdf->setTitle('Invoice INV-1042')->setAuthor('Acme Studio');
$pdf->addHtml(<<<'HTML'
<style>
    :root { --ink: #172b38; --accent: #0f766e; --muted: #64748b; }
    body { font-family: Helvetica, sans-serif; font-size: 10pt; color: var(--ink); }
    p { margin: 0 0 8pt; line-height: 1.5; }
    h1 { margin: 0 0 6pt; font-size: 30pt; letter-spacing: -0.5pt; }
    h2 { margin: 0 0 10pt; font-size: 12pt; }
    .eyebrow { font-size: 8pt; letter-spacing: 1.5pt; color: var(--muted); }
    .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 26pt; }
    .muted { color: var(--muted); }
    .badge { background: #dcfce7; color: #166534; padding: 5pt 10pt; border-radius: 20pt; font-size: 8pt; font-weight: bold; }
    .cards { display: flex; gap: 14pt; margin-bottom: 24pt; }
    .card { flex: 1; background: #f1f5f9; padding: 16pt; border-radius: 8pt; }
    .card p { margin: 0 0 5pt; }
    .label { font-size: 8pt; color: var(--muted); }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; padding: 10pt 8pt; background: var(--accent); color: white; font-size: 8pt; }
    td { padding: 12pt 8pt; border-bottom: 1px solid #e2e8f0; }
    .number { text-align: right; }
    .summary { margin-top: 18pt; margin-left: auto; width: 220pt; }
    .summary td { padding: 7pt 8pt; border: none; }
    .total td { background: #e6f3f0; font-size: 14pt; font-weight: bold; color: var(--accent); padding: 12pt 8pt; }
    .note { margin-top: 28pt; border-top: 1px solid #e2e8f0; padding-top: 14pt; font-size: 9pt; }
</style>
<div class="header">
    <div><p class="eyebrow">ACME STUDIO / BILLING</p><h1>Invoice</h1><p class="muted">INV-1042 &nbsp; / &nbsp; 14 November 2026</p></div>
    <div><span class="badge">PAID IN FULL</span></div>
</div>
<div class="cards">
    <div class="card"><p class="label">BILLED TO</p><h2>Northstar Labs</h2><p>24 Lake Road<br>Dhaka 1205, Bangladesh</p><p class="muted">accounts@example.com</p></div>
    <div class="card"><p class="label">PAYMENT DETAILS</p><h2>Project delivery</h2><p>Due: 28 November 2026<br>Currency: USD</p><p class="muted">Reference: NS-2026-42</p></div>
</div>
<table>
    <thead><tr><th>Description</th><th class="number">Qty</th><th class="number">Rate</th><th class="number">Amount</th></tr></thead>
    <tbody>
        <tr><td><b>Product design</b><br><span class="muted">Interface layouts and component library</span></td><td class="number">1</td><td class="number">$1,200.00</td><td class="number">$1,200.00</td></tr>
        <tr><td><b>Implementation</b><br><span class="muted">Responsive templates and integration</span></td><td class="number">16</td><td class="number">$75.00</td><td class="number">$1,200.00</td></tr>
        <tr><td><b>Quality review</b><br><span class="muted">Final checks and delivery</span></td><td class="number">4</td><td class="number">$50.00</td><td class="number">$200.00</td></tr>
    </tbody>
</table>
<table class="summary">
    <tr><td>Subtotal</td><td class="number">$2,600.00</td></tr>
    <tr><td>Discount</td><td class="number">-$100.00</td></tr>
    <tr class="total"><td>Total paid</td><td class="number">$2,500.00</td></tr>
</table>
<div class="note"><p><b>Thank you for working with Acme Studio.</b></p><p class="muted">Please include INV-1042 in any correspondence. <a href="https://example.com/invoices/1042" style="color:#0f766e">View invoice details</a></p></div>
HTML);
$pdf->save($argv[1] ?? 'invoice.pdf');
