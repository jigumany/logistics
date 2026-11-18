<?php

namespace App\Domain\Invoicing;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\SchedulingTransaction;
use App\Domain\Notifications\OutboxMessage;
use App\Domain\Quotes\QuotePermissions;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final class IssueInvoice
{
    public function execute(Booking $booking, int $actor, string $dueDate, array $billing = []): Invoice
    {
        app(QuotePermissions::class)->manage($actor);

        return app(SchedulingTransaction::class)->execute(function () use ($booking, $actor, $dueDate, $billing) {
            $b = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $old = Invoice::where('booking_id', $b->id)->first();
            if ($old) {
                return $old;
            }
            if ($b->status !== 'confirmed') {
                throw BookingConflict::because('invoice_not_ready', 'Only a confirmed booking can be invoiced.');
            }
            $r = QuoteRevision::with('lines')->findOrFail($b->accepted_revision_id);
            $s = CompanySettings::firstOrFail();
            if (! $s->registered_name || ! $s->billing_address || ! $s->contact_email) {
                throw BookingConflict::because('supplier_incomplete', 'Configure the company legal name, billing address and contact email before issuing invoices.');
            }
            if ((int) $r->tax_minor > 0 && ! $s->vat_number) {
                throw BookingConflict::because('tax_identity_missing', 'A VAT number is required for this quotation’s tax amount.');
            }
            if ($r->tax_minor > 0 && $r->total_minor > 500000 && empty($billing['billing_address'])) {
                throw BookingConflict::because('billing_address_required', 'A full tax invoice requires the customer billing address.');
            }
            $customer = [...$r->contact_snapshot, ...array_intersect_key($billing, array_flip(['billing_address', 'vat_number', 'billing_name']))];
            $tenant = app(TenantContext::class)->tenant()->id;
            DB::table('invoice_sequences')->insertOrIgnore(['tenant_id' => $tenant, 'next_number' => 1]);
            $seq = DB::table('invoice_sequences')->where('tenant_id', $tenant)->lockForUpdate()->first();
            $number = 'INV-'.str_pad((string) $seq->next_number, 7, '0', STR_PAD_LEFT);
            DB::table('invoice_sequences')->where('tenant_id', $tenant)->increment('next_number');
            $invoice = Invoice::create(['booking_id' => $b->id, 'revision_id' => $r->id, 'number' => $number, 'currency' => $b->currency, 'subtotal_minor' => $r->subtotal_minor, 'tax_minor' => $r->tax_minor, 'total_minor' => $r->total_minor, 'supplier_snapshot' => $s->only(['registered_name', 'registration_number', 'vat_number', 'billing_address', 'contact_email', 'contact_phone']), 'customer_snapshot' => $customer, 'lines' => $r->lines->map(fn ($l) => $l->only(['code', 'description', 'quantity', 'rate_micros', 'amount_minor']))->all(), 'due_date' => $dueDate, 'issued_at' => now(), 'issued_by' => $actor]);
            OutboxMessage::create(['event_type' => 'invoice.issued', 'payload' => ['invoice_public_id' => $invoice->public_id, 'booking_public_id' => $b->public_id]]);

            return $invoice;
        });
    }
}
