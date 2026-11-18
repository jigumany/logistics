<?php

namespace App\Domain\Payments;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\ReconcileBookingPayment;
use App\Domain\Bookings\SchedulingTransaction;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Payments\YocoAccount;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

final class ProcessYocoEvent
{
    public function execute(array $event, string $rawHash): void
    {
        $a = app(YocoAccount::class)->configuration();
        if (($event['type'] ?? '') === 'checkout.expired') {
            app(ExpireYocoCheckout::class)->execute($event, $rawHash);

            return;
        }
        if (! in_array($event['type'] ?? '', ['payment.succeeded', 'payment.failed'], true)) {
            return;
        }
        $v = Validator::make($event, ['id' => ['required', 'string', 'max:160'], 'payload' => ['required', 'array'], 'payload.id' => ['required', 'string', 'max:160'], 'payload.type' => ['required', 'in:payment'], 'payload.status' => ['required', 'string'], 'payload.amount' => ['required', 'integer', 'min:1'], 'payload.currency' => ['required', 'in:ZAR'], 'payload.mode' => ['required', 'in:'.$a['mode']], 'payload.metadata.paymentKey' => ['required', 'uuid'], 'payload.metadata.bookingPublicId' => ['required', 'ulid'], 'payload.metadata.tenantPublicId' => ['required', 'ulid']])->validate();
        $p = $v['payload'];
        $booking = app(SchedulingTransaction::class)->execute(function () use ($event, $v, $p, $rawHash) {
            $attempt = PaymentAttempt::where('idempotency_key', $p['metadata']['paymentKey'])->lockForUpdate()->firstOrFail();
            $booking = Booking::findOrFail($attempt->booking_id);
            if ($booking->public_id !== $p['metadata']['bookingPublicId'] || app(TenantContext::class)->tenant()->public_id !== $p['metadata']['tenantPublicId'] || $attempt->amount_minor !== $p['amount'] || $attempt->currency !== $p['currency'] || $attempt->mode !== $p['mode']) {
                throw BookingConflict::because('payment_mismatch', 'Payment evidence does not match the recorded checkout.');
            }
            $old = PaymentWebhookEvent::where('provider_event_id', $v['id'])->first();
            if ($old) {
                if (! hash_equals($old->payload_hash, $rawHash)) {
                    throw BookingConflict::because('event_mismatch', 'A payment event identifier was reused with different evidence.');
                }

                return $booking;
            }
            if ($event['type'] === 'payment.succeeded') {
                if (($p['status'] ?? null) !== 'succeeded' || ($p['type'] ?? null) !== 'payment') {
                    throw BookingConflict::because('payment_mismatch', 'This event is not a successful payment.');
                }
                if ($attempt->status === 'succeeded' && $attempt->provider_payment_id !== $p['id']) {
                    throw BookingConflict::because('payment_mismatch', 'This checkout has already been paid with different evidence.');
                }
                if ($attempt->status !== 'succeeded') {
                    $attempt->status = 'succeeded';
                    $attempt->provider_payment_id = $p['id'];
                    $attempt->verified_at = now();
                    $attempt->save();
                }
            } elseif ($attempt->status !== 'succeeded') {
                $attempt->status = 'failed';
                $attempt->save();
            }
            $paid = (int) PaymentAttempt::where('booking_id', $booking->id)->where('status', 'succeeded')->sum('amount_minor');
            $total = (int) QuoteRevision::whereKey($booking->accepted_revision_id)->value('total_minor');
            if ($paid > $total && $booking->refund_state !== 'review_required') {
                $booking->refund_state = 'review_required';
                $booking->save();
            }
            PaymentWebhookEvent::create(['attempt_id' => $attempt->id, 'provider_event_id' => $v['id'], 'payload_hash' => $rawHash, 'type' => $event['type'], 'outcome' => $event['type'] === 'payment.succeeded' ? 'verified' : 'failed', 'processed_at' => now()]);

            return $booking;
        });
        // Evidence commits independently so operational recovery cannot lose a received payment.
        if ($event['type'] === 'payment.succeeded' && $booking->required_payment_minor > 0 && in_array($booking->status, ['pending_payment', 'pending_confirmation', 'expired', 'cancelled'], true)) {
            app(ReconcileBookingPayment::class)->execute($booking, (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'yoco:'.$event['id']));
        }
    }
}
