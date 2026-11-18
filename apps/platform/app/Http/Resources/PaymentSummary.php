<?php

namespace App\Http\Resources;

use App\Domain\Bookings\Booking;
use App\Domain\Invoicing\Invoice;
use App\Domain\Payments\PaymentAttempt;
use App\Domain\Payments\PaymentBalance;
use App\Domain\Quotes\QuoteRevision;

final class PaymentSummary
{
    public function make(Booking $b): array
    {
        $paid = app(PaymentBalance::class)->paid($b);
        $total = (int) QuoteRevision::whereKey($b->accepted_revision_id)->value('total_minor');
        $latest = PaymentAttempt::where('booking_id', $b->id)->whereIn('status', ['creating', 'ready'])->orderByDesc('id')->first();
        $invoice = Invoice::where('booking_id', $b->id)->first();

        return ['total_minor' => $total, 'paid_minor' => $paid, 'balance_minor' => max(0, $total - $paid), 'currency' => $b->currency, 'checkout' => $latest?->only(['public_id', 'status', 'purpose', 'amount_minor', 'redirect_url', 'idempotency_key']), 'invoice' => $invoice?->only(['public_id', 'number', 'due_date', 'total_minor'])];
    }
}
