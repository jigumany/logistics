<?php

use App\Domain\Geography\CoverageLookup;
use App\Domain\Quotes\AcceptedQuotation;
use App\Domain\Quotes\AcceptQuote;
use App\Domain\Quotes\CalculateQuote;
use App\Domain\Quotes\CloseQuote;
use App\Domain\Quotes\CreateQuoteDraft;
use App\Domain\Quotes\QuotationPolicy;
use App\Domain\Quotes\ReviewQuote;
use App\Domain\Quotes\SubmitQuote;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('does not automatically approve customer-estimated weights', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $input = quoteInput($service);
    $input['inventory'][0]['weight_certainty'] = 'estimated';
    $quote = app(CreateQuoteDraft::class)->execute($input, (string) Str::uuid());
    $quote = app(CalculateQuote::class)->execute($quote, 0);
    expect($quote->status)->toBe('requires_review')->and(array_column($quote->currentRevision->review_reasons, 'code'))->toContain('uncertain_weight');
});
it('supports cross-zone routes while honoring an explicit per-service review policy', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $counter = 0;
    app()->instance(CoverageLookup::class, Mockery::mock(CoverageLookup::class)->shouldReceive('check')->andReturnUsing(function () use (&$counter) {
        return ['covered' => true, 'zone' => ['public_id' => 'zone-'.($counter++ % 2), 'manual_review' => false, 'restrictions' => null]];
    })->getMock());
    $quote = calculatedQuote($service);
    expect($quote->status)->toBe('quoted')->and($quote->currentRevision->coverage['cross_zone'])->toBeTrue();
    QuotationPolicy::where('service_id', $service->id)->update(['allow_cross_zone' => false]);
    $other = calculatedQuote($service);
    expect($other->status)->toBe('requires_review')->and(array_column($other->currentRevision->review_reasons, 'code'))->toContain('coverage_review');
});
it('enforces unsupported-area policies even for manual price approval', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    QuotationPolicy::where('service_id', $service->id)->update(['outside_zone' => 'reject']);
    app()->instance(CoverageLookup::class, Mockery::mock(CoverageLookup::class)->shouldReceive('check')->andReturn(['covered' => false, 'zone' => null])->getMock());
    $quote = calculatedQuote($service);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    expect(array_column($quote->currentRevision->review_reasons, 'code'))->toContain('outside_supported_area');
    expect(fn () => app(ReviewQuote::class)->execute($quote, ['decision' => 'approve', 'lock_version' => $quote->lock_version, 'reason' => 'Attempted unsupported service area override.', 'currency' => 'ZAR', 'lines' => [['description' => 'Transport', 'amount_minor' => 10000]], 'tax_basis_points' => 0, 'expires_at' => now()->addDay()->toIso8601String(), 'terms' => 'Terms', 'exclusions' => ''], $owner->id))->toThrow(ValidationException::class);
});
it('routes oversized multi-stop and difficult-access requests into review', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $input = quoteInput($service);
    array_splice($input['stops'], 1, 0, [$input['stops'][0]]);
    $input['inventory'][0]['oversized'] = true;
    $input['details']['parking_limited'] = true;
    $quote = app(CreateQuoteDraft::class)->execute($input, (string) Str::uuid());
    $quote = app(CalculateQuote::class)->execute($quote, 0);
    expect($quote->status)->toBe('requires_review')->and(array_column($quote->currentRevision->review_reasons, 'code'))->toContain('multiple_stops', 'difficult_access', 'special_handling');
});
it('does not turn an idempotency key into cross-session access', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $input = quoteInput($service);
    $key = (string) Str::uuid();
    app(CreateQuoteDraft::class)->execute($input, $key, hash('sha256', 'creator'));
    expect(fn () => app(CreateQuoteDraft::class)->execute($input, $key, hash('sha256', 'different browser')))->toThrow(ValidationException::class);
});
it('keeps decline and cancellation idempotent and cannot close an accepted quote', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $quote = app(CloseQuote::class)->execute($quote, 'decline', $quote->lock_version);
    expect(app(CloseQuote::class)->execute($quote, 'decline', 0)->status)->toBe('declined');
    $other = calculatedQuote($service);
    $other = app(SubmitQuote::class)->execute($other, quoteContact(), $other->lock_version);
    $other = app(AcceptQuote::class)->execute($other, $other->currentRevision->public_id);
    expect(fn () => app(CloseQuote::class)->execute($other, 'cancel', $other->lock_version))->toThrow(ValidationException::class);
});
it('provides a tenant-scoped accepted snapshot without creating a booking', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    app(AcceptQuote::class)->execute($quote, $quote->currentRevision->public_id);
    $snapshot = app(AcceptedQuotation::class)->snapshot($quote->public_id);
    expect($snapshot['availability_must_be_rechecked'])->toBeTrue()->and($snapshot['booking_confirmed'])->toBeFalse()->and($snapshot['total_minor'])->toBe(20000);
});
