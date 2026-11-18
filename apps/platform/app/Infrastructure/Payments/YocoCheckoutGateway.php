<?php

namespace App\Infrastructure\Payments;

use App\Domain\Bookings\BookingConflict;
use App\Domain\Payments\CheckoutGateway;
use App\Domain\Payments\PaymentAttempt;
use Illuminate\Support\Facades\Http;

final class YocoCheckoutGateway implements CheckoutGateway
{
    public function create(PaymentAttempt $attempt): array
    {
        $a = app(YocoAccount::class)->configuration();
        try {
            $response = Http::withToken($a['secret_key'])->acceptJson()->withHeaders(['Idempotency-Key' => $attempt->public_id])->connectTimeout(5)->timeout(20)->post('https://payments.yoco.com/api/checkouts', $attempt->checkout_request);
            if (! $response->successful()) {
                throw new \RuntimeException('Gateway did not accept checkout.');
            }$r = $response->json();
            if (! is_array($r) || ! is_string($r['id'] ?? null) || ! is_string($r['redirectUrl'] ?? null) || ($r['amount'] ?? null) !== $attempt->amount_minor || ($r['currency'] ?? null) !== $attempt->currency || ($r['processingMode'] ?? null) !== $attempt->mode || parse_url($r['redirectUrl'], PHP_URL_SCHEME) !== 'https' || ! in_array(parse_url($r['redirectUrl'], PHP_URL_HOST), ['c.yoco.com', 'pay.yoco.com'], true)) {
                throw new \RuntimeException('Invalid checkout response.');
            }

            return ['id' => $r['id'], 'redirect_url' => $r['redirectUrl']];
        } catch (\Throwable $e) {
            throw BookingConflict::because('gateway_unavailable', 'Yoco checkout is temporarily unavailable. Retry this payment request; no booking is confirmed by this response.');
        }
    }
}
