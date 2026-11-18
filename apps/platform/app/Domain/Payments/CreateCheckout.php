<?php

namespace App\Domain\Payments;

use App\Domain\Bookings\Booking;
use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\BookingHold;
use App\Domain\Bookings\GuestBookingLinks;
use App\Domain\Bookings\SchedulingTransaction;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Payments\YocoAccount;

final class CreateCheckout
{
    public function execute(Booking $booking, string $key, string $purpose): PaymentAttempt
    {
        $a = app(YocoAccount::class)->configuration();
        $hash = hash('sha256', json_encode([$booking->public_id, $purpose], JSON_THROW_ON_ERROR));
        $attempt = app(SchedulingTransaction::class)->execute(function () use ($booking, $key, $purpose, $a, $hash) {
            $b = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $old = PaymentAttempt::where('idempotency_key', $key)->first();
            if ($old) {
                if (! hash_equals($old->request_hash, $hash)) {
                    throw BookingConflict::because('idempotency_conflict', 'This payment key was already used.');
                }

                if (in_array($old->status, ['creating', 'ready'], true)) {
                    if (! in_array($b->status, ['pending_payment', 'pending_confirmation', 'confirmed'], true)) {
                        throw BookingConflict::because('booking_closed', 'This booking can no longer accept checkout payments.');
                    }
                    if ($old->purpose === 'reservation' && ! BookingHold::where('booking_id', $b->id)->where('purpose', 'initial')->where('status', 'active')->where('expires_at', '>', now())->exists()) {
                        throw BookingConflict::because('hold_expired', 'This hold has expired. Review availability before making a payment.');
                    }
                }

                return $old;
            }
            if (! in_array($b->status, ['pending_payment', 'pending_confirmation', 'confirmed'], true)) {
                throw BookingConflict::because('booking_closed', 'This booking cannot accept checkout payments.');
            }
            if ($purpose === 'reservation' && ! BookingHold::where('booking_id', $b->id)->where('purpose', 'initial')->where('status', 'active')->where('expires_at', '>', now())->exists()) {
                throw BookingConflict::because('hold_expired', 'The reservation hold has expired. Refresh availability before paying.');
            }
            if ($purpose === 'balance' && $b->status !== 'confirmed') {
                throw BookingConflict::because('booking_not_confirmed', 'Balance payments require a confirmed booking.');
            }
            $total = (int) QuoteRevision::whereKey($b->accepted_revision_id)->value('total_minor');
            $paid = app(PaymentBalance::class)->paid($b);
            $amount = ($purpose === 'reservation' ? $b->required_payment_minor : $total) - $paid;
            if ($amount < 200 || $b->currency !== 'ZAR') {
                throw BookingConflict::because('nothing_to_collect', 'There is no supported outstanding payment of at least R2.00.');
            }
            $pending = PaymentAttempt::where('booking_id', $b->id)->whereIn('status', ['creating', 'ready'])->first();
            if ($pending) {
                throw BookingConflict::because('checkout_exists', 'A checkout already exists for this booking. Resume it or contact operations before opening another.');
            }
            $return = app(GuestBookingLinks::class)->forQuote(Quote::findOrFail($b->quote_id))['view'];

            return PaymentAttempt::create(['booking_id' => $b->id, 'idempotency_key' => $key, 'request_hash' => $hash, 'purpose' => $purpose, 'mode' => $a['mode'], 'amount_minor' => $amount, 'currency' => $b->currency, 'status' => 'creating', 'checkout_request' => ['amount' => $amount, 'currency' => $b->currency, 'clientReferenceId' => $b->public_id, 'metadata' => ['bookingPublicId' => $b->public_id, 'tenantPublicId' => app(TenantContext::class)->tenant()->public_id, 'paymentKey' => $key], 'successUrl' => $return, 'cancelUrl' => $return, 'failureUrl' => $return]]);
        });
        if ($attempt->status !== 'creating') {
            return $attempt;
        }
        // Network I/O is outside booking row locks; provider idempotency makes retries safe.
        $checkout = app(CheckoutGateway::class)->create($attempt);

        return app(SchedulingTransaction::class)->execute(function () use ($attempt, $checkout) {
            $p = PaymentAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($p->status === 'creating') {
                $p->provider_checkout_id = $checkout['id'];
                $p->redirect_url = $checkout['redirect_url'];
                $p->status = 'ready';
                $p->save();
            }

            return $p;
        });
    }
}
