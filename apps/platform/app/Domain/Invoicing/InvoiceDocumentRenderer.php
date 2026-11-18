<?php

namespace App\Domain\Invoicing;

interface InvoiceDocumentRenderer
{
    public function render(Invoice $invoice): string;
}
