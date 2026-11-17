<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function owned(Blueprint $table): void
    {
        $table->id();
        $table->ulid('public_id')->unique();
        $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
        $table->timestampsTz();
        $table->unique(['tenant_id', 'id']);
    }

    private function reference(Blueprint $table, string $column, string $parent): void
    {
        $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($parent)->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::rename('service_types', 'tenant_services');
        Schema::create('service_types', function (Blueprint $t): void {
            $t->id();
            $t->string('key', 50)->unique();
            $t->string('name', 100);
        });
        foreach (['household_moving' => 'Household Moving', 'office_relocation' => 'Office Relocation', 'goods_transport' => 'General Goods Transportation'] as $key => $name) {
            DB::table('service_types')->insert(['key' => $key, 'name' => $name]);
        }
        Schema::table('tenant_services', function (Blueprint $t): void {
            $t->foreignId('service_type_id')->nullable()->constrained('service_types')->restrictOnDelete();
            $t->text('description')->nullable();
            $t->unsignedInteger('notice_minutes')->default(1440);
            $t->text('restrictions')->nullable();
            $t->boolean('manual_approval')->default(false);
            $t->boolean('publicly_bookable')->default(false);
            $t->boolean('active')->default(true);
            $t->unique(['tenant_id', 'service_type_id']);
        });
        foreach (['household' => 'household_moving', 'office' => 'office_relocation', 'general' => 'goods_transport'] as $code => $key) {
            DB::table('tenant_services')->where('code', $code)->update(['service_type_id' => DB::table('service_types')->where('key', $key)->value('id')]);
        }
        Schema::create('depots', function (Blueprint $t): void {
            $this->owned($t);
            $t->string('name', 160);
            $t->string('address_line', 255);
            $t->string('suburb', 100)->nullable();
            $t->string('city', 100);
            $t->string('postal_code', 20)->nullable();
            $t->string('country_code', 2)->default('ZA');
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->string('coordinate_source', 100)->nullable();
            $t->boolean('coordinates_verified')->default(false);
            $t->string('contact_name', 160)->nullable();
            $t->string('contact_email', 254)->nullable();
            $t->string('contact_phone', 30)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->boolean('active')->default(true);
            $t->string('timezone', 64)->default('Africa/Johannesburg');
            $t->index(['tenant_id', 'active', 'name']);
        });
        DB::statement('CREATE UNIQUE INDEX depots_one_primary ON depots (tenant_id) WHERE is_primary = '.(DB::getDriverName() === 'pgsql' ? 'true' : '1'));
        Schema::create('vehicle_types', function (Blueprint $t): void {
            $t->id();
            $t->string('key', 50)->unique();
            $t->string('name', 100);
            $t->text('description')->nullable();
            $t->string('vehicle_class', 40);
            foreach (['usable_volume_m3', 'payload_kg', 'cargo_length_m', 'cargo_width_m', 'cargo_height_m'] as $field) {
                $t->decimal($field, 12, 3);
            }
            $t->boolean('active')->default(true);
        });
        DB::table('vehicle_types')->insert(['key' => 'sample_van', 'name' => 'Van — example defaults', 'description' => 'Development catalogue defaults; verify actual vehicle measurements.', 'vehicle_class' => 'light', 'usable_volume_m3' => 10, 'payload_kg' => 1000, 'cargo_length_m' => 3, 'cargo_width_m' => 1.7, 'cargo_height_m' => 2, 'active' => true]);
        DB::table('vehicle_types')->insert([
            ['key' => 'sample_medium_truck', 'name' => 'Medium truck — example defaults', 'description' => 'Example catalogue measurements; replace with verified vehicle measurements.', 'vehicle_class' => 'medium', 'usable_volume_m3' => 25, 'payload_kg' => 4000, 'cargo_length_m' => 6, 'cargo_width_m' => 2.2, 'cargo_height_m' => 2.1, 'active' => true],
            ['key' => 'sample_heavy_truck', 'name' => 'Heavy truck — example defaults', 'description' => 'Example catalogue measurements; replace with verified vehicle measurements.', 'vehicle_class' => 'heavy', 'usable_volume_m3' => 45, 'payload_kg' => 8000, 'cargo_length_m' => 8, 'cargo_width_m' => 2.3, 'cargo_height_m' => 2.5, 'active' => true],
        ]);
        Schema::table('company_settings', function (Blueprint $t): void {
            foreach (['registered_name', 'registration_number', 'vat_number', 'primary_contact_name', 'website', 'operating_address', 'billing_address', 'operational_email', 'operational_phone'] as $field) {
                $t->string($field, 255)->nullable();
            }
            $t->text('description')->nullable();
            $t->string('logo_path')->nullable();
            $t->enum('operational_status', ['active', 'paused', 'closed'])->default('active');
            $t->enum('measurement_units', ['metric', 'imperial'])->default('metric');
            $t->unsignedInteger('booking_notice_minutes')->default(1440);
            $t->unsignedInteger('job_buffer_minutes')->default(60);
            $t->boolean('public_contact_visible')->default(false);
            $t->boolean('booking_enabled')->default(false);
            $t->unsignedBigInteger('default_service_id')->nullable();
            $this->reference($t, 'default_service_id', 'tenant_services');
        });
        Schema::create('vehicles', function (Blueprint $t): void {
            $this->owned($t);
            $t->uuid('uuid')->unique();
            $t->string('name', 160);
            $t->string('registration_number', 30);
            $t->foreignId('vehicle_type_id')->constrained()->restrictOnDelete();
            $t->string('make', 80)->nullable();
            $t->string('model', 80)->nullable();
            $t->unsignedSmallInteger('manufacture_year')->nullable();
            $t->enum('status', ['available', 'inactive', 'maintenance', 'out_of_service'])->default('inactive');
            $t->unsignedBigInteger('depot_id')->nullable();
            $this->reference($t, 'depot_id', 'depots');
            $t->index(['tenant_id', 'depot_id']);
            foreach (['usable_volume_m3', 'payload_kg', 'cargo_length_m', 'cargo_width_m', 'cargo_height_m'] as $field) {
                $t->decimal($field, 12, 3);
            }
            foreach (['max_item_length_m', 'max_item_width_m', 'max_item_height_m'] as $field) {
                $t->decimal($field, 12, 3)->nullable();
            }
            $t->unsignedSmallInteger('capacity_margin_basis_points')->default(1000);
            $t->text('operating_restrictions')->nullable();
            $t->text('notes')->nullable();
            $t->string('photo_path')->nullable();
            $t->timestampTz('archived_at')->nullable();
            // Registration remains reserved after archival to preserve vehicle identity.
            $t->unique(['tenant_id', 'registration_number']);
            $t->index(['tenant_id', 'archived_at', 'status', 'id']);
            $t->index(['tenant_id', 'name']);
        });
        Schema::create('staff_members', function (Blueprint $t): void {
            $this->owned($t);
            $t->string('full_name', 160);
            $t->string('email', 254)->nullable();
            $t->string('phone', 30)->nullable();
            $t->enum('role', ['driver', 'mover', 'dispatcher', 'operations_manager']);
            $t->enum('engagement_status', ['employed', 'contractor', 'suspended', 'ended'])->default('employed');
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('depot_id')->nullable();
            $this->reference($t, 'depot_id', 'depots');
            $t->index(['tenant_id', 'depot_id']);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'user_id'])->on('memberships')->restrictOnDelete();
            $t->unique(['tenant_id', 'user_id']);
            $t->string('licence_category', 40)->nullable();
            $t->date('licence_expires_on')->nullable();
            $t->text('qualifications')->nullable();
            $t->text('notes')->nullable();
            $t->timestampTz('archived_at')->nullable();
            $t->index(['tenant_id', 'archived_at', 'role', 'active', 'id']);
            $t->index(['tenant_id', 'full_name']);
        });
        Schema::create('driver_vehicle_classes', function (Blueprint $t): void {
            $this->owned($t);
            $t->unsignedBigInteger('staff_id');
            $this->reference($t, 'staff_id', 'staff_members');
            $t->string('vehicle_class', 40);
            $t->unique(['tenant_id', 'staff_id', 'vehicle_class']);
        });
        // Future allocation rows can reference (tenant_id,id) and exclude overlapping half-open tstzrange values using GiST with tenant_id/resource_id equality.
        Schema::create('resources', function (Blueprint $t): void {
            $this->owned($t);
            $t->enum('kind', ['vehicle', 'driver', 'mover']);
            $t->unsignedBigInteger('vehicle_id')->nullable();
            $t->unsignedBigInteger('staff_id')->nullable();
            $this->reference($t, 'vehicle_id', 'vehicles');
            $this->reference($t, 'staff_id', 'staff_members');
            $t->boolean('active')->default(false);
            $t->unique(['tenant_id', 'vehicle_id']);
            $t->unique(['tenant_id', 'staff_id']);
            $t->index(['tenant_id', 'active', 'kind', 'id']);
        });
        Schema::create('service_zones', function (Blueprint $t): void {
            $this->owned($t);
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->enum('zone_type', ['pickup', 'delivery', 'both'])->default('both');
            $t->json('boundary');
            $t->boolean('active')->default(false);
            $t->integer('priority')->default(0);
            $t->text('restrictions')->nullable();
            $t->boolean('manual_review')->default(false);
            $t->boolean('example_only')->default(false);
            $t->index(['tenant_id', 'active', 'priority', 'id']);
        });
        Schema::create('working_schedules', function (Blueprint $t): void {
            $this->owned($t);
            $t->unsignedBigInteger('resource_id')->nullable();
            $this->reference($t, 'resource_id', 'resources');
            $t->unsignedTinyInteger('weekday');
            $t->time('opens_at');
            $t->time('closes_at');
            $t->boolean('ends_next_day')->default(false);
            $t->string('timezone', 64);
            $t->boolean('active')->default(true);
        });
        DB::statement('CREATE UNIQUE INDEX company_weekly_schedule ON working_schedules (tenant_id, weekday) WHERE resource_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX resource_weekly_schedule ON working_schedules (tenant_id, resource_id, weekday) WHERE resource_id IS NOT NULL');
        Schema::create('schedule_overrides', function (Blueprint $t): void {
            $this->owned($t);
            $t->unsignedBigInteger('resource_id')->nullable();
            $this->reference($t, 'resource_id', 'resources');
            $t->date('date');
            $t->boolean('closed')->default(true);
            $t->time('opens_at')->nullable();
            $t->time('closes_at')->nullable();
            $t->boolean('ends_next_day')->default(false);
            $t->string('timezone', 64);
            $t->string('reason', 255)->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX company_date_override ON schedule_overrides (tenant_id, date) WHERE resource_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX resource_date_override ON schedule_overrides (tenant_id, resource_id, date) WHERE resource_id IS NOT NULL');
        Schema::create('resource_blocks', function (Blueprint $t): void {
            $this->owned($t);
            $t->unsignedBigInteger('resource_id');
            $this->reference($t, 'resource_id', 'resources');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->enum('reason', ['maintenance', 'leave', 'administrative', 'blackout']);
            $t->text('notes')->nullable();
            $t->index(['tenant_id', 'resource_id', 'starts_at', 'ends_at']);
        });
        Schema::create('staff_documents', function (Blueprint $t): void {
            $this->owned($t);
            $t->unsignedBigInteger('staff_id');
            $this->reference($t, 'staff_id', 'staff_members');
            $t->string('path');
            $t->string('original_name', 255);
            $t->string('mime_type', 100);
            $t->unsignedBigInteger('size_bytes');
            $t->index(['tenant_id', 'staff_id', 'id']);
        });
        $resourceCheck = "(kind = 'vehicle' AND vehicle_id IS NOT NULL AND staff_id IS NULL) OR (kind IN ('driver','mover') AND staff_id IS NOT NULL AND vehicle_id IS NULL)";
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tenant_services ALTER COLUMN service_type_id SET NOT NULL');
            DB::statement("ALTER TABLE resources ADD CONSTRAINT resource_entity_mapping CHECK ({$resourceCheck})");
            DB::statement('ALTER TABLE service_zones ADD COLUMN geometry geometry(Polygon,4326) NOT NULL');
            DB::statement('CREATE INDEX service_zones_geometry_gist ON service_zones USING GIST (geometry)');
            DB::statement('ALTER TABLE service_zones ADD CONSTRAINT valid_zone_geometry CHECK (ST_IsValid(geometry) AND NOT ST_IsEmpty(geometry))');
            DB::unprepared('CREATE OR REPLACE FUNCTION sync_zone_geometry() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.geometry := ST_SetSRID(ST_GeomFromGeoJSON(NEW.boundary::text),4326); RETURN NEW; END $$; CREATE TRIGGER zones_geometry BEFORE INSERT OR UPDATE OF boundary ON service_zones FOR EACH ROW EXECUTE FUNCTION sync_zone_geometry();');
            foreach (['depots', 'vehicles', 'staff_members', 'resources', 'driver_vehicle_classes', 'service_zones', 'working_schedules', 'schedule_overrides', 'resource_blocks', 'staff_documents'] as $table) {
                DB::statement("CREATE TRIGGER tenant_ownership_immutable BEFORE UPDATE OF tenant_id ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_tenant_reassignment()");
            }
            foreach (['usable_volume_m3', 'payload_kg', 'cargo_length_m', 'cargo_width_m', 'cargo_height_m'] as $field) {
                DB::statement("ALTER TABLE vehicles ADD CHECK ({$field} > 0)");
            }
            DB::statement('ALTER TABLE vehicles ADD CHECK (capacity_margin_basis_points BETWEEN 0 AND 10000)');
            DB::statement("ALTER TABLE vehicles ADD CHECK (registration_number ~ '^[A-Z0-9]{2,20}$')");
            DB::statement('ALTER TABLE vehicles ADD CHECK (usable_volume_m3 <= cargo_length_m * cargo_width_m * cargo_height_m * 1.001)');
            foreach (['max_item_length_m', 'max_item_width_m', 'max_item_height_m'] as $field) {
                DB::statement("ALTER TABLE vehicles ADD CHECK ({$field} IS NULL OR {$field} > 0)");
            }
            DB::statement('ALTER TABLE company_settings ADD CHECK (booking_notice_minutes BETWEEN 0 AND 525600 AND job_buffer_minutes BETWEEN 0 AND 10080)');
            DB::statement('ALTER TABLE tenant_services ADD CHECK (notice_minutes BETWEEN 0 AND 525600)');
            DB::statement('ALTER TABLE depots ADD CHECK (NOT is_primary OR active)');
            DB::statement("ALTER TABLE driver_vehicle_classes ADD CHECK (vehicle_class IN ('light','medium','heavy','articulated'))");
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_resource_reassignment() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.vehicle_id IS DISTINCT FROM OLD.vehicle_id OR NEW.staff_id IS DISTINCT FROM OLD.staff_id OR NEW.public_id IS DISTINCT FROM OLD.public_id THEN RAISE EXCEPTION 'Resource identity is immutable'; END IF; RETURN NEW; END $$; CREATE TRIGGER resource_identity_immutable BEFORE UPDATE ON resources FOR EACH ROW EXECUTE FUNCTION reject_resource_reassignment();");
            DB::statement('ALTER TABLE resource_blocks ADD CHECK (ends_at > starts_at)');
            DB::statement('ALTER TABLE working_schedules ADD CHECK (weekday BETWEEN 0 AND 6 AND ((ends_next_day AND closes_at <= opens_at) OR (NOT ends_next_day AND closes_at > opens_at)))');
            DB::statement('ALTER TABLE schedule_overrides ADD CHECK (closed OR (opens_at IS NOT NULL AND closes_at IS NOT NULL AND (ends_next_day OR closes_at > opens_at)))');
            DB::statement('ALTER TABLE depots ADD CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180)');
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_catalogue_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'System catalogue definitions are immutable'; END $$; CREATE TRIGGER immutable_service_definitions BEFORE UPDATE OR DELETE ON service_types FOR EACH ROW EXECUTE FUNCTION reject_catalogue_mutation();");
        } else {
            $newCheck = str_replace(['kind', 'vehicle_id', 'staff_id'], ['NEW.kind', 'NEW.vehicle_id', 'NEW.staff_id'], $resourceCheck);
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER resource_entity_mapping_{$operation} BEFORE {$operation} ON resources WHEN NOT ({$newCheck}) BEGIN SELECT RAISE(ABORT,'Invalid resource entity mapping'); END;");
            }
        }
    }

    public function down(): void
    {
        foreach (['staff_documents', 'resource_blocks', 'schedule_overrides', 'working_schedules', 'service_zones', 'resources', 'driver_vehicle_classes', 'staff_members', 'vehicles'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('company_settings', function (Blueprint $t): void {
            $t->dropForeign(['tenant_id', 'default_service_id']);
            $t->dropColumn(['registered_name', 'registration_number', 'vat_number', 'primary_contact_name', 'website', 'operating_address', 'billing_address', 'operational_email', 'operational_phone', 'description', 'logo_path', 'operational_status', 'measurement_units', 'booking_notice_minutes', 'job_buffer_minutes', 'public_contact_visible', 'booking_enabled', 'default_service_id']);
        });
        Schema::dropIfExists('depots');
        Schema::dropIfExists('vehicle_types');
        Schema::table('tenant_services', function (Blueprint $t): void {
            $t->dropForeign(['service_type_id']);
            $t->dropUnique(['tenant_id', 'service_type_id']);
            $t->dropColumn(['service_type_id', 'description', 'notice_minutes', 'restrictions', 'manual_approval', 'publicly_bookable', 'active']);
        });
        Schema::dropIfExists('service_types');
        Schema::rename('tenant_services', 'service_types');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS sync_zone_geometry()');
            DB::statement('DROP FUNCTION IF EXISTS reject_catalogue_mutation()');
            DB::statement('DROP FUNCTION IF EXISTS reject_resource_reassignment()');
        }
    }
};
