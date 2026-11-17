<?php

use App\Domain\Notifications\DeliverQuoteNotice;
use App\Domain\Notifications\QuoteDelivery;
use App\Domain\Notifications\QuoteNotice;
use App\Domain\Quotes\GenerateQuotePdf;
use App\Domain\Quotes\QuotationPolicy;
use App\Domain\Quotes\QuoteDocument;
use App\Domain\Quotes\SubmitQuote;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('renders a private customer PDF with itemised financial snapshots and reuses it', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    Storage::fake();
    $quote = calculatedQuote($service);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    $job = new GenerateQuotePdf($tenant->id, $quote->currentRevision->public_id);
    $job->handle(app(TenantContext::class));
    app(TenantContext::class)->set($tenant);
    $document = QuoteDocument::where('revision_id', $quote->current_revision_id)->firstOrFail();
    expect(Storage::get($document->path))->toStartWith('%PDF-')->and(Storage::getVisibility($document->path))->toBe('private')->and($document->path)->toStartWith('tenants/'.$tenant->public_id.'/quotes/');
    $html = view('quotes.pdf', ['quote' => $quote, 'revision' => $quote->currentRevision->load('lines', 'stops', 'inventory'), 'settings' => (object) $quote->currentRevision->branding_snapshot, 'logo' => null])->render();
    expect($html)->toContain($quote->reference, 'Base transport', 'Customer journey distance', 'No booking', '200.00');
    $job->handle(app(TenantContext::class));
    app(TenantContext::class)->set($tenant);
    expect(QuoteDocument::count())->toBe(1);
});
it('uses the committed outbox before queueing PDFs and email notices', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    Queue::fake();
    $quote = calculatedQuote($service);
    app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    Queue::assertNothingPushed();
    $this->artisan('outbox:publish')->assertSuccessful();
    Queue::assertPushed(GenerateQuotePdf::class);
    Queue::assertPushed(DeliverQuoteNotice::class);
});
it('deduplicates a delivered notice and respects tenant notification settings', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    Mail::fake();
    $quote = calculatedQuote($service);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    QuotationPolicy::where('service_id', $service->id)->update(['notifications_enabled' => true]);
    $event = (string) Str::ulid();
    QuoteDelivery::create(['quote_id' => $quote->id, 'event_id' => $event, 'event_type' => 'quote.issued']);
    $job = new DeliverQuoteNotice($tenant->id, $event, $quote->currentRevision->public_id);
    $job->handle(app(TenantContext::class));
    $job->handle(app(TenantContext::class));
    Mail::assertSent(QuoteNotice::class, 1);
});
it('does not send transactional email without the configured preference', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    Mail::fake();
    $quote = calculatedQuote($service);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    $event = (string) Str::ulid();
    QuoteDelivery::create(['quote_id' => $quote->id, 'event_id' => $event, 'event_type' => 'quote.issued']);
    (new DeliverQuoteNotice($tenant->id, $event, $quote->currentRevision->public_id))->handle(app(TenantContext::class));
    Mail::assertNothingSent();
    app(TenantContext::class)->set($tenant);
    expect(QuoteDelivery::where('event_id',$event)->value('status'))->toBe('skipped');
});
