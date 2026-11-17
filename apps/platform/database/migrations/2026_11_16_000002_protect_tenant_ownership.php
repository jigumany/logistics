<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_tenant_reassignment() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id THEN RAISE EXCEPTION 'Tenant ownership is immutable'; END IF; RETURN NEW; END $$;");
        foreach (['company_settings', 'service_types', 'outbox_messages', 'memberships'] as $table) {
            DB::statement("CREATE TRIGGER tenant_ownership_immutable BEFORE UPDATE OF tenant_id ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_tenant_reassignment()");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['company_settings', 'service_types', 'outbox_messages', 'memberships'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS tenant_ownership_immutable ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS reject_tenant_reassignment()');
    }
};
