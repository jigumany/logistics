<?php

namespace App\Domain\Quotes;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 */
class QuoteReviewDecision extends Model
{
    use BelongsToTenant;

    protected $table = 'quote_review_decisions';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['adjustment' => 'array'];
    }
}
