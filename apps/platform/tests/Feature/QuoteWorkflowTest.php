<?php

use App\Domain\Fleet\SaveVehicle;
use App\Domain\Fleet\Vehicle;
use App\Domain\Fleet\VehicleType;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Inventory\CatalogueItem;
use App\Domain\Pricing\ManagePriceBook;
use App\Domain\Pricing\PriceBook;
use App\Domain\Pricing\PricingRule;
use App\Domain\Quotes\AcceptQuote;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Quotes\GuestQuoteLinks;
use App\Domain\Quotes\QuotationPolicy;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuoteReviewDecision;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Quotes\ReviewQuote;
use App\Domain\Quotes\SubmitQuote;
use App\Domain\Routing\RoutingProvider;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\QuotationView;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('calculates submits and idempotently accepts a standard quote without reserving resources', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    expect($quote->status)->toBe('quoted')->and($quote->currentRevision->total_minor)->toBe(20000)->and($quote->currentRevision->approval)->toBe('automatic');
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    expect(QuoteRevision::where('quote_id', $quote->id)->count())->toBe(2);
    $accepted = app(AcceptQuote::class)->execute($quote, $quote->currentRevision->public_id);
    $again = app(AcceptQuote::class)->execute($accepted, $accepted->currentRevision->public_id);
    expect($again->status)->toBe('accepted')->and(DB::table('outbox_messages')->where('event_type', 'quote.accepted')->count())->toBe(1)->and(DB::table('resource_blocks')->count())->toBe(0)->and(DB::table('customers')->value('email_verified_at'))->toBeNull();
});
it('deduplicates draft creation and rejects reused keys with different inputs', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $key = (string) Str::uuid();
    $input = quoteInput($service);
    $first = app(CreateQuoteDraft::class)->execute($input, $key);
    expect(app(CreateQuoteDraft::class)->execute($input, $key)->id)->toBe($first->id);
    expect(fn () => app(CreateQuoteDraft::class)->execute([...$input, 'preferred_time' => '11:00'], $key))->toThrow(ValidationException::class);
});
it('does not recalculate unchanged inputs or mutate the immutable revision', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $revisionId = $quote->current_revision_id;
    expect(app(CalculateQuote::class)->execute($quote, 0)->current_revision_id)->toBe($revisionId)->and(QuoteRevision::where('quote_id', $quote->id)->count())->toBe(1);
});
it('routes missing price configuration into manual review', function (): void {
    [$tenant,$owner,$service] = quoteFixture(false);
    $quote = calculatedQuote($service);
    expect($quote->status)->toBe('requires_review')->and($quote->currentRevision->total_minor)->toBeNull()->and(array_column($quote->currentRevision->review_reasons, 'code'))->toContain('pricing_incomplete');
});
it('continues the request when routing is unavailable', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    app()->instance(RoutingProvider::class, Mockery::mock(RoutingProvider::class)->shouldReceive('calculate')->andReturn(['provider' => 'fake', 'status' => 'unavailable', 'failure_code' => 'provider_timeout', 'customer_metres' => null, 'depot_metres' => null, 'return_metres' => null, 'duration_seconds' => null, 'tolls_minor' => null, 'retain_until' => null])->getMock());
    $quote = calculatedQuote($service);
    expect($quote->status)->toBe('requires_review')->and(array_column($quote->currentRevision->review_reasons, 'code'))->toContain('route_unavailable', 'pricing_incomplete');
});
it('creates an approved manual revision with an audited reason and reconciled tax', function (): void {
    [$tenant,$owner,$service] = quoteFixture(false);
    $quote = calculatedQuote($service);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    $old = $quote->current_revision_id;
    $quote = app(ReviewQuote::class)->execute($quote, ['lock_version' => $quote->lock_version, 'decision' => 'approve', 'reason' => 'Reviewed the route and specialist handling.', 'currency' => 'ZAR', 'lines' => [['description' => 'Reviewed transport and loading', 'amount_minor' => 80000]], 'tax_basis_points' => 1500, 'expires_at' => now()->addDays(3)->toIso8601String(), 'terms' => 'Reviewed scope. No booking is confirmed.', 'exclusions' => 'No packing.'], $owner->id);
    expect($quote->status)->toBe('quoted')->and($quote->current_revision_id)->not->toBe($old)->and($quote->currentRevision->total_minor)->toBe(92000)->and($quote->currentRevision->approval)->toBe('approved');
    expect(DB::table('audit_entries')->where('action', 'quote.approve')->exists())->toBeTrue();
    expect(fn () => app(AcceptQuote::class)->execute($quote, QuoteRevision::findOrFail($old)->public_id))->toThrow(ValidationException::class);
});
it('rejects stale updates and requires a new revision for changed inputs', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $input = quoteInput($service, ['preferred_time' => '11:00']);
    expect(fn () => app(CalculateQuote::class)->execute($quote, 0, $input))->toThrow(ValidationException::class);
    $new = app(CalculateQuote::class)->execute($quote, $quote->lock_version, $input);
    expect($new->currentRevision->number)->toBe(2);
});
it('rejects expired acceptance and keeps accepted inputs immutable', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    $this->travel(4)->days();
    expect(fn () => app(AcceptQuote::class)->execute($quote, $quote->currentRevision->public_id))->toThrow(ValidationException::class);
    $this->travelBack();
    $quote = app(AcceptQuote::class)->execute($quote, $quote->currentRevision->public_id);
    expect(fn () => app(CalculateQuote::class)->execute($quote, $quote->lock_version, quoteInput($service)))->toThrow(ValidationException::class);
});
it('requires a valid signed capability and does not expose another tenants quote', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $links = app(GuestQuoteLinks::class)->forQuote($quote);
    $this->getJson('/q/quotation-company/requests/'.$quote->public_id)->assertForbidden();
    $this->getJson($links['view'])->assertOk()->assertJsonMissingPath('revision.suitability')->assertJsonMissingPath('revision.pricing_snapshot');
    $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
    app(TenantContext::class)->set($other);
    expect(Quote::where('public_id', $quote->public_id)->exists())->toBeFalse();
});
it('prevents viewers from overriding quote prices through HTTP', function (): void {
    [$tenant,$owner,$service] = quoteFixture(false);
    $quote = calculatedQuote($service);
    $viewer = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $viewer->id, 'role' => Role::Viewer]);
    $this->actingAs($viewer)->postJson('/c/quotation-company/quotes/'.$quote->public_id.'/review', ['decision' => 'approve'])->assertForbidden();
});

it('prices a suitable vehicle rather than issuing an automatic offer on a review-only candidate', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $restricted = Vehicle::firstOrFail();
    $restricted->operating_restrictions = 'Operator review required.';
    $restricted->save();
    $suitable = app(SaveVehicle::class)->execute(['name' => 'Suitable truck', 'registration_number' => 'QUOTE002', 'vehicle_type_id' => VehicleType::where('key', 'sample_medium_truck')->value('id'), 'status' => 'available', 'capacity_margin_basis_points' => 1000], $owner->id);
    $quote = calculatedQuote($service);
    expect($quote->status)->toBe('quoted')->and($quote->currentRevision->pricing_snapshot['screened_vehicle_public_id'])->toBe($suitable->public_id);
});
it('does not combine an unpriced suitable vehicle with a priced review-only vehicle', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $restricted = Vehicle::firstOrFail();
    $restricted->operating_restrictions = 'Operator review required.';
    $restricted->save();
    app(SaveVehicle::class)->execute(['name' => 'Unpriced suitable truck', 'registration_number' => 'QUOTE003', 'vehicle_type_id' => VehicleType::where('key', 'sample_medium_truck')->value('id'), 'status' => 'available', 'capacity_margin_basis_points' => 1000], $owner->id);
    $book = PriceBook::firstOrFail();
    $rules = PricingRule::where('price_book_id', $book->id)->get()->map(fn ($rule) => [...$rule->only('code', 'label', 'stage', 'kind', 'metric', 'rate_micros', 'position'), 'vehicle_type_key' => 'sample_van'])->all();
    app(ManagePriceBook::class)->retire($book, $owner->id);
    $new = app(ManagePriceBook::class)->save([...$book->only('name', 'currency', 'effective_from', 'effective_until', 'distance_basis', 'configuration'), 'service_public_id' => $service->public_id, 'rules' => $rules], $owner->id);
    app(ManagePriceBook::class)->publish($new, $owner->id);
    $quote = calculatedQuote($service);
    expect($quote->status)->toBe('requires_review')->and(array_column($quote->currentRevision->review_reasons, 'code'))->toContain('vehicle_review');
});
it('treats mixed catalogue and customer values according to both confidence policies', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    QuotationPolicy::where('service_id', $service->id)->update(['allow_customer_measurements' => false, 'allow_catalogue_estimates' => true]);
    $input = quoteInput($service);
    $item = CatalogueItem::where('name', 'Dining chair')->firstOrFail();
    $input['inventory'] = [['item_key' => '00000000-0000-4000-8000-000000000001', 'catalogue_public_id' => $item->public_id, 'quantity' => 1, 'weight_kg' => '6']];
    $quote = app(CreateQuoteDraft::class)->execute($input, (string) Str::uuid());
    $quote = app(CalculateQuote::class)->execute($quote, 0);
    expect($quote->status)->toBe('requires_review')->and($quote->currentRevision->inventory_summary['measurement_sources'])->toHaveKeys(['catalogue', 'customer']);
});

it('returns a usable initial version and signed operation links when a guest creates a draft', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $response = $this->postJson('/q/quotation-company/drafts', ['idempotency_key' => (string) Str::uuid(), 'input' => quoteInput($service), 'website' => '']);
    $response->assertCreated()->assertJsonPath('lock_version', 0)->assertJsonPath('status', 'draft');
    expect($response->json('links.calculate'))->toContain('/calculate?');
    $this->postJson($response->json('links.save'), ['lock_version' => 0, 'input' => quoteInput($service)])->assertOk()->assertJsonPath('lock_version', 0);
});

it('creates a pending revision when more information is requested and resolves prior review reasons on approval', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    $old = $quote->current_revision_id;
    $quote = app(ReviewQuote::class)->execute($quote, ['lock_version' => $quote->lock_version, 'decision' => 'request_information', 'reason' => 'Please confirm the pickup access.'], $owner->id);
    expect($quote->status)->toBe('requires_review')->and($quote->currentRevision->approval)->toBe('pending')->and($quote->current_revision_id)->not->toBe($old);
    $quote = app(ReviewQuote::class)->execute($quote, ['lock_version' => $quote->lock_version, 'decision' => 'approve', 'reason' => 'Pickup access and pricing confirmed.', 'currency' => 'USD', 'lines' => [['description' => 'Reviewed transport', 'amount_minor' => 10000]], 'tax_basis_points' => 0, 'expires_at' => now()->addDays(2)->toIso8601String(), 'terms' => 'Reviewed conditions.', 'exclusions' => ''], $owner->id);
    $view = app(QuotationView::class)->make($quote);
    expect($view['revision']['review_reasons'])->toBe([])->and($view['information_request'])->toBeNull();
    $decision = QuoteReviewDecision::where('decision', 'approve')->firstOrFail();
    expect($decision->adjustment['previous_currency'])->toBe('ZAR')->and($decision->adjustment['currency'])->toBe('USD');
});
