<?php

namespace App\Domain\Payments;

use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\SchedulingTransaction;
use App\Infrastructure\Payments\YocoAccount;
use Illuminate\Support\Facades\Validator;

final class ExpireYocoCheckout
{
    public function execute(array $event, string $hash): void
    {
        $a = app(YocoAccount::class)->configuration();
        $v = Validator::make($event, ['id' => ['required', 'string', 'max:160'], 'payload.id' => ['required', 'string', 'max:160'], 'payload.type' => ['required', 'in:checkout'], 'payload.status' => ['required', 'in:expired'], 'payload.amount' => ['required', 'integer', 'min:1'], 'payload.currency' => ['required', 'in:ZAR'], 'payload.mode' => ['sometimes', 'in:'.$a['mode']]])->validate();
        app(SchedulingTransaction::class)->execute(function () use ($v, $hash) {
            $p = $v['payload'];
            $attempt = PaymentAttempt::where('provider_checkout_id', $p['id'])->lockForUpdate()->firstOrFail();
            if ($attempt->amount_minor !== $p['amount'] || $attempt->currency !== $p['currency']) {
                throw BookingConflict::because('payment_mismatch', 'The expired checkout does not match the recorded amount.');
            }
            $old = PaymentWebhookEvent::where('provider_event_id', $v['id'])->first();
            if ($old) {
                if (! hash_equals($old->payload_hash, $hash)) {
                    throw BookingConflict::because('event_mismatch', 'This provider event identifier has already been used.');
                }

return;
            }
            if ($attempt->status !== 'succeeded') {
                $attempt->status = 'failed';
                $attempt->save();
            }
            PaymentWebhookEvent::create(['attempt_id' => $attempt->id, 'provider_event_id' => $v['id'], 'payload_hash' => $hash, 'type' => 'checkout.expired', 'outcome' => 'expired', 'processed_at' => now()]);
        });
    }
}
