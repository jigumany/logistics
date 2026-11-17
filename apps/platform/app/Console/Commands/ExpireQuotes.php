<?php

namespace App\Console\Commands;

use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuoteLifecycle;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Quotes\QuoteStatus;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireQuotes extends Command
{
    protected $signature = 'quotes:expire';

    protected $description = 'Expire issued offers without changing immutable revisions.';

    public function handle(TenantContext $context): int
    {
        foreach (Tenant::lazyById(100) as $tenant) {
            $context->set($tenant);
            try {
                foreach (Quote::where('status', 'quoted')->whereIn('current_revision_id', QuoteRevision::where('expires_at', '<=', now())->select('id'))->lazyById(100) as $quote) {
                    DB::transaction(function () use ($quote): void {
                        $current = Quote::whereKey($quote->id)->with('currentRevision')->lockForUpdate()->firstOrFail();
                        if ($current->status === 'quoted' && $current->currentRevision->expires_at->isPast()) {
                            app(QuoteLifecycle::class)->transition($current, QuoteStatus::Expired);
                        }
                    });
                }
            } finally {
                $context->clear();
            }
        }

        return self::SUCCESS;
    }
}
