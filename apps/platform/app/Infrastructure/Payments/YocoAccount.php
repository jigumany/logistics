<?php

namespace App\Infrastructure\Payments;

use App\Domain\Bookings\BookingConflict;
use App\Domain\Payments\PaymentAccount;
use App\Domain\Tenancy\TenantContext;

final class YocoAccount
{
    public function configuration(): array
    {
        $slug = app(TenantContext::class)->tenant()->slug;
        $stored = PaymentAccount::where('provider', 'yoco')->first();
        $a = $stored ? ['mode' => $stored->mode, 'secret_key' => $stored->secret_key, 'webhook_secret' => $stored->webhook_secret] : config('payments.yoco.accounts.'.$slug, []);
        if (! in_array($a['mode'] ?? null, ['test', 'live'], true) || ! str_starts_with($a['secret_key'] ?? '', 'sk_'.($a['mode'] ?? '').'_') || ! str_starts_with($a['webhook_secret'] ?? '', 'whsec_')) {
            throw BookingConflict::because('payments_not_configured', 'Yoco payments have not been configured for this company. Contact operations.');
        }
        if (app()->isProduction() && $a['mode'] !== 'live') {
            throw BookingConflict::because('test_payments_disabled', 'Test payments cannot be used in production.');
        }

        return $a;
    }
}
