<?php

use App\Domain\Availability\BookingQuoteScope;
use App\Domain\Bookings\BookingConflict;
use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Quotes\AcceptQuote;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Quotes\GuestQuoteLinks;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\ReviewQuote;
use App\Domain\Quotes\SubmitQuote;
use App\Domain\Quotes\VerifyAcceptedQuoteInventory;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\AuditEntry;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

function quoteWithUnverifiedWeight(TenantService $service, User $owner): Quote
{
    $input = quoteInput($service);
    $input['inventory'][0]['weight_kg'] = null;
    $quote = app(CreateQuoteDraft::class)->execute($input, (string) Str::uuid());
    $quote = app(CalculateQuote::class)->execute($quote, $quote->lock_version);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    $quote = app(ReviewQuote::class)->execute($quote, [
        'lock_version' => $quote->lock_version,
        'decision' => 'approve',
        'reason' => 'Operations reviewed the service and agreed the offer.',
        'currency' => 'ZAR',
        'lines' => [['description' => 'Reviewed moving service', 'amount_minor' => 20000]],
        'tax_basis_points' => 0,
        'expires_at' => now()->addDays(3)->toIso8601String(),
        'terms' => 'Reviewed service offer.',
        'exclusions' => '',
    ], $owner->id);

    return app(AcceptQuote::class)->execute($quote, $quote->currentRevision->public_id);
}

it('keeps booking blocked until operations verifies every accepted quote item', function (): void {
    [$tenant, $owner, $service] = quoteFixture();
    $quote = quoteWithUnverifiedWeight($service, $owner);
    $inventoryItem = $quote->currentRevision->inventory->firstOrFail();

    expect(fn () => app(BookingQuoteScope::class)->resolve($quote))
        ->toThrow(BookingConflict::class);

    $payload = [
        'notes' => 'Weight confirmed with the customer by phone.',
        'vehicle_type_key' => 'sample_van',
        'crew_size' => 2,
        'items' => [[
            'public_id' => $inventoryItem->public_id,
            'length_cm' => '100',
            'width_cm' => '50',
            'height_cm' => '40',
            'weight_kg' => '12.5',
        ]],
    ];

    $this->actingAs($owner)
        ->post('/c/'.$tenant->slug.'/quotes/'.$quote->public_id.'/inventory-verification', $payload)
        ->assertRedirect();

    app(TenantContext::class)->set($tenant);
    $scope = app(BookingQuoteScope::class)->resolve($quote->fresh(['currentRevision.inventory']));
    $this->actingAs($owner)
        ->get('/c/'.$tenant->slug.'/quotes/'.$quote->public_id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('company/quotes/Show')
            ->where('quote.inventory_verification.notes', 'Weight confirmed with the customer by phone.')
            ->has('quote.inventory_verification.items', 1));
    app(TenantContext::class)->set($tenant);
    expect($scope['inventory']['unknown_weight_quantity'])->toBe(0)
        ->and($scope['vehicle_type_key'])->toBe('sample_van')
        ->and($scope['crew_size'])->toBe(2)
        ->and($scope['movers'])->toBe(1)
        ->and($scope['inventory']['total_weight_kg'])->toBe('25.000000')
        ->and($scope['inventory']['measurement_sources'])->toHaveKey('measured')
        ->and($quote->currentRevision->inventory->first()->weight_kg)->toBeNull()
        ->and(AuditEntry::where('action', 'quote.inventory.verify')->count())->toBe(1);
});

it('rejects verification that changes an accepted quote measurement', function (): void {
    [$tenant, $owner, $service] = quoteFixture();
    $quote = quoteWithUnverifiedWeight($service, $owner);
    $item = $quote->currentRevision->inventory->firstOrFail();

    expect(fn () => app(VerifyAcceptedQuoteInventory::class)->execute($quote, [
        'notes' => 'Measured on site.',
        'vehicle_type_key' => 'sample_van',
        'crew_size' => 2,
        'items' => [[
            'public_id' => $item->public_id,
            'length_cm' => '101',
            'width_cm' => '50',
            'height_cm' => '40',
            'weight_kg' => '12.5',
        ]],
    ], $owner->id))->toThrow(ValidationException::class);

    expect(AuditEntry::where('action', 'quote.inventory.verify')->count())->toBe(0);
});

it('restricts accepted quote inventory verification to company managers', function (): void {
    [$tenant, $owner, $service] = quoteFixture();
    $quote = quoteWithUnverifiedWeight($service, $owner);
    $viewer = User::factory()->create();
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $viewer->id, 'role' => Role::Viewer]);
    $item = $quote->currentRevision->inventory->firstOrFail();

    $this->actingAs($viewer)
        ->post('/c/'.$tenant->slug.'/quotes/'.$quote->public_id.'/inventory-verification', [
            'notes' => 'Measured on site.',
            'vehicle_type_key' => 'sample_van',
            'crew_size' => 2,
            'items' => [[
                'public_id' => $item->public_id,
                'length_cm' => '100',
                'width_cm' => '50',
                'height_cm' => '40',
                'weight_kg' => '12.5',
            ]],
        ])->assertForbidden();
});

it('does not expose internal verification notes on the customer quote page', function (): void {
    [$tenant, $owner, $service] = quoteFixture();
    $quote = quoteWithUnverifiedWeight($service, $owner);
    $item = $quote->currentRevision->inventory->firstOrFail();
    app(VerifyAcceptedQuoteInventory::class)->execute($quote, [
        'notes' => 'Customer confirmed the measured weight.',
        'vehicle_type_key' => 'sample_van',
        'crew_size' => 2,
        'items' => [[
            'public_id' => $item->public_id,
            'length_cm' => '100',
            'width_cm' => '50',
            'height_cm' => '40',
            'weight_kg' => '12.5',
        ]],
    ], $owner->id);

    $this->getJson(app(GuestQuoteLinks::class)->forQuote($quote)['view'])
        ->assertOk()
        ->assertJsonMissingPath('inventory_verification')
        ->assertDontSee('Customer confirmed the measured weight.');
});

it('requires an assessed active vehicle class for operations verification', function (): void {
    [$tenant, $owner, $service] = quoteFixture();
    $quote = quoteWithUnverifiedWeight($service, $owner);
    $item = $quote->currentRevision->inventory->firstOrFail();
    $measurements = [[
        'public_id' => $item->public_id,
        'length_cm' => '100',
        'width_cm' => '50',
        'height_cm' => '40',
        'weight_kg' => '12.5',
    ]];

    expect(fn () => app(VerifyAcceptedQuoteInventory::class)->execute($quote, [
        'notes' => 'Measurements confirmed on site.',
        'vehicle_type_key' => 'sample_van',
        'items' => $measurements,
    ], $owner->id))->toThrow(ValidationException::class);

    expect(fn () => app(VerifyAcceptedQuoteInventory::class)->execute($quote, [
        'notes' => 'Measurements confirmed on site.',
        'vehicle_type_key' => 'inactive-or-unknown',
        'crew_size' => 2,
        'items' => $measurements,
    ], $owner->id))->toThrow(ValidationException::class);

    expect(AuditEntry::where('action', 'quote.inventory.verify')->count())->toBe(0);
});

it('adds required vehicle class and crew when measurements were verified earlier', function (): void {
    [$tenant, $owner, $service] = quoteFixture();
    $quote = quoteWithUnverifiedWeight($service, $owner);
    $revision = $quote->currentRevision;
    $item = $revision->inventory->firstOrFail();
    $crewSize = (int) ($revision->pricing_snapshot['configuration']['crew_size'] ?? 2);
    $measurements = [
        'public_id' => $item->public_id,
        'name' => $item->name,
        'length_cm' => '100.000000',
        'width_cm' => '50.000000',
        'height_cm' => '40.000000',
        'weight_kg' => '12.500000',
    ];
    $legacyEntry = new AuditEntry([
        'actor_id' => $owner->id,
        'action' => 'quote.inventory.verify',
        'subject_public_id' => $quote->public_id,
    ]);
    $legacyEntry->setAttribute('details', [
        'revision_public_id' => $revision->public_id,
        'notes' => 'Inventory measurements confirmed before vehicle class was required.',
        'items' => [$measurements],
    ]);
    $legacyEntry->save();

    app(VerifyAcceptedQuoteInventory::class)->execute($quote, [
        'notes' => 'Inventory and required class confirmed by operations.',
        'vehicle_type_key' => 'sample_van',
        'crew_size' => $crewSize,
        'items' => [[
            'public_id' => $item->public_id,
            'length_cm' => '100',
            'width_cm' => '50',
            'height_cm' => '40',
            'weight_kg' => '12.5',
        ]],
    ], $owner->id);

    expect(AuditEntry::where('action', 'quote.inventory.verify')->count())->toBe(2)
        ->and(app(BookingQuoteScope::class)->resolve($quote)['vehicle_type_key'])->toBe('sample_van')
        ->and(app(BookingQuoteScope::class)->resolve($quote)['crew_size'])->toBe($crewSize);
});
