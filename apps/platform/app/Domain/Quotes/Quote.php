<?php

namespace App\Domain\Quotes;

use App\Domain\Tenancy\BelongsToTenant;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $simulation_price_book_id
 * @property array|null $simulation_options
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property array<string,mixed> $draft_input
 * @property int $service_id
 * @property int|null $customer_id
 * @property int|null $current_revision_id
 * @property CarbonImmutable|null $preferred_date
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable $access_expires_at
 * @property Carbon $updated_at
 * @property-read QuoteRevision|null $currentRevision
 */
class Quote extends Model
{
    use BelongsToTenant;

    protected $attributes = ['status' => 'draft', 'lock_version' => 0];

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id', 'idempotency_key', 'request_hash', 'creator_session_hash'];

    protected function casts(): array
    {
        return ['simulation_options' => 'array', 'simulation_price_book_id' => 'integer', 'draft_input' => 'array', 'preferred_date' => 'immutable_date', 'submitted_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'access_expires_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(QuoteRevision::class, 'current_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(QuoteRevision::class)->orderByDesc('number');
    }
}
