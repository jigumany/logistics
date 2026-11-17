<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private function owned(Blueprint $t): void
    {
        $t->id();
        $t->ulid('public_id')->unique();
        $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
        $t->timestampsTz();
        $t->unique(['tenant_id', 'id']);
    }

    private function reference(Blueprint $t, string $column, string $parent, bool $nullable = false): void
    {
        $field = $t->unsignedBigInteger($column);
        if ($nullable) {
            $field->nullable();
        }
        $t->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($parent)->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::table('audit_entries', fn (Blueprint $t) => $t->json('details')->nullable());
        Schema::create('catalogue_items', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name', 160);
            $t->string('category', 80);
            $t->text('description')->nullable();
            foreach (['length_cm', 'width_cm', 'height_cm', 'weight_kg', 'volume_m3'] as $field) {
                $t->decimal($field, 15, 6)->nullable();
            }
            $t->enum('handling', ['standard', 'fragile', 'special'])->default('standard');
            $t->boolean('active')->default(true);
            $t->string('image_path')->nullable();
            $t->timestampsTz();
            $t->index(['tenant_id', 'active', 'category', 'name']);
        });
        foreach ([['Medium box', 'boxes', 60, 40, 40, null, 'standard'], ['Dining chair', 'furniture', 50, 50, 90, 6, 'standard'], ['Refrigerator', 'appliances', 70, 70, 180, 80, 'special'], ['Office desk', 'office equipment', 140, 70, 75, 25, 'standard'], ['Loaded pallet', 'general cargo', 120, 100, 150, null, 'special']] as [$name,$category,$length,$width,$height,$weight,$handling]) {
            DB::table('catalogue_items')->insert(['public_id' => (string) Str::ulid(), 'tenant_id' => null, 'name' => $name, 'category' => $category, 'description' => 'Illustrative catalogue estimate. Confirm the actual item measurements and handling requirements.', 'length_cm' => $length, 'width_cm' => $width, 'height_cm' => $height, 'weight_kg' => $weight, 'volume_m3' => $length * $width * $height / 1000000, 'handling' => $handling, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('customers', function (Blueprint $t): void {
            $this->owned($t);
            $t->string('name', 160);
            $t->string('email', 254);
            $t->string('phone', 30)->nullable();
            $t->timestampTz('email_verified_at')->nullable();
            $t->unique(['tenant_id', 'email']);
        });
        Schema::create('quotation_policies', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'service_id', 'tenant_services');
            $t->unique(['tenant_id', 'service_id']);
            $t->boolean('automatic_estimates')->default(false);
            $t->boolean('allow_catalogue_estimates')->default(false);
            $t->boolean('allow_customer_measurements')->default(false);
            $t->boolean('allow_cross_zone')->default(true);
            $t->enum('outside_zone', ['review', 'reject'])->default('review');
            $t->unsignedSmallInteger('safety_margin_bps')->default(1000);
            $t->unsignedSmallInteger('borderline_bps')->default(9000);
            $t->unsignedSmallInteger('max_auto_stops')->default(0);
            $t->unsignedSmallInteger('max_auto_stairs')->default(0);
            $t->unsignedSmallInteger('validity_hours')->default(72);
            $t->boolean('notifications_enabled')->default(false);
            $t->text('terms')->nullable();
            $t->text('exclusions')->nullable();
        });
        Schema::create('price_books', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'service_id', 'tenant_services');
            $t->string('name', 160);
            $t->unsignedInteger('version');
            $t->enum('status', ['draft', 'published', 'retired'])->default('draft');
            $t->string('currency', 3);
            $t->timestampTz('effective_from');
            $t->timestampTz('effective_until')->nullable();
            $t->enum('distance_basis', ['customer', 'customer_depot', 'customer_return', 'operational'])->default('customer');
            $t->json('configuration');
            $t->timestampTz('published_at')->nullable();
            $t->unique(['tenant_id', 'service_id', 'version']);
            $t->index(['tenant_id', 'service_id', 'status', 'effective_from']);
        });
        Schema::create('pricing_rules', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'price_book_id', 'price_books');
            $t->string('code', 60);
            $t->string('label', 160);
            $t->enum('stage', ['charge', 'surcharge', 'discount', 'minimum', 'tax']);
            $t->enum('kind', ['fixed', 'rate', 'percent']);
            $t->string('metric', 50)->nullable();
            $t->bigInteger('rate_micros');
            $t->string('vehicle_type_key', 50)->nullable();
            $t->unsignedSmallInteger('position')->default(0);
            $t->unique(['tenant_id', 'price_book_id', 'code']);
        });
        Schema::create('quotes', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'service_id', 'tenant_services');
            $this->reference($t, 'customer_id', 'customers', true);
            $t->string('reference', 40);
            $t->uuid('idempotency_key');
            $t->string('request_hash', 64);
            $t->string('creator_session_hash', 64)->nullable();
            $t->string('input_hash', 64);
            $t->json('draft_input');
            $t->enum('status', ['draft', 'calculating', 'requires_review', 'quoted', 'accepted', 'expired', 'declined', 'cancelled'])->default('draft');
            $t->unsignedInteger('lock_version')->default(0);
            $t->unsignedBigInteger('current_revision_id')->nullable();
            $t->date('preferred_date')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('access_expires_at');
            $t->unique(['tenant_id', 'reference']);
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'status', 'created_at', 'id']);
            $t->index(['tenant_id', 'customer_id', 'id']);
        });
        Schema::create('route_calculations', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'quote_id', 'quotes');
            $t->string('input_hash', 64);
            $t->string('provider', 40);
            $t->enum('status', ['available', 'unavailable']);
            $t->string('failure_code', 60)->nullable();
            foreach (['customer_metres', 'depot_metres', 'return_metres', 'duration_seconds', 'tolls_minor'] as $field) {
                $t->unsignedBigInteger($field)->nullable();
            }
            $t->timestampTz('retain_until')->nullable();
            $t->index(['tenant_id', 'quote_id', 'input_hash']);
            $t->unique(['tenant_id', 'quote_id', 'id']);
        });
        Schema::create('quote_revisions', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'quote_id', 'quotes');
            $this->reference($t, 'price_book_id', 'price_books', true);
            $this->reference($t, 'route_calculation_id', 'route_calculations', true);
            $t->foreign(['tenant_id', 'quote_id', 'route_calculation_id'])->references(['tenant_id', 'quote_id', 'id'])->on('route_calculations')->restrictOnDelete();
            $t->unsignedInteger('number');
            $t->enum('approval', ['automatic', 'pending', 'approved']);
            $t->string('service_name', 160);
            $t->json('branding_snapshot');
            foreach (['input', 'inventory_summary', 'coverage', 'suitability', 'review_reasons', 'pricing_snapshot', 'contact_snapshot'] as $field) {
                $t->json($field);
            }
            $t->string('currency', 3)->nullable();
            $t->unsignedBigInteger('subtotal_minor')->nullable();
            $t->unsignedBigInteger('tax_minor')->nullable();
            $t->unsignedBigInteger('total_minor')->nullable();
            $t->text('terms');
            $t->text('exclusions');
            $t->timestampTz('expires_at');
            $t->timestampTz('sealed_at')->nullable();
            $t->unique(['tenant_id', 'quote_id', 'number']);
            $t->unique(['tenant_id', 'quote_id', 'id']);
        });
        Schema::table('quotes', function (Blueprint $t): void {
            $t->foreign(['tenant_id', 'id', 'current_revision_id'])->references(['tenant_id', 'quote_id', 'id'])->on('quote_revisions')->restrictOnDelete();
        });
        Schema::create('quote_inventory', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'revision_id', 'quote_revisions');
            $t->uuid('item_key');
            $t->string('name', 160);
            $t->ulid('catalogue_public_id')->nullable();
            $t->unsignedInteger('quantity');
            foreach (['length_cm', 'width_cm', 'height_cm', 'weight_kg', 'volume_m3'] as $field) {
                $t->decimal($field, 15, 6)->nullable();
            }
            $t->enum('measurement_source', ['catalogue', 'customer', 'measured', 'unknown']);
            $t->boolean('fragile');
            $t->boolean('oversized');
            $t->boolean('stackable');
            $t->boolean('rotation_allowed');
            $t->text('instructions')->nullable();
            $t->json('photos');
            $t->json('provenance');
            $t->index(['tenant_id', 'revision_id', 'id']);
        });
        Schema::create('route_stops', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'revision_id', 'quote_revisions');
            $t->unsignedSmallInteger('position');
            $t->enum('kind', ['pickup', 'waypoint', 'delivery']);
            $t->string('address_line', 255);
            $t->string('suburb', 100)->nullable();
            $t->string('city', 100);
            $t->string('postal_code', 20)->nullable();
            $t->string('country_code', 2);
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->string('provider_place_id', 255)->nullable();
            $t->enum('verification', ['manual', 'provider', 'staff']);
            $t->text('instructions')->nullable();
            $t->unique(['tenant_id', 'revision_id', 'position']);
        });
        Schema::create('pricing_calculations', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'revision_id', 'quote_revisions');
            $t->json('metrics');
            $t->json('configuration');
            $t->string('engine_version', 20);
            $t->unique(['tenant_id', 'revision_id']);
        });
        Schema::create('quote_line_items', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'revision_id', 'quote_revisions');
            $t->string('code', 60);
            $t->string('description', 160);
            $t->string('stage', 20);
            $t->decimal('quantity', 18, 6);
            $t->bigInteger('rate_micros');
            $t->bigInteger('amount_minor');
            $t->unsignedSmallInteger('position');
            $t->unique(['tenant_id', 'revision_id', 'position']);
        });
        Schema::create('quote_review_decisions', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'quote_id', 'quotes');
            $this->reference($t, 'revision_id', 'quote_revisions');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->foreign(['tenant_id', 'actor_id'])->references(['tenant_id', 'user_id'])->on('memberships')->restrictOnDelete();
            $t->foreign(['tenant_id', 'quote_id', 'revision_id'])->references(['tenant_id', 'quote_id', 'id'])->on('quote_revisions')->restrictOnDelete();
            $t->enum('decision', ['note', 'approve', 'reject', 'request_information', 'revise', 'customer_response']);
            $t->text('reason');
            $t->uuid('request_key')->nullable();
            $t->unique(['tenant_id', 'quote_id', 'request_key']);
            $t->json('adjustment')->nullable();
            $t->index(['tenant_id', 'quote_id', 'id']);
        });
        Schema::create('quote_status_history', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'quote_id', 'quotes');
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->foreign(['tenant_id', 'actor_id'])->references(['tenant_id', 'user_id'])->on('memberships')->restrictOnDelete();
            $t->index(['tenant_id', 'quote_id', 'id']);
        });
        Schema::create('quote_attachments', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'quote_id', 'quotes');
            $t->uuid('item_key');
            $t->string('path');
            $t->string('mime_type', 80);
            $t->string('sha256', 64);
            $t->unique(['tenant_id', 'quote_id', 'item_key', 'sha256']);
            $t->unsignedBigInteger('size_bytes');
            $t->index(['tenant_id', 'quote_id', 'item_key']);
        });
        Schema::create('quote_documents', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'revision_id', 'quote_revisions');
            $t->string('path');
            $t->unique(['tenant_id', 'revision_id']);
        });
        Schema::create('quote_deliveries', function (Blueprint $t): void {
            $this->owned($t);
            $this->reference($t, 'quote_id', 'quotes');
            $t->ulid('event_id')->unique();
            $t->string('event_type', 60);
            $t->enum('status', ['pending', 'sent', 'skipped'])->default('pending');
            $t->timestampTz('sent_at')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach (['customers', 'quotation_policies', 'price_books', 'pricing_rules', 'quotes', 'route_calculations', 'quote_revisions', 'quote_inventory', 'route_stops', 'pricing_calculations', 'quote_line_items', 'quote_review_decisions', 'quote_status_history', 'quote_attachments', 'quote_documents', 'quote_deliveries', 'catalogue_items'] as $table) {
                DB::statement("CREATE TRIGGER tenant_ownership_immutable BEFORE UPDATE OF tenant_id ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_tenant_reassignment()");
            }
            DB::statement('ALTER TABLE quotation_policies ADD CHECK (service_id IS NOT NULL AND safety_margin_bps BETWEEN 0 AND 5000 AND borderline_bps BETWEEN 5000 AND 10000 AND validity_hours BETWEEN 1 AND 720 AND max_auto_stops <= 8 AND max_auto_stairs <= 100)');
            DB::statement("ALTER TABLE price_books ADD CHECK (service_id IS NOT NULL AND version > 0 AND currency IN ('ZAR','USD','EUR','GBP') AND (effective_until IS NULL OR effective_until > effective_from))");
            DB::statement('ALTER TABLE quote_inventory ADD CHECK (quantity BETWEEN 1 AND 1000 AND (weight_kg IS NULL OR weight_kg >= 0) AND (volume_m3 IS NULL OR volume_m3 > 0))');
            DB::statement('ALTER TABLE quote_revisions ADD CHECK (quote_id IS NOT NULL AND number > 0 AND (total_minor IS NULL OR (total_minor = subtotal_minor + tax_minor AND subtotal_minor >= 0 AND tax_minor >= 0)))');
            DB::statement("ALTER TABLE quote_review_decisions ADD CHECK ((decision='customer_response' AND actor_id IS NULL) OR (decision<>'customer_response' AND actor_id IS NOT NULL))");
            DB::statement('ALTER TABLE pricing_rules ADD CHECK (price_book_id IS NOT NULL AND rate_micros >= 0)');
            DB::statement("ALTER TABLE price_books ADD CONSTRAINT price_book_no_overlap EXCLUDE USING gist (tenant_id WITH =, service_id WITH =, tstzrange(effective_from,effective_until,'[)') WITH &&) WHERE (status='published')");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_quotation_revision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
   IF EXISTS (SELECT 1 FROM quotes WHERE id=NEW.quote_id AND status='accepted') THEN RAISE EXCEPTION 'Accepted quotes are immutable'; END IF;
   RETURN NEW;
 END IF;
 IF OLD.sealed_at IS NOT NULL THEN RAISE EXCEPTION 'Quote revisions are immutable'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER immutable_quote_revision BEFORE INSERT OR UPDATE OR DELETE ON quote_revisions FOR EACH ROW EXECUTE FUNCTION guard_quotation_revision();
CREATE OR REPLACE FUNCTION guard_quotation_child() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE revision bigint;
BEGIN
 revision := CASE WHEN TG_OP='DELETE' THEN OLD.revision_id ELSE NEW.revision_id END;
 IF TG_OP='UPDATE' AND EXISTS (SELECT 1 FROM quote_revisions WHERE id=OLD.revision_id AND sealed_at IS NOT NULL) THEN RAISE EXCEPTION 'Issued revision details are immutable'; END IF;
 IF EXISTS (SELECT 1 FROM quote_revisions WHERE id=revision AND sealed_at IS NOT NULL) THEN RAISE EXCEPTION 'Issued revision details are immutable'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION guard_quote_transition() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='INSERT' THEN IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'Quotes start as drafts'; END IF; RETURN NEW; END IF;
 IF OLD.status='accepted' THEN RAISE EXCEPTION 'Accepted quotes are immutable'; END IF;
 IF NEW.status <> OLD.status AND NOT (
 (OLD.status='draft' AND NEW.status IN ('calculating','cancelled')) OR
 (OLD.status='calculating' AND NEW.status IN ('requires_review','quoted')) OR
 (OLD.status='requires_review' AND NEW.status IN ('calculating','quoted','declined','cancelled')) OR
 (OLD.status='quoted' AND NEW.status IN ('calculating','accepted','expired','declined','cancelled')) OR
 (OLD.status='expired' AND NEW.status='calculating')
 ) THEN RAISE EXCEPTION 'Invalid quote status transition'; END IF;
 IF NEW.status='accepted' AND (NEW.submitted_at IS NULL OR NEW.customer_id IS NULL OR NEW.accepted_at IS NULL) THEN RAISE EXCEPTION 'Acceptance requires a submitted contact and timestamp'; END IF;
 IF NEW.status='accepted' AND NOT EXISTS (SELECT 1 FROM quote_revisions WHERE id=NEW.current_revision_id AND quote_id=NEW.id AND tenant_id=NEW.tenant_id AND sealed_at IS NOT NULL AND approval IN ('approved','automatic') AND expires_at > CURRENT_TIMESTAMP AND total_minor IS NOT NULL) THEN RAISE EXCEPTION 'Cannot accept an unissued or expired revision'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER valid_quote_transition BEFORE INSERT OR UPDATE ON quotes FOR EACH ROW EXECUTE FUNCTION guard_quote_transition();
CREATE OR REPLACE FUNCTION guard_price_book() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.status <> 'draft' AND (TG_OP='DELETE' OR (to_jsonb(NEW)-'status'-'updated_at') IS DISTINCT FROM (to_jsonb(OLD)-'status'-'updated_at') OR NEW.status <> 'retired') THEN RAISE EXCEPTION 'Published pricing is immutable'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER immutable_price_book BEFORE UPDATE OR DELETE ON price_books FOR EACH ROW EXECUTE FUNCTION guard_price_book();
CREATE OR REPLACE FUNCTION guard_pricing_rule() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE book bigint;
BEGIN
 book := CASE WHEN TG_OP='DELETE' THEN OLD.price_book_id ELSE NEW.price_book_id END;
 IF EXISTS (SELECT 1 FROM price_books WHERE id=book AND status <> 'draft') THEN RAISE EXCEPTION 'Published pricing rules are immutable'; END IF;
 IF TG_OP='UPDATE' AND OLD.price_book_id <> NEW.price_book_id THEN RAISE EXCEPTION 'Rule ownership is immutable'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER immutable_pricing_rule BEFORE INSERT OR UPDATE OR DELETE ON pricing_rules FOR EACH ROW EXECUTE FUNCTION guard_pricing_rule();
CREATE OR REPLACE FUNCTION guard_global_inventory() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.tenant_id IS NULL THEN RAISE EXCEPTION 'Global inventory definitions are immutable'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER immutable_global_inventory BEFORE UPDATE OR DELETE ON catalogue_items FOR EACH ROW EXECUTE FUNCTION guard_global_inventory();
SQL);
            foreach (['quote_inventory', 'route_stops', 'quote_line_items', 'pricing_calculations'] as $table) {
                DB::statement("CREATE TRIGGER immutable_revision_details BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION guard_quotation_child()");
            }
            foreach (['quote_review_decisions', 'quote_status_history'] as $table) {
                DB::statement("CREATE TRIGGER immutable_quote_history BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation()");
            }
        }
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $t) => $t->dropForeign(['tenant_id', 'id', 'current_revision_id']));
        foreach (['quote_deliveries', 'quote_documents', 'quote_attachments', 'quote_status_history', 'quote_review_decisions', 'quote_line_items', 'pricing_calculations', 'route_stops', 'quote_inventory', 'quote_revisions', 'route_calculations', 'quotes', 'pricing_rules', 'price_books', 'quotation_policies', 'customers', 'catalogue_items'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('audit_entries', fn (Blueprint $t) => $t->dropColumn('details'));
        if (DB::getDriverName() === 'pgsql') {
            foreach (['guard_quotation_revision', 'guard_quotation_child', 'guard_quote_transition', 'guard_price_book', 'guard_pricing_rule', 'guard_global_inventory'] as $function) {
                DB::statement("DROP FUNCTION IF EXISTS {$function}()");
            }
        }
    }
};
