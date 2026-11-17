<?php

namespace App\Domain\Quotes;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 */
class QuoteAttachment extends Model
{
    use BelongsToTenant;

    protected $table = 'quote_attachments';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
