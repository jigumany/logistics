<?php

namespace App\Domain\Inventory;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property array $photos
 * @property int $revision_id
 */
class QuoteInventory extends Model
{
    use BelongsToTenant;

    protected $table = 'quote_inventory';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'fragile' => 'boolean', 'oversized' => 'boolean', 'stackable' => 'boolean', 'rotation_allowed' => 'boolean', 'photos' => 'array', 'provenance' => 'array'];
    }
}
