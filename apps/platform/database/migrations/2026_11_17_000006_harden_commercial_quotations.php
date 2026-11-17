<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $t): void {
            $t->boolean('capacity_verified')->default(false);
            $t->timestampTz('capacity_verified_at')->nullable();
            $t->string('capacity_verification_note', 1000)->nullable();
        });
        Schema::table('quotation_policies', function (Blueprint $t): void {
            $t->json('commercial_terms')->nullable();
            $t->string('terms_version', 64)->nullable();
        });
        Schema::table('quote_revisions', fn (Blueprint $t) => $t->json('terms_snapshot')->nullable());
        Schema::table('quotes', fn (Blueprint $t) => $t->string('accepted_terms_hash', 64)->nullable());
        Schema::table('route_calculations', function (Blueprint $t): void {
            $t->unsignedInteger('depot_duration_seconds')->nullable();
            $t->unsignedInteger('return_duration_seconds')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_vehicle_capacity_verification() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.capacity_verified AND (NEW.capacity_verified_at IS NULL OR length(trim(COALESCE(NEW.capacity_verification_note,''))) < 3) THEN RAISE EXCEPTION 'Verified capacity requires a timestamp and evidence note'; END IF;
 IF TG_OP='UPDATE' AND (NEW.vehicle_type_id,NEW.usable_volume_m3,NEW.payload_kg,NEW.cargo_length_m,NEW.cargo_width_m,NEW.cargo_height_m,NEW.max_item_length_m,NEW.max_item_width_m,NEW.max_item_height_m,NEW.capacity_margin_basis_points,NEW.registration_number) IS DISTINCT FROM (OLD.vehicle_type_id,OLD.usable_volume_m3,OLD.payload_kg,OLD.cargo_length_m,OLD.cargo_width_m,OLD.cargo_height_m,OLD.max_item_length_m,OLD.max_item_width_m,OLD.max_item_height_m,OLD.capacity_margin_basis_points,OLD.registration_number) AND NEW.capacity_verified_at IS NOT DISTINCT FROM OLD.capacity_verified_at THEN NEW.capacity_verified=false; NEW.capacity_verified_at=NULL; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER verify_vehicle_capacity BEFORE INSERT OR UPDATE ON vehicles FOR EACH ROW EXECUTE FUNCTION guard_vehicle_capacity_verification();
CREATE OR REPLACE FUNCTION guard_accepted_terms() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status='accepted' AND OLD.status <> 'accepted' AND EXISTS (SELECT 1 FROM quote_revisions r WHERE r.id=NEW.current_revision_id AND r.terms_snapshot IS NOT NULL) AND NOT EXISTS (SELECT 1 FROM quote_revisions r WHERE r.id=NEW.current_revision_id AND NEW.accepted_terms_hash=r.terms_snapshot->>'hash') THEN RAISE EXCEPTION 'Acceptance must preserve the issued terms hash'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER valid_accepted_terms BEFORE UPDATE ON quotes FOR EACH ROW EXECUTE FUNCTION guard_accepted_terms();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS valid_accepted_terms ON quotes');
            DB::statement('DROP FUNCTION IF EXISTS guard_accepted_terms()');
            DB::statement('DROP TRIGGER IF EXISTS verify_vehicle_capacity ON vehicles');
            DB::statement('DROP FUNCTION IF EXISTS guard_vehicle_capacity_verification()');
        }
        Schema::table('vehicles', fn (Blueprint $t) => $t->dropColumn(['capacity_verified', 'capacity_verified_at', 'capacity_verification_note']));
        Schema::table('quotation_policies', fn (Blueprint $t) => $t->dropColumn(['commercial_terms', 'terms_version']));
        Schema::table('quote_revisions', fn (Blueprint $t) => $t->dropColumn('terms_snapshot'));
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn('accepted_terms_hash'));
        Schema::table('route_calculations', fn (Blueprint $t) => $t->dropColumn(['depot_duration_seconds', 'return_duration_seconds']));
    }
};
