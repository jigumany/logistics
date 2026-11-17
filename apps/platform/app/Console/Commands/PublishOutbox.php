<?php

namespace App\Console\Commands;

use App\Domain\Notifications\DomainEvent;
use App\Domain\Notifications\OutboxMessage;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class PublishOutbox extends Command
{
    protected $signature = 'outbox:publish {--limit=100}';

    protected $description = 'Deliver committed domain events; consumers must deduplicate using event ID.';

    public function handle(TenantContext $context): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        // Explicit system-wide scan: no request data can influence this administrative boundary.
        $tenants = Tenant::query()->whereIn('id', DB::table('outbox_messages')->select('tenant_id')->whereNull('published_at'))->lazyById(100);
        foreach ($tenants as $tenant) {
            $context->set($tenant);
            try {
                for ($i = 0; $i < $limit; $i++) {
                    $context->set($tenant);
                    $published = DB::transaction(function (): bool {
                        $query = OutboxMessage::query()->whereNull('published_at')->orderBy('id');
                        $message = $query->lock(DB::getDriverName() === 'pgsql' ? 'FOR UPDATE SKIP LOCKED' : true)->first();
                        if (! $message) {
                            return false;
                        }
                        Event::dispatch(new DomainEvent($message->public_id, $message->event_type, $message->payload));
                        $message->published_at = now();
                        $message->save();

                        return true;
                    });
                    if (! $published) {
                        break;
                    }
                }
            } finally {
                $context->clear();
            }
        }

        return self::SUCCESS;
    }
}
