<?php

namespace App\Domain\Quotes;

use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class GenerateQuotePdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $tenantId, public string $revisionPublicId) {}

    public function handle(TenantContext $context): void
    {
        $context->set(Tenant::findOrFail($this->tenantId));
        try {
            Cache::lock('quote-pdf:'.$this->tenantId.':'.$this->revisionPublicId, 90)->block(10, function () use ($context): void {
                $revision = QuoteRevision::where('public_id', $this->revisionPublicId)->with(['lines', 'stops', 'inventory'])->firstOrFail();
                $quote = Quote::whereKey($revision->quote_id)->whereNotNull('submitted_at')->firstOrFail();
                $document = QuoteDocument::where('revision_id', $revision->id)->first();
                if ($document && Storage::exists($document->path)) {
                    return;
                }
                $bytes = app(QuotationDocumentRenderer::class)->render($quote, $revision);
                $path = 'tenants/'.$context->tenant()->public_id.'/quotes/'.$quote->public_id.'/revision-'.$revision->number.'.pdf';
                if (! Storage::put($path, $bytes, ['visibility' => 'private'])) {
                    throw new RuntimeException('Unable to store the quotation PDF privately.');
                }
                QuoteDocument::firstOrCreate(['revision_id' => $revision->id], ['path' => $path]);
            });
        } finally {
            $context->clear();
        }
    }
}
