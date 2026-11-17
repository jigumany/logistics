<?php

use App\Domain\Fleet\Vehicle;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Inventory\ResolveInventory;
use App\Domain\Pricing\CommercialConfiguration;
use App\Domain\Pricing\PriceBook;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\QuotationReadiness;
use App\Domain\Pricing\QuotePricingMetrics;
use App\Domain\Quotes\AcceptQuote;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\QuotationPolicy;
use App\Domain\Quotes\QuotationTerms;
use App\Domain\Quotes\QuoteLifecycle;
use App\Domain\Quotes\QuoteStatus;
use App\Domain\Quotes\ReviewQuote;
use App\Domain\Quotes\SubmitQuote;
use App\Domain\Quotes\WriteQuoteRevision;
use App\Http\Resources\QuotationView;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('has explicit readiness gates and does not mistake a configured key for working routing', function (): void {
    [$tenant, $user, $service] = quoteFixture();
    $readiness = app(QuotationReadiness::class)->evaluate($service);
    expect($readiness['state'])->toBe('ready_for_internal_testing')->and($readiness['automatic_ready'])->toBeFalse();
    expect(app(QuotationReadiness::class)->evaluate($service, route: ['status' => 'available'])['automatic_ready'])->toBeTrue();
    Vehicle::query()->update(['capacity_verified' => false, 'capacity_verified_at' => null]);
    expect(app(QuotationReadiness::class)->evaluate($service, route: ['status' => 'available'])['state'])->toBe('configuration_incomplete');
});
it('preserves exact issued terms through policy changes submission and acceptance', function (): void {
    [$tenant, $user, $service] = quoteFixture();
    $quote = calculatedQuote($service);
    $snapshot = $quote->currentRevision->terms_snapshot;
    QuotationPolicy::query()->update(['terms' => 'Changed later policy.', 'terms_version' => 'later']);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    expect($quote->currentRevision->terms_snapshot)->toBe($snapshot);
    $quote = app(AcceptQuote::class)->execute($quote, $quote->currentRevision->public_id);
    expect($quote->accepted_terms_hash)->toBe($snapshot['hash'])->and($quote->currentRevision->terms)->not->toContain('Changed later policy.');
});
it('never includes draft insurance statements in customer terms', function (): void {
    quoteFixture();
    $policy = QuotationPolicy::firstOrFail();
    $policy->commercial_terms = [...$policy->commercial_terms, 'insurance_status' => 'draft', 'insurance_information' => 'UNVERIFIED_INSURANCE_PROMISE'];
    $policy->save();
    expect(app(QuotationTerms::class)->snapshot($policy)['terms'])->not->toContain('UNVERIFIED_INSURANCE_PROMISE')->and(app(QuotationTerms::class)->reviewed($policy))->toBeFalse();
});
it('keeps internal commercial costs and margins out of guest views and public revisions', function (): void {
    [$tenant, $user, $service] = quoteFixture();
    $quote = calculatedQuote($service);
    $view = app(QuotationView::class);
    $public = json_encode($view->make($quote));
    expect($public)->not->toContain('internal_costs', 'margin_bps', 'fuel_rate_micros', 'override_roles', 'configuration', 'readiness');
    expect($view->make($quote, true)['revision']['pricing_snapshot']['internal_costs']['status'])->toBe('estimated');
});
it('forces manual review for an unverified selected vehicle even when another vehicle has evidence', function (): void {
    [$tenant, $user, $service] = quoteFixture();
    Vehicle::query()->update(['capacity_verified' => false, 'capacity_verified_at' => null]);
    $quote = calculatedQuote($service);
    expect($quote->status)->toBe('requires_review')->and(array_column($quote->currentRevision->review_reasons, 'code'))->toContain('unverified_vehicle_capacity');
});
it('resets verification when raw capacity measurements change', function (): void {
    quoteFixture();
    $vehicle = Vehicle::firstOrFail();
    DB::table('vehicles')->where('id', $vehicle->id)->update(['payload_kg' => 999]);
    expect($vehicle->fresh()->capacity_verified)->toBeFalse()->and($vehicle->fresh()->capacity_verified_at)->toBeNull();
});
it('uses included distance and minimum labour without charging loading twice', function (): void {
    [$tenant, $user, $service] = quoteFixture();
    $book = PriceBook::firstOrFail();
    $config = $book->configuration;
    $config['commercial'] = [...$config['commercial'], 'included_km' => 10, 'minimum_chargeable_km' => 5, 'minimum_labour_hours' => 2];
    $book->configuration = $config;
    $input = quoteInput($service);
    $inventory = app(ResolveInventory::class)->execute($input['inventory']);
    $metrics = app(QuotePricingMetrics::class)->build($input, $inventory, ['customer_metres' => 12000, 'duration_seconds' => 1800, 'tolls_minor' => 0], $book, 'Africa/Johannesburg');
    expect($metrics['chargeable_km'])->toBe('5')->and($metrics['driver_hours'])->toBe('2')->and($metrics['mover_hours'])->toBe('2')->and($metrics['labour_hours'])->toBe('4');
});
it('does not grant an administrator price override privileges excluded by published policy', function (): void {
    [$tenant, $owner, $service] = quoteFixture();
    $quote = calculatedQuote($service);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    $admin = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role' => Role::Admin]);
    app(ReviewQuote::class)->execute($quote, ['lock_version' => $quote->lock_version, 'decision' => 'revise', 'reason' => 'Requested rate override.', 'currency' => 'ZAR', 'lines' => [['description' => 'Manual price', 'amount_minor' => 10000]], 'tax_basis_points' => 0, 'expires_at' => now()->addDay()->toIso8601String(), 'terms' => 'Reviewed scope.', 'exclusions' => ''], $admin->id);
})->throws(AuthorizationException::class);

it('rejects overlapping labour and inconsistent tax settings', function (array $changes, array $rules): void {
    (new CommercialConfiguration)->validate(commercialFixture($changes), $rules);
})->with([
    [[], [['metric' => 'labour_hours', 'stage' => 'charge'], ['metric' => 'loading_hours', 'stage' => 'charge']]],
    [['labour_model' => 'separate'], [['metric' => 'labour_hours', 'stage' => 'charge']]],
    [['tax_basis_points' => 1500], []],
    [[], [['metric' => 'base', 'stage' => 'charge'], ['metric' => 'base', 'stage' => 'surcharge']]],
])->throws(ValidationException::class);

it('flags below-minimum margins without silently increasing the quoted price', function (): void {
    [$tenant, $user, $service] = quoteFixture(true, ['fixed_cost_minor' => 50000]);
    $quote = calculatedQuote($service);
    expect($quote->status)->toBe('requires_review')->and($quote->currentRevision->total_minor)->toBe(20000)->and(array_column($quote->currentRevision->review_reasons, 'code'))->toContain('below_minimum_margin');
    expect($quote->currentRevision->pricing_snapshot['internal_costs']['below_minimum'])->toBeTrue();
});

it('reproduces itemised totals from the stored calculation snapshot', function (): void {
    [$tenant, $user, $service] = quoteFixture();
    $quote = calculatedQuote($service);
    $snapshot = $quote->currentRevision->pricing_snapshot;
    $replayed = app(PricingEngine::class)->calculate($quote->currentRevision->currency, $snapshot['rules'], $snapshot['metrics'], $snapshot['screened_vehicle_type']);
    expect($replayed['total_minor'])->toBe($quote->currentRevision->total_minor)->and($snapshot['engine_version'])->toBe('2')->and($snapshot['calculation_id'])->not->toBeEmpty();
    expect(array_sum(array_column($replayed['lines'], 'amount_minor')))->toBe($replayed['total_minor']);
});
it('hides low-margin reasons behind a general customer review message', function (): void {
    [$tenant, $user, $service] = quoteFixture(true, ['fixed_cost_minor' => 50000]);
    $quote = calculatedQuote($service);
    $public = json_encode(app(QuotationView::class)->make($quote));
    expect($public)->not->toContain('below_minimum_margin', 'cost_estimate_incomplete', '50000', 'minimum_margin_bps');
});

it('requires legacy unissued automatic estimates to recalculate without rewriting their history', function (): void {
    [$tenant, $user, $service] = quoteFixture();
    $quote = calculatedQuote($service);
    app(QuoteLifecycle::class)->transition($quote, QuoteStatus::Calculating);
    $legacy = app(WriteQuoteRevision::class)->copy($quote, $quote->currentRevision, [], ['pricing_snapshot' => ['engine_version' => '1']]);
    app(QuoteLifecycle::class)->transition($quote, QuoteStatus::Quoted);
    $quote = $quote->fresh(['currentRevision']);
    expect(fn () => app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version))->toThrow(ValidationException::class);
    $quote = app(CalculateQuote::class)->execute($quote, $quote->lock_version);
    expect($quote->currentRevision->public_id)->not->toBe($legacy->public_id)->and($quote->currentRevision->pricing_snapshot['engine_version'])->toBe('2');
    expect($legacy->fresh()->pricing_snapshot)->toHaveKey('engine_version', '1');
});
