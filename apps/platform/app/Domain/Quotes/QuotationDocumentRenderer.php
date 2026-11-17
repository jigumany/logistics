<?php

namespace App\Domain\Quotes;

interface QuotationDocumentRenderer
{
    public function render(Quote $quote, QuoteRevision $revision): string;
}
