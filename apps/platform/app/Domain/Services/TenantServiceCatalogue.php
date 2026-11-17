<?php

namespace App\Domain\Services;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class TenantServiceCatalogue
{
    public function key(): string
    {
        return 'tenant:'.app(TenantContext::class)->tenant()->public_id.':services:v2';
    }

    public function enabled(): array
    {
        return Cache::remember($this->key(), 300, fn () => TenantService::where('enabled', true)->where('active', true)->orderBy('name')->get(['public_id', 'name', 'notice_minutes', 'manual_approval', 'publicly_bookable'])->toArray());
    }

    public function invalidateAfterCommit(): void
    {
        $key = $this->key();
        DB::afterCommit(function () use ($key): void {
            try {
                Cache::forget($key);
            } catch (\Throwable $error) {
                report($error);
            }
        });
    }
}
