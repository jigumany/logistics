<?php

namespace App\Domain\Pricing;

use App\Domain\Quotes\QuotePermissions;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\RecordsAdministrativeChange;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManagePriceBook
{
    public function save(array $data, int $actorId, ?PriceBook $book = null): PriceBook
    {
        app(QuotePermissions::class)->manage($actorId);

        return DB::transaction(function () use ($data, $actorId, $book): PriceBook {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            if ($book) {
                $book = PriceBook::whereKey($book->id)->lockForUpdate()->firstOrFail();
                if ($book->status !== 'draft') {
                    throw ValidationException::withMessages(['price_book' => 'Create a new version to change published pricing.']);
                }
            }
            $service = TenantService::where('public_id', $data['service_public_id'])->firstOrFail();
            if ($book && $book->service_id !== $service->id) {
                throw ValidationException::withMessages(['service_public_id' => 'The service of a pricing version cannot change.']);
            }
            try {
                $rules = (new PricingRules)->validate($data['rules']);
            } catch (\InvalidArgumentException $error) {
                throw ValidationException::withMessages(['rules' => $error->getMessage()]);
            }
            $isDemonstration = ($book ? $book->is_demonstration : null) ?? (bool) ($data['is_demonstration'] ?? false);
            if ($isDemonstration && (! app()->environment(['local', 'testing']) || app(TenantContext::class)->tenant()->slug !== 'cape-town-movers')) {
                throw ValidationException::withMessages(['price_book' => 'Demonstration pricing is restricted to the designated development tenant.']);
            }
            if ($book && array_key_exists('is_demonstration', $data) && (bool) $data['is_demonstration'] !== $book->is_demonstration) {
                throw ValidationException::withMessages(['price_book' => 'A pricing version cannot change its demonstration identity.']);
            }
            $configuration = app(PriceBookConfiguration::class)->validate($data['configuration'], $rules, $isDemonstration);
            if ($data['distance_basis'] !== 'customer' && empty($configuration['depot_public_id'])) {
                throw ValidationException::withMessages(['configuration' => 'Select a verified depot when depot or return distances are chargeable.']);
            }
            $book ??= new PriceBook(['demonstration_key' => $isDemonstration ? ($data['demonstration_key'] ?? null) : null, 'is_demonstration' => $isDemonstration, 'service_id' => $service->id, 'version' => (int) PriceBook::where('service_id', $service->id)->max('version') + 1, 'status' => 'draft']);
            $book->fill(array_intersect_key($data, array_flip(['name', 'currency', 'effective_from', 'effective_until', 'distance_basis'])));
            $book->configuration = $configuration;
            $book->save();
            PricingRule::where('price_book_id', $book->id)->delete();
            foreach ($rules as $rule) {
                PricingRule::create([...array_intersect_key($rule, array_flip(['code', 'label', 'stage', 'kind', 'metric', 'rate_micros', 'position', 'vehicle_type_key'])), 'price_book_id' => $book->id]);
            }
            app(RecordsAdministrativeChange::class)->record($book, 'price_book.saved', $actorId);

            return $book;
        });
    }

    public function publish(PriceBook $book, int $actorId): PriceBook
    {
        app(QuotePermissions::class)->manage($actorId);

        return DB::transaction(function () use ($book, $actorId): PriceBook {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            $book = PriceBook::whereKey($book->id)->lockForUpdate()->firstOrFail();
            if ($book->is_demonstration) {
                throw ValidationException::withMessages(['price_book' => 'Synthetic demonstration rates cannot be published as customer-facing commercial pricing.']);
            }
            if ($book->status !== 'draft') {
                throw ValidationException::withMessages(['price_book' => 'Only draft pricing can be published.']);
            }
            app(PriceBookConfiguration::class)->validate($book->configuration, PricingRule::where('price_book_id', $book->id)->get()->map(fn ($rule) => $rule->getAttributes())->all());
            $overlap = PriceBook::where('service_id', $book->service_id)->where('status', 'published')->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $book->effective_from));
            if ($book->effective_until) {
                $overlap->where('effective_from', '<', $book->effective_until);
            }
            if ($overlap->exists()) {
                throw ValidationException::withMessages(['effective_from' => 'Published price book effective periods must not overlap for a service.']);
            }
            $book->status = 'published';
            $book->published_at = CarbonImmutable::now();
            $book->save();
            app(RecordsAdministrativeChange::class)->record($book, 'price_book.published', $actorId);

            return $book;
        });
    }

    public function retire(PriceBook $book, int $actorId): void
    {
        app(QuotePermissions::class)->manage($actorId);
        DB::transaction(function () use ($book, $actorId): void {
            Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
            $book = PriceBook::whereKey($book->id)->lockForUpdate()->firstOrFail();
            if ($book->status !== 'published') {
                throw ValidationException::withMessages(['price_book' => 'Only published pricing can be retired.']);
            }
            $book->status = 'retired';
            $book->save();
            app(RecordsAdministrativeChange::class)->record($book, 'price_book.retired', $actorId);
        });
    }
}
