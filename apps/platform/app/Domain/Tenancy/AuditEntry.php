<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Model;

class AuditEntry extends Model
{
    use BelongsToTenant;

    protected $fillable = ['actor_id', 'action', 'subject_public_id'];

    protected $hidden = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
