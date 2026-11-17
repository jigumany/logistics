<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $fillable = ['name', 'slug', 'active'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Tenant $tenant) => $tenant->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
