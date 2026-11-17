<?php

use App\Domain\Bookings\BookingHold;
use App\Domain\Bookings\BookingHoldResource;
use App\Domain\Bookings\ResourceAllocation;
use App\Domain\Fleet\Vehicle;
use App\Domain\Quotes\AcceptQuote;
use App\Domain\Quotes\SubmitQuote;
use App\Domain\Resources\Resource;
use App\Domain\Resources\SaveStaffMember;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ledgerFixture(): array
{
    [$tenant,$owner,$service] = quoteFixture();
    $vehicle = Vehicle::with('type')->firstOrFail();
    app(SaveStaffMember::class)->execute(['full_name' => 'Qualified driver', 'role' => 'driver', 'active' => true, 'engagement_status' => 'employed', 'licence_category' => 'C1', 'licence_expires_on' => now()->addYear()->toDateString(), 'eligible_vehicle_classes' => [$vehicle->type->vehicle_class]], $owner->id);
    $quotes = [];
    for ($i = 0; $i < 2; $i++) {
        $q = calculatedQuote($service);
        $q = app(SubmitQuote::class)->execute($q, [...quoteContact(), 'email' => "ledger{$i}@example.test"], $q->lock_version);
        $quotes[] = app(AcceptQuote::class)->execute($q, $q->currentRevision->public_id);
    }

    return [$tenant, $quotes, Resource::orderBy('id')->get(), $service, $owner];
}
function ledgerReserve($quote, $resources, CarbonImmutable $start): BookingHold
{
    return DB::transaction(function () use ($quote, $resources, $start) {
        $hold = BookingHold::create(['quote_id' => $quote->id, 'accepted_revision_id' => $quote->current_revision_id, 'customer_id' => $quote->customer_id, 'purpose' => 'initial', 'status' => 'active', 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(15),
            'arrival_starts_at' => $start, 'arrival_ends_at' => $start->addMinutes(30), 'service_starts_at' => $start, 'service_ends_at' => $start->addHours(2), 'allocation_starts_at' => $start, 'allocation_ends_at' => $start->addHours(2), 'requirements' => ['vehicles' => 1, 'drivers' => 1, 'movers' => 0], 'duration_snapshot' => []]);
        foreach ($resources as $r) {
            BookingHoldResource::create(['hold_id' => $hold->id, 'resource_id' => $r->id]);
            ResourceAllocation::create(['resource_id' => $r->id, 'hold_id' => $hold->id, 'status' => 'held', 'starts_at' => $start, 'ends_at' => $start->addHours(2)]);
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        return $hold;
    });
}
it('enforces exclusive half-open truck and driver intervals in PostgreSQL', function () {
    [$tenant,$quotes,$resources] = ledgerFixture();
    $start = CarbonImmutable::now()->addWeek()->startOfDay()->addHours(9);
    ledgerReserve($quotes[0], $resources, $start);
    try {
        ledgerReserve($quotes[1], $resources, $start);
        $this->fail('Overlapping hold was permitted');
    } catch (QueryException $e) {
        expect($e->errorInfo[0])->toBe('23P01');
    }
    expect(BookingHold::count())->toBe(1)->and(ResourceAllocation::where('status', 'held')->count())->toBe(2);
    ledgerReserve($quotes[1], $resources, $start->addHours(2));
    expect(BookingHold::count())->toBe(2);
});
it('rejects committing a partially allocated hold', function () {
    [$tenant,$quotes,$resources] = ledgerFixture();
    $start = CarbonImmutable::now()->addWeek()->startOfDay()->addHours(9);
    expect(fn () => ledgerReserve($quotes[0], $resources->take(1), $start))->toThrow(QueryException::class);
    expect(BookingHold::count())->toBe(0)->and(ResourceAllocation::count())->toBe(0);
});
