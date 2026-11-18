<?php

namespace App\Infrastructure\Storage;

use App\Domain\Invoicing\Invoice;
use App\Domain\Invoicing\InvoiceDocumentRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;

final class DompdfInvoiceRenderer implements InvoiceDocumentRenderer
{
    public function render(Invoice $invoice): string
    {
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'chroot' => public_path()]);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml(view('invoices.pdf', ['invoice' => $invoice])->render(), 'UTF-8');
        $pdf->render();

        return $pdf->output();
    }
}
