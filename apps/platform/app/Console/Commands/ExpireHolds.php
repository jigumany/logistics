<?php

namespace App\Console\Commands;

use App\Domain\Bookings\ExpireBookingHolds;
use App\Domain\Bookings\SchedulingTransaction;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireHolds extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Release expired temporary reservations and expire payment-pending bookings.';

    public function handle(TenantContext $context): int
    {
        foreach (Tenant::whereIn('id', DB::table('booking_holds')->where('status', 'active')->where('expires_at', '<=', now())->select('tenant_id'))->lazyById(100) as $tenant) {
            $context->set($tenant);
            try {
                app(SchedulingTransaction::class)->execute(fn () => app(ExpireBookingHolds::class)->execute());
            } finally {
                $context->clear();
            }
        }

        return self::SUCCESS;
    }
}
