<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_books', function (Blueprint $t): void {
            $t->boolean('is_demonstration')->default(false);
            $t->string('demonstration_key', 60)->nullable();
            $t->unique(['tenant_id', 'service_id', 'demonstration_key']);
        });
        Schema::table('quotes', function (Blueprint $t): void {
            $t->unsignedBigInteger('simulation_price_book_id')->nullable();
            $t->json('simulation_options')->nullable();
            $t->foreign(['tenant_id', 'simulation_price_book_id'])->references(['tenant_id', 'id'])->on('price_books')->restrictOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
ALTER TABLE price_books ADD CONSTRAINT demo_pricing_never_published CHECK (NOT is_demonstration OR status <> 'published');
CREATE OR REPLACE FUNCTION guard_demonstration_pricing() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.is_demonstration AND NOT NEW.is_demonstration THEN RAISE EXCEPTION 'Demonstration pricing cannot become commercial pricing'; END IF;
 IF OLD.demonstration_key IS DISTINCT FROM NEW.demonstration_key THEN RAISE EXCEPTION 'Fixture identity is immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_demonstration_pricing BEFORE UPDATE ON price_books FOR EACH ROW EXECUTE FUNCTION guard_demonstration_pricing();
CREATE OR REPLACE FUNCTION guard_quotation_simulation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' AND OLD.simulation_price_book_id IS DISTINCT FROM NEW.simulation_price_book_id THEN RAISE EXCEPTION 'Simulation identity is immutable'; END IF;
 IF NEW.simulation_price_book_id IS NOT NULL THEN
  IF NOT EXISTS (SELECT 1 FROM price_books b WHERE b.id=NEW.simulation_price_book_id AND b.tenant_id=NEW.tenant_id AND b.service_id=NEW.service_id AND b.is_demonstration) THEN RAISE EXCEPTION 'Simulation requires a matching demonstration book'; END IF;
  IF NEW.submitted_at IS NOT NULL OR NEW.accepted_at IS NOT NULL OR NEW.customer_id IS NOT NULL OR NEW.status IN ('quoted','accepted') THEN RAISE EXCEPTION 'A simulation cannot issue or accept a customer offer'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_quotation_simulation BEFORE INSERT OR UPDATE ON quotes FOR EACH ROW EXECUTE FUNCTION guard_quotation_simulation();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS protect_quotation_simulation ON quotes');
            DB::statement('DROP FUNCTION IF EXISTS guard_quotation_simulation()');
            DB::statement('DROP TRIGGER IF EXISTS protect_demonstration_pricing ON price_books');
            DB::statement('DROP FUNCTION IF EXISTS guard_demonstration_pricing()');
            DB::statement('ALTER TABLE price_books DROP CONSTRAINT IF EXISTS demo_pricing_never_published');
        }
        Schema::table('quotes', function (Blueprint $t): void {
            $t->dropForeign(['tenant_id', 'simulation_price_book_id']);
            $t->dropColumn(['simulation_price_book_id', 'simulation_options']);
        });
        Schema::table('price_books', function (Blueprint $t): void {
            $t->dropUnique(['tenant_id', 'service_id', 'demonstration_key']);
            $t->dropColumn(['is_demonstration', 'demonstration_key']);
        });
    }
};
