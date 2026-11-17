<?php

namespace App\Console\Commands;

use App\Domain\Quotes\Quote;
use App\Domain\Quotes\WriteQuoteRevision;
use App\Domain\Routing\RouteCalculation;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PurgeRouteResults extends Command
{
    protected $signature = 'routes:purge-results';

    protected $description = 'Remove transient provider route metrics after their configured retention window.';

    public function handle(TenantContext $context): int
    {
        // Explicit administrative scan; request parameters cannot influence the tenant boundary.
        foreach (Tenant::whereIn('id', DB::table('route_calculations')->select('tenant_id')->where('retain_until', '<=', now()))->lazyById(100) as $tenant) {
            $context->set($tenant);
            try {
                foreach (RouteCalculation::where('retain_until', '<=', now())->lazyById(100) as $route) {
                    DB::transaction(function () use ($route): void {
                        $quote = Quote::whereKey($route->quote_id)->lockForUpdate()->firstOrFail();
                        if ($quote->status !== 'accepted') {
                            $quote->draft_input = app(WriteQuoteRevision::class)->snapshotInput($quote->draft_input);
                            $quote->save();
                        }
                        $route->fill(['customer_metres' => null, 'depot_metres' => null, 'return_metres' => null, 'duration_seconds' => null, 'depot_duration_seconds' => null, 'return_duration_seconds' => null, 'tolls_minor' => null, 'retain_until' => null])->save();
                    });
                }
            } finally {
                $context->clear();
            }
        }

        return self::SUCCESS;
    }
}
