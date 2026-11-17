<?php

use App\Domain\Inventory\CatalogueItem;
use App\Domain\Inventory\QuoteInventory;
use App\Domain\Pricing\PriceBook;
use App\Domain\Quotes\AcceptQuote;
use App\Domain\Quotes\SubmitQuote;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL quotation constraints.');
    }
});
it('protects accepted quotes against direct SQL updates', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $quote = app(SubmitQuote::class)->execute($quote, quoteContact(), $quote->lock_version);
    app(AcceptQuote::class)->execute($quote, $quote->currentRevision->public_id);
    expect(fn () => DB::transaction(fn () => DB::table('quotes')->where('id', $quote->id)->update(['status' => 'quoted'])))->toThrow(QueryException::class);
});
it('protects sealed revisions against direct price changes', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    expect(fn () => DB::transaction(fn () => DB::table('quote_revisions')->where('id', $quote->current_revision_id)->update(['total_minor' => 1])))->toThrow(QueryException::class);
});
it('prevents rewriting issued line items', function (string $operation): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $line = DB::table('quote_line_items')->where('revision_id', $quote->current_revision_id)->first();
    expect(fn () => DB::transaction(function () use ($line, $operation): void {
        if ($operation === 'delete') {
            DB::table('quote_line_items')->where('id', $line->id)->delete();
        } else {
            DB::table('quote_line_items')->where('id', $line->id)->update(['amount_minor' => 1]);
        }
    }))->toThrow(QueryException::class);
})->with(['update', 'delete']);
it('prevents moving an issued line into an unsealed revision', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $revision = $quote->currentRevision;
    $target = $revision->replicate(['public_id', 'sealed_at']);
    $target->number = 2;
    $target->save();
    expect(fn () => DB::transaction(fn () => DB::table('quote_line_items')->where('revision_id', $revision->id)->update(['revision_id' => $target->id])))->toThrow(QueryException::class);
});
it('enforces published tariff exclusion periods at the database boundary', function (): void {
    quoteFixture();
    $book = PriceBook::firstOrFail();
    $other = $book->replicate(['public_id']);
    $other->version = 2;
    expect(fn () => DB::transaction(fn () => $other->save()))->toThrow(QueryException::class);
});
it('rejects an illegal status transition independently of controllers', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    expect(fn () => DB::transaction(fn () => DB::table('quotes')->where('id', $quote->id)->update(['status' => 'draft'])))->toThrow(QueryException::class);
});

it('rejects another tenants catalogue reference even before a revision is sealed', function (): void {
    [$tenant,$owner,$service] = quoteFixture();
    $quote = calculatedQuote($service);
    $other = Tenant::create(['name' => 'Other catalogue tenant', 'slug' => 'other-catalogue']);
    app(TenantContext::class)->set($other);
    $definition = CatalogueItem::create(['name' => 'Other private item', 'category' => 'boxes', 'handling' => 'standard', 'active' => true]);
    app(TenantContext::class)->set($tenant);
    $revision = $quote->currentRevision->replicate(['public_id', 'sealed_at']);
    $revision->number = 2;
    $revision->save();
    $row = QuoteInventory::where('revision_id', $quote->current_revision_id)->firstOrFail();
    $data = array_diff_key($row->toArray(), array_flip(['public_id', 'created_at', 'updated_at']));
    expect(fn () => DB::transaction(fn () => QuoteInventory::create([...$data, 'revision_id' => $revision->id, 'catalogue_public_id' => $definition->public_id])))->toThrow(QueryException::class, 'Inventory catalogue ownership mismatch');
});
