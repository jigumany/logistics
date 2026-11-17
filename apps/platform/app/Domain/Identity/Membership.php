<?php

namespace App\Domain\Identity;

use Illuminate\Database\Eloquent\Model;

/** @property Role $role */
class Membership extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'role'];

    protected function casts(): array
    {
        return ['role' => Role::class];
    }
}
