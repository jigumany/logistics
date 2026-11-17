<?php

namespace App\Domain\Bookings;

use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class SchedulingTransaction
{
    public function execute(callable $operation): mixed
    {
        try {
            return DB::transaction(function () use ($operation) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement("SET LOCAL lock_timeout='5s'");
                }
                // A short tenant-row lock serialises commands consistently across all application instances.
                // GiST remains the authority even for writers which do not use this action.
                Tenant::whereKey(app(TenantContext::class)->tenant()->id)->lockForUpdate()->firstOrFail();
                $result = $operation();
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                    DB::statement('SET CONSTRAINTS ALL DEFERRED');
                }

                return $result;
            }, 5);
        } catch (QueryException $e) {
            if (in_array($e->errorInfo[0] ?? '', ['23P01', '40001', '40P01', '55P03'], true)) {
                throw BookingConflict::because('resource_conflict', 'Resources changed while reserving. Refresh availability and try another slot.');
            }
            throw $e;
        }
    }
}
