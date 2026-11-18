<?php

namespace App\Domain\Invoicing;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $public_id
 * @property int $booking_id
 * @property int $revision_id
 * @property array $lines
 * @property array $supplier_snapshot
 * @property array $customer_snapshot
 */
class Invoice extends Model
{
    use BelongsToTenant;

    protected $table = 'invoices';

    protected $guarded = ['id', 'tenant_id', 'public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'supplier_snapshot' => 'array', 'customer_snapshot' => 'array', 'issued_at' => 'immutable_datetime', 'due_date' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Evidence is immutable.'));
        static::deleting(fn () => throw new \LogicException('Evidence is retained.'));
    }
}
