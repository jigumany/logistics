<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_inventory', fn (Blueprint $t) => $t->foreign('catalogue_public_id')->references('public_id')->on('catalogue_items')->restrictOnDelete());
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_quotation_catalogue_reference() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.catalogue_public_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM catalogue_items WHERE public_id=NEW.catalogue_public_id AND (tenant_id IS NULL OR tenant_id=NEW.tenant_id)) THEN RAISE EXCEPTION 'Inventory catalogue ownership mismatch'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER valid_quote_catalogue_reference BEFORE INSERT OR UPDATE OF catalogue_public_id ON quote_inventory FOR EACH ROW EXECUTE FUNCTION guard_quotation_catalogue_reference();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS valid_quote_catalogue_reference ON quote_inventory');
            DB::statement('DROP FUNCTION IF EXISTS guard_quotation_catalogue_reference()');
        }
        Schema::table('quote_inventory', fn (Blueprint $t) => $t->dropForeign(['catalogue_public_id']));
    }
};
