<?php

use App\Domain\Fleet\Vehicle;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Pricing\ManagePriceBook;
use App\Domain\Pricing\PriceBook;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\PricingRule;
use App\Domain\Pricing\QuotationReadiness;
use App\Domain\Quotes\AcceptQuote;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\ReviewQuote;
use App\Domain\Quotes\SubmitQuote;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Database\Seeders\DemonstrationPriceBookSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function demoTariffFixture(): array
{
    [$tenant,$owner,$service] = quoteFixture();
    $tenant->update(['slug' => 'cape-town-movers']);
    app(TenantContext::class)->set($tenant);
    $commercial = PriceBook::firstOrFail();
    app(DemonstrationPriceBookSeeder::class)->run();
    app(TenantContext::class)->set($tenant);

    return [$tenant, $owner, $service, PriceBook::where('is_demonstration', true)->firstOrFail(), $commercial];
}
function demoSimulation($service, $book, array $options = [], ?array $input = null): Quote
{
    $input ??= quoteInput($service, ['preferred_date' => now()->addWeek()->next('Monday')->toDateString()]);
    $quote = app(CreateQuoteDraft::class)->execute($input, (string) Str::uuid(), null, $book, [...['vehicle_type_key' => 'demo_light_box_truck', 'additional_movers' => 0, 'waiting_confirmed' => false, 'route_distance_km' => '25', 'route_duration_minutes' => '60'], ...$options]);

    return app(CalculateQuote::class)->execute($quote, 0);
}

it('seeds editable demo tariffs idempotently without altering existing commercial records', function (): void {
    [$tenant,$owner,$service,$book,$commercial] = demoTariffFixture();
    $snapshot = $commercial->getAttributes();
    $settings = CompanySettings::firstOrFail()->getAttributes();
    $vehicles = Vehicle::get()->toArray();
    expect(PricingRule::where('price_book_id', $book->id)->count())->toBe(29);
    expect(PricingRule::where('price_book_id', $book->id)->whereNotNull('vehicle_type_key')->distinct()->count('vehicle_type_key'))->toBe(6);
    $rule = PricingRule::where('price_book_id', $book->id)->where('code', 'demo_light_box_truck_base')->firstOrFail();
    $rule->update(['rate_micros' => 600000000]);
    app(DemonstrationPriceBookSeeder::class)->run();
    app(TenantContext::class)->set($tenant);
    expect(PriceBook::where('is_demonstration', true)->count())->toBe(1)->and($rule->fresh()->rate_micros)->toBe(600000000);
    expect($commercial->fresh()->getAttributes())->toBe($snapshot)->and(CompanySettings::firstOrFail()->getAttributes())->toBe($settings)->and(Vehicle::get()->toArray())->toBe($vehicles);
    expect(app(QuotationReadiness::class)->currentBook($service->id)->id)->toBe($commercial->id);
});
it('supports existing moving and transport services with separate tenant-owned demo drafts', function (): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    foreach (['office', 'general'] as $code) {
        TenantService::create(['code' => $code, 'name' => $code, 'enabled' => true, 'active' => true]);
    }
    app(DemonstrationPriceBookSeeder::class)->run();
    app(TenantContext::class)->set($tenant);
    expect(PriceBook::where('is_demonstration', true)->count())->toBe(3)->and(PriceBook::where('is_demonstration', true)->where('status', 'published')->count())->toBe(0);
});
it('reconciles deterministic demonstration scenarios with exact rules and immutable revisions', function (string $type, int $km, int $minutes, int $movers, int $stairs, bool $weekend, int $total): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $input = quoteInput($service, ['preferred_date' => now()->addWeek()->next($weekend ? 'Saturday' : 'Monday')->toDateString()]);
    $input['details']['pickup_stairs'] = $stairs;
    $quote = demoSimulation($service, $book, ['vehicle_type_key' => $type, 'additional_movers' => $movers, 'route_distance_km' => (string) $km, 'route_duration_minutes' => (string) $minutes], $input);
    $revision = $quote->currentRevision;
    expect($revision->total_minor)->toBe($total)->and($revision->tax_minor)->toBe(0)->and($quote->status)->toBe('requires_review')->and($revision->approval)->toBe('pending');
    expect($revision->pricing_snapshot['version'])->toBe($book->version)->and($revision->pricing_snapshot['price_book_name'])->toBe($book->name)->and($revision->pricing_snapshot['screened_vehicle_type'])->toBe($type);
    expect(array_sum($revision->lines->pluck('amount_minor')->all()))->toBe($total)->and(array_column($revision->review_reasons, 'code'))->toContain('demonstration_only', 'unverified_vehicle_capacity');
    $snap = $revision->pricing_snapshot;
    $replay = app(PricingEngine::class)->calculate('ZAR', $snap['rules'], $snap['metrics'], $type, $snap['surcharge_basis']);
    expect($replay['total_minor'])->toBe($total);
})->with([
    ['demo_small_delivery_van', 10, 30, 0, 0, false, 40000],
    ['demo_light_box_truck', 25, 160, 1, 1, false, 157000],
    ['demo_medium_box_truck', 40, 30, 0, 0, false, 157000],
    ['demo_light_box_truck', 20, 30, 0, 0, true, 95450],
]);
it('calculates independent percentage surcharges before fixed surcharges and does not bill prospective waiting', function (): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $input = quoteInput($service, ['preferred_date' => now()->addWeek()->next('Saturday')->toDateString(), 'preferred_time' => '19:00']);
    $input['details']['pickup_stairs'] = 1;
    $input['details']['waiting_minutes'] = 46;
    $quote = demoSimulation($service, $book, ['route_distance_km' => '20'], $input);
    expect($quote->currentRevision->total_minor)->toBe(122050);
    expect($quote->currentRevision->lines->firstWhere('code', 'waiting'))->toBeNull();
    $agreed = demoSimulation($service, $book, ['route_distance_km' => '20', 'waiting_confirmed' => true], $input);
    expect($agreed->currentRevision->lines->firstWhere('code', 'waiting')->amount_minor)->toBe(20000)->and($agreed->currentRevision->total_minor)->toBe(142050);
});
it('rounds labour up to quarters and charges only requested additional movers', function (): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $quote = demoSimulation($service, $book, ['route_duration_minutes' => '161', 'additional_movers' => 2]);
    expect($quote->currentRevision->pricing_snapshot['metrics']['estimated_job_hours'])->toBe('3.250000')->and($quote->currentRevision->lines->firstWhere('code', 'additional_movers')->amount_minor)->toBe(97500)->and($quote->currentRevision->lines->firstWhere('code', 'driver_extra')->amount_minor)->toBe(15000);
});
it('requires review rather than a guaranteed price for excess payload or missing class rates', function (bool $missing): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $input = quoteInput($service);
    if ($missing) {
        PricingRule::where('price_book_id', $book->id)->where('vehicle_type_key', 'demo_large_moving_truck')->delete();
    } else {
        $input['inventory'][0]['weight_kg'] = '9000';
    }
    $quote = demoSimulation($service, $book, ['vehicle_type_key' => 'demo_large_moving_truck'], $input);
    expect($quote->currentRevision->total_minor)->toBeNull()->and($quote->status)->toBe('requires_review')->and($quote->currentRevision->approval)->toBe('pending');
    expect(array_column($quote->currentRevision->review_reasons, 'code'))->toContain('demonstration_only');
})->with([false, true]);
it('cannot publish, submit, approve or accept demonstration simulations', function (): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $quote = demoSimulation($service, $book);
    expect(fn () => app(ManagePriceBook::class)->publish($book, $owner->id))->toThrow(ValidationException::class);
    expect(fn () => app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version))->toThrow(ValidationException::class);
    expect(fn () => app(ReviewQuote::class)->execute($quote, ['decision' => 'approve'], $owner->id))->toThrow(ValidationException::class);
    expect(fn () => app(AcceptQuote::class)->execute($quote, $quote->currentRevision->public_id))->toThrow(ValidationException::class);
    expect($quote->fresh()->customer_id)->toBeNull()->and($quote->fresh()->submitted_at)->toBeNull();
});
it('keeps existing calculation history when demo rates change', function (): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $quote = demoSimulation($service, $book);
    $first = $quote->currentRevision;
    $snapshot = $first->getAttributes();
    PricingRule::where('price_book_id', $book->id)->where('code', 'demo_light_box_truck_base')->update(['rate_micros' => 600000000]);
    $quote = app(CalculateQuote::class)->execute($quote, $quote->lock_version);
    expect($quote->currentRevision->total_minor)->toBe(95000)->and($first->fresh()->getAttributes())->toBe($snapshot)->and($quote->currentRevision->number)->toBe(2);
});
it('restricts simulation routes to tenant administrators and excludes signed public access', function (): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $base = '/c/'.$tenant->slug.'/price-books/'.$book->public_id.'/simulate';
    $this->get($base)->assertRedirect('/login');
    $this->actingAs($owner)->get($base)->assertOk();
    app(TenantContext::class)->set($tenant);
    $quote = demoSimulation($service, $book);
    $this->get(URL::temporarySignedRoute('quote.guest.view', now()->addHour(), ['company' => $tenant->slug, 'quote' => $quote->public_id]))->assertNotFound();
    $viewer = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $viewer->id, 'role' => Role::Viewer]);
    $this->actingAs($viewer)->get($base)->assertForbidden();
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
    Membership::create(['tenant_id' => $other->id, 'user_id' => $owner->id, 'role' => Role::Owner]);
    app(TenantContext::class)->set($other);
    expect(PriceBook::whereKey($book->id)->exists())->toBeFalse()->and(Quote::whereKey($quote->id)->exists())->toBeFalse();
    $this->actingAs($owner)->get('/c/other/price-books/'.$book->public_id.'/simulate')->assertNotFound();
});
it('rejects production demo seeding before changing any data', function (): void {
    demoTariffFixture();
    $count = DB::table('price_books')->count();
    app()->detectEnvironment(fn () => 'production');
    expect(fn () => app(DemonstrationPriceBookSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(DB::table('price_books')->count())->toBe($count);
});
it('screens the other seeded classes without treating catalogue vehicles as owned resources', function (string $key): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $count = Vehicle::count();
    $quote = demoSimulation($service, $book, ['vehicle_type_key' => $key]);
    expect($quote->currentRevision->total_minor)->toBeGreaterThan(0)->and($quote->currentRevision->pricing_snapshot['screened_vehicle_type'])->toBe($key)->and(Vehicle::count())->toBe($count);
})->with(['demo_large_panel_van', 'demo_one_ton_canopy_pickup', 'demo_large_moving_truck']);
it('reviews unknown weight and oversized items using the existing suitability screen', function (bool $oversized): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $input = quoteInput($service);
    if ($oversized) {
        $input['inventory'][0]['length_cm'] = '1000';
        $input['inventory'][0]['rotation_allowed'] = false;
    } else {
        $input['inventory'][0]['weight_kg'] = null;
        $input['inventory'][0]['weight_certainty'] = 'unknown';
    }
    $quote = demoSimulation($service, $book, [], $input);
    expect($quote->currentRevision->approval)->toBe('pending')->and($quote->status)->toBe('requires_review');
    if ($oversized) {
        expect($quote->currentRevision->total_minor)->toBeNull();
    }
})->with([false, true]);
it('enforces demonstration publication and simulation identity restrictions in PostgreSQL', function (string $mutation): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $quote = demoSimulation($service, $book);
    expect(fn () => DB::transaction(function () use ($mutation, $quote, $book): void {
        match ($mutation) {
            'publish' => DB::table('price_books')->where('id', $book->id)->update(['status' => 'published']),
            'convert' => DB::table('price_books')->where('id', $book->id)->update(['is_demonstration' => false]),
            'issue' => DB::table('quotes')->where('id', $quote->id)->update(['submitted_at' => now()]),
            'detach' => DB::table('quotes')->where('id', $quote->id)->update(['simulation_price_book_id' => null]),
        };
    }))->toThrow(QueryException::class);
})->with(['publish', 'convert', 'issue', 'detach']);
it('retains every Light Box Truck component when revised and an acceptance attempt is rejected', function (): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $input = quoteInput($service, ['preferred_date' => now()->addWeek()->next('Monday')->toDateString()]);
    $input['details']['pickup_stairs'] = 1;
    $quote = demoSimulation($service, $book, ['additional_movers' => 1, 'route_duration_minutes' => '160'], $input);
    $first = $quote->currentRevision;
    $expected = ['demo_light_box_truck_base' => 55000, 'demo_light_box_truck_distance' => 35000, 'driver_extra' => 12000, 'additional_movers' => 45000, 'stairs' => 10000, 'demo_light_box_truck_minimum' => 0, 'demo_tax' => 0];
    expect($first->lines->pluck('amount_minor', 'code')->all())->toEqual($expected)->and($first->total_minor)->toBe(157000);
    $snapshot = $first->getAttributes();
    $lines = $first->lines->map->getAttributes()->all();
    PricingRule::where('price_book_id', $book->id)->where('code', 'demo_light_box_truck_base')->update(['rate_micros' => 600000000]);
    $quote = app(CalculateQuote::class)->execute($quote, $quote->lock_version);
    expect($quote->currentRevision->total_minor)->toBe(162000)->and($quote->currentRevision->number)->toBe(2);
    expect($first->fresh()->getAttributes())->toBe($snapshot)->and($first->fresh()->lines->map->getAttributes()->all())->toBe($lines);
    $latest = $quote->currentRevision->getAttributes();
    expect(fn () => app(AcceptQuote::class)->execute($quote, $quote->currentRevision->public_id))->toThrow(ValidationException::class);
    expect($quote->fresh()->accepted_at)->toBeNull()->and($quote->currentRevision->fresh()->getAttributes())->toBe($latest)->and($first->fresh()->total_minor)->toBe(157000);
});
it('rejects a load above the Light Box Truck payload with explicit technical reasons and no automatic price', function (): void {
    [$tenant,$owner,$service,$book] = demoTariffFixture();
    $input = quoteInput($service);
    $input['inventory'][0]['quantity'] = 1;
    $input['inventory'][0]['weight_kg'] = '2100';
    $input['inventory'][0]['weight_certainty'] = 'known';
    $quote = demoSimulation($service, $book, [], $input);
    $revision = $quote->currentRevision;
    expect($revision->total_minor)->toBeNull()->and($revision->approval)->toBe('pending')->and($quote->status)->toBe('requires_review')->and($revision->lines)->toHaveCount(0);
    expect($revision->suitability[0]['vehicle_type_key'])->toBe('demo_light_box_truck')->and($revision->suitability[0]['technically_fits'])->toBeFalse();
    expect(array_column($revision->suitability[0]['reasons'], 'code'))->toContain('capacity_exceeded');
    expect(array_column($revision->review_reasons, 'code'))->toContain('vehicle_review', 'pricing_incomplete');
});
it('preserves an explicitly reviewed itemised R1570 commercial offer through acceptance', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    $original = $quote->currentRevision;
    $originalSnapshot = $original->getAttributes();
    $quote = app(ReviewQuote::class)->execute($quote, [
        'lock_version' => $quote->lock_version, 'decision' => 'revise', 'reason' => 'Test-only explicit operator review of the itemised Light Box Truck scope.',
        'currency' => 'ZAR', 'tax_basis_points' => 0, 'expires_at' => now()->addDay()->toIso8601String(), 'terms' => 'Test-only reviewed transport scope. Acceptance is not a confirmed booking.', 'exclusions' => '',
        'lines' => [
            ['description' => 'Light Box Truck base', 'amount_minor' => 55000],
            ['description' => '25 km at R14', 'amount_minor' => 35000],
            ['description' => 'One additional mover for three hours', 'amount_minor' => 45000],
            ['description' => 'One extra driver hour', 'amount_minor' => 12000],
            ['description' => 'One stair floor', 'amount_minor' => 10000],
        ],
    ], $owner->id);
    $issued = $quote->currentRevision;
    $snapshot = $issued->getAttributes();
    $lines = $issued->lines->map->getAttributes()->all();
    expect($issued->total_minor)->toBe(157000)->and($issued->approval)->toBe('approved')->and($quote->simulation_price_book_id)->toBeNull();
    $quote = app(AcceptQuote::class)->execute($quote, $issued->public_id);
    expect($quote->status)->toBe('accepted')->and($quote->currentRevision->getAttributes())->toBe($snapshot)->and($quote->currentRevision->lines->map->getAttributes()->all())->toBe($lines);
    expect($original->fresh()->getAttributes())->toBe($originalSnapshot)->and($quote->accepted_terms_hash)->toBe($issued->terms_snapshot['hash']);
});
