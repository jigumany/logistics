<?php

namespace App\Domain\Payments;

use App\Domain\Bookings\BookingConflict;
use App\Domain\Bookings\SchedulingTransaction;
use App\Domain\Quotes\QuotePermissions;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use Illuminate\Support\Facades\Validator;

final class ConfigureYoco
{
    public function execute(array $input, int $actor): void
    {
        app(QuotePermissions::class)->manage($actor);
        app(SchedulingTransaction::class)->execute(function () use ($input, $actor) {
            $account = PaymentAccount::where('provider', 'yoco')->first();
            $v = Validator::make($input, ['mode' => ['required', 'in:test,live'], 'secret_key' => ['nullable', 'string', 'max:250'], 'webhook_secret' => ['nullable', 'string', 'max:250']])->validate();
            if ($account && $account->mode !== $v['mode'] && PaymentAttempt::whereIn('status', ['creating', 'ready'])->exists()) {
                throw BookingConflict::because('payments_pending', 'Resolve existing checkouts before changing Yoco mode.');
            }
            $secret = $v['secret_key'] ?? ($account?->mode === $v['mode'] ? $account?->secret_key : null);
            $webhook = $v['webhook_secret'] ?? ($account?->mode === $v['mode'] ? $account?->webhook_secret : null);
            Validator::make(['secret_key' => $secret, 'webhook_secret' => $webhook], ['secret_key' => ['required', 'string', 'starts_with:sk_'.$v['mode'].'_'], 'webhook_secret' => ['required', 'string', 'starts_with:whsec_']])->validate();
            $bytes = base64_decode(substr($webhook, 6), true);
            Validator::make(['webhook_secret' => $bytes !== false && strlen($bytes) >= 16], ['webhook_secret' => ['accepted']])->validate();
            $account ??= new PaymentAccount;
            $account->fill(['provider' => 'yoco', 'mode' => $v['mode'], 'secret_key' => $secret, 'webhook_secret' => $webhook])->save();
            app(RecordsAdministrativeChange::class)->record($account, 'payments.yoco_configured', $actor);
        });
    }
}
