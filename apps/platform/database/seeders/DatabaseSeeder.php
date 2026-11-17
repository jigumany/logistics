<?php

namespace Database\Seeders;

use App\Domain\Identity\Membership;
use App\Domain\Identity\Role;
use App\Domain\Services\TenantService;
use App\Domain\Tenancy\CompanySettings;
use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Development seeding is forbidden outside local/testing.');
        }
        $password = env('DEMO_PASSWORD');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Set DEMO_PASSWORD to at least 12 characters.');
        }
        DB::transaction(function () use ($password): void {
            $user = User::firstOrCreate(['email' => 'owner@example.test'], ['name' => 'Development Owner', 'password' => $password]);
            $tenant = Tenant::firstOrCreate(['slug' => 'cape-town-movers'], ['name' => 'Cape Town Movers']);
            Membership::firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $user->id], ['role' => Role::Owner]);
            $context = app(TenantContext::class);
            $context->set($tenant);
            try {
                CompanySettings::firstOrCreate([], ['display_name' => $tenant->name, 'contact_email' => 'operations@example.test', 'payment_terms' => 'deposit', 'deposit_basis_points' => 2500]);
                foreach (['household' => 'Household moving', 'office' => 'Office moving', 'general' => 'General goods transport'] as $code => $name) {
                    TenantService::firstOrCreate(['code' => $code], ['name' => $name, 'enabled' => true]);
                }
            } finally {
                $context->clear();
            }
        });
        $this->call(OperationalSeeder::class);
    }
}
