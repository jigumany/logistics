<?php

namespace App\Domain\Notifications;

use App\Domain\Quotes\GuestQuoteLinks;
use App\Domain\Quotes\QuotationPolicies;
use App\Domain\Quotes\Quote;
use App\Domain\Quotes\QuoteRevision;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

final class DeliverQuoteNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public int $tenantId, public string $eventId, public string $revisionPublicId, public ?string $customerMessage = null) {}

    public function handle(TenantContext $context): void
    {
        $context->set(Tenant::findOrFail($this->tenantId));
        try {
            Cache::lock('quote-notice:'.$this->eventId, 60)->block(10, function (): void {
                $delivery = QuoteDelivery::where('event_id', $this->eventId)->firstOrFail();
                if ($delivery->status !== 'pending') {
                    return;
                }
                $quote = Quote::whereKey($delivery->quote_id)->firstOrFail();
                $revision = QuoteRevision::where('public_id', $this->revisionPublicId)->where('quote_id', $quote->id)->firstOrFail();
                $contact = $revision->contact_snapshot;
                $enabled = app(QuotationPolicies::class)->forService($quote->service_id)->notifications_enabled;
                if (! $enabled || ($contact['communication'] ?? 'none') !== 'email' || $quote->current_revision_id !== $revision->id) {
                    $delivery->status = 'skipped';
                    $delivery->save();

                    return;
                }
                $url = app(GuestQuoteLinks::class)->forQuote($quote)['view'];
                $business = CompanySettings::value('display_name');
                Mail::to($contact['email'])->send(new QuoteNotice($business, $quote->reference, $delivery->event_type, $url, $this->customerMessage));
                $delivery->status = 'sent';
                $delivery->sent_at = CarbonImmutable::now();
                $delivery->save();
            });
        } finally {
            $context->clear();
        }
    }
}
