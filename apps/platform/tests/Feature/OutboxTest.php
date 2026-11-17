<?php

use App\Domain\Notifications\DomainEvent;
use App\Domain\Notifications\OutboxMessage;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('delivers pending events and does not redeliver published ones', function (): void {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    app(TenantContext::class)->set($tenant);
    OutboxMessage::create(['event_type' => 'test.created', 'payload' => ['value' => 1]]);
    app(TenantContext::class)->clear();
    Event::fake([DomainEvent::class]);
    $this->artisan('outbox:publish')->assertSuccessful();
    $this->artisan('outbox:publish')->assertSuccessful();
    Event::assertDispatchedTimes(DomainEvent::class, 1);
    expect(DB::table('outbox_messages')->whereNull('published_at')->count())->toBe(0);
    expect(fn () => app(TenantContext::class)->tenant())->toThrow(LogicException::class);
});
it('keeps failed deliveries pending for retry and clears tenant context', function (): void {
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    app(TenantContext::class)->set($tenant);
    OutboxMessage::create(['event_type' => 'test.created', 'payload' => []]);
    app(TenantContext::class)->clear();
    Event::listen(DomainEvent::class, fn () => throw new RuntimeException('Delivery failed'));
    expect(fn () => $this->artisan('outbox:publish')->run())->toThrow(RuntimeException::class);
    expect(DB::table('outbox_messages')->whereNull('published_at')->count())->toBe(1);
    expect(fn () => app(TenantContext::class)->tenant())->toThrow(LogicException::class);
});
