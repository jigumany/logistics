<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    private function ref(Blueprint $t, string $field, string $table, bool $nullable = false): void
    {
        $column = $t->unsignedBigInteger($field);
        if ($nullable) {
            $column->nullable();
        }
        $t->foreign(['tenant_id', $field])->references(['tenant_id', 'id'])->on($table)->restrictOnDelete();
    }

    private function intervals(Blueprint $t): void
    {
        foreach (['arrival_starts_at', 'arrival_ends_at', 'service_starts_at', 'service_ends_at', 'allocation_starts_at', 'allocation_ends_at'] as $field) {
            $t->timestampTz($field);
        }
        $t->json('requirements');
        $t->json('duration_snapshot');
    }

    public function up(): void
    {
        Schema::table('company_settings', fn (Blueprint $t) => $t->json('booking_policy')->nullable());
        Schema::table('tenant_services', fn (Blueprint $t) => $t->json('booking_rules')->nullable());
        Schema::create('bookings', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'quote_id', 'quotes');
            $this->ref($t, 'accepted_revision_id', 'quote_revisions');
            $this->ref($t, 'customer_id', 'customers');
            $this->ref($t, 'service_id', 'tenant_services');
            $t->foreign(['tenant_id', 'quote_id', 'accepted_revision_id'])->references(['tenant_id', 'quote_id', 'id'])->on('quote_revisions')->restrictOnDelete();
            $t->string('reference', 32);
            $t->string('status', 30)->default('pending_confirmation');
            $t->string('financial_state', 30)->default('not_required');
            $t->unsignedBigInteger('required_payment_minor')->default(0);
            $t->string('currency', 3);
            $t->string('payment_reference', 160)->nullable();
            $t->unique(['tenant_id', 'payment_reference']);
            $t->string('dispatch_state', 30)->default('blocked');
            $t->string('refund_state', 30)->default('none');
            $this->intervals($t);
            $t->json('policy_snapshot');
            $t->unsignedBigInteger('lock_version')->default(0);
            $t->unique(['tenant_id', 'quote_id']);
            $t->unique(['tenant_id', 'reference']);
            $t->index(['tenant_id', 'status', 'service_starts_at']);
            $t->index(['tenant_id', 'service_id', 'service_starts_at']);
        });
        Schema::create('booking_holds', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'quote_id', 'quotes');
            $this->ref($t, 'accepted_revision_id', 'quote_revisions');
            $this->ref($t, 'customer_id', 'customers');
            $this->ref($t, 'booking_id', 'bookings', true);
            $t->foreign(['tenant_id', 'quote_id', 'accepted_revision_id'])->references(['tenant_id', 'quote_id', 'id'])->on('quote_revisions')->restrictOnDelete();
            $t->string('purpose', 20)->default('initial');
            $t->string('status', 20)->default('active');
            $t->uuid('idempotency_key');
            $t->string('request_hash', 64);
            $t->timestampTz('expires_at');
            $this->intervals($t);
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'status', 'expires_at']);
            $t->index(['tenant_id', 'customer_id', 'created_at']);
        });
        DB::statement("CREATE UNIQUE INDEX booking_one_active_hold ON booking_holds (tenant_id,quote_id) WHERE status='active'");
        Schema::create('booking_hold_resources', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'hold_id', 'booking_holds');
            $this->ref($t, 'resource_id', 'resources');
            $t->unique(['tenant_id', 'hold_id', 'resource_id']);
        });
        Schema::create('resource_allocations', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'resource_id', 'resources');
            $this->ref($t, 'hold_id', 'booking_holds');
            $this->ref($t, 'booking_id', 'bookings', true);
            $t->string('status', 20)->default('held');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->index(['tenant_id', 'hold_id', 'resource_id']);
            $t->index(['tenant_id', 'status', 'starts_at', 'ends_at']);
            $t->index(['tenant_id', 'resource_id', 'starts_at', 'ends_at']);
            $t->index(['tenant_id', 'booking_id', 'status']);
        });
        Schema::create('booking_status_history', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'booking_id', 'bookings');
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30);
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->index(['tenant_id', 'booking_id', 'id']);
        });
        Schema::create('booking_change_requests', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'booking_id', 'bookings', true);
            $this->ref($t, 'quote_id', 'quotes');
            $t->uuid('idempotency_key');
            $t->string('request_hash', 64);
            $t->string('kind', 30);
            $t->string('outcome', 30);
            $t->text('reason');
            $t->json('details');
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'kind', 'created_at']);
        });
        Schema::create('booking_deliveries', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'quote_id', 'quotes');
            $t->ulid('event_id')->unique();
            $t->string('event_type', 50);
            $t->string('status', 20)->default('pending');
            $t->timestampTz('sent_at')->nullable();
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        foreach (['bookings', 'booking_holds', 'resource_allocations', 'booking_hold_resources', 'booking_status_history', 'booking_change_requests', 'booking_deliveries'] as $table) {
            DB::statement("CREATE TRIGGER tenant_ownership_immutable BEFORE UPDATE OF tenant_id ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_tenant_reassignment()");
        }
        foreach (['bookings', 'booking_holds'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_ordered_intervals CHECK (allocation_starts_at <= service_starts_at AND service_starts_at = arrival_starts_at AND arrival_starts_at < arrival_ends_at AND arrival_ends_at <= service_ends_at AND service_ends_at <= allocation_ends_at)");
        }
        foreach (['bookings', 'booking_holds'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_required_counts CHECK ((requirements->>'vehicles')::int BETWEEN 1 AND 10 AND (requirements->>'drivers')::int >= (requirements->>'vehicles')::int AND (requirements->>'drivers')::int <= 10 AND (requirements->>'movers')::int BETWEEN 0 AND 40 AND requirements->>'vehicles' IS NOT NULL AND requirements->>'drivers' IS NOT NULL AND requirements->>'movers' IS NOT NULL)");
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE resource_allocations ADD CONSTRAINT allocation_interval_valid CHECK (starts_at < ends_at);
ALTER TABLE resource_allocations ADD CONSTRAINT allocation_state_valid CHECK (status IN ('held','confirmed','released') AND (status <> 'confirmed' OR booking_id IS NOT NULL));
ALTER TABLE resource_allocations ADD CONSTRAINT resource_allocation_no_overlap EXCLUDE USING gist (tenant_id WITH =, resource_id WITH =, tstzrange(starts_at,ends_at,'[)') WITH &&) WHERE (status IN ('held','confirmed'));
ALTER TABLE booking_holds ADD CONSTRAINT hold_state_valid CHECK (status IN ('active','consumed','expired','released') AND purpose IN ('initial','reschedule') AND expires_at > created_at);
ALTER TABLE bookings ADD CONSTRAINT booking_state_valid CHECK (status IN ('pending_confirmation','pending_payment','confirmed','cancelled','expired') AND financial_state IN ('not_required','awaiting_verification','verified','manual_resolution') AND dispatch_state IN ('blocked','awaiting_dispatch') AND refund_state IN ('none','review_required'));
CREATE OR REPLACE FUNCTION guard_booking_record() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Booking records are retained'; END IF;
 IF TG_OP='UPDATE' THEN
  IF (OLD.quote_id,OLD.accepted_revision_id,OLD.customer_id,OLD.service_id) IS DISTINCT FROM (NEW.quote_id,NEW.accepted_revision_id,NEW.customer_id,NEW.service_id) THEN RAISE EXCEPTION 'Accepted booking scope is immutable'; END IF;
  IF OLD.status IN ('cancelled','expired') AND NEW.status IS DISTINCT FROM OLD.status THEN RAISE EXCEPTION 'Closed bookings cannot be reopened'; END IF;
  IF OLD.status='confirmed' AND NEW.status NOT IN ('confirmed','cancelled') THEN RAISE EXCEPTION 'Invalid confirmed booking transition'; END IF;
 END IF;
 IF NOT EXISTS (SELECT 1 FROM quotes q JOIN quote_revisions r ON r.id=NEW.accepted_revision_id AND r.quote_id=q.id AND r.tenant_id=q.tenant_id WHERE q.id=NEW.quote_id AND q.tenant_id=NEW.tenant_id AND q.customer_id=NEW.customer_id AND q.service_id=NEW.service_id AND q.status='accepted' AND q.simulation_price_book_id IS NULL AND q.current_revision_id=r.id AND r.sealed_at IS NOT NULL AND r.total_minor IS NOT NULL) THEN RAISE EXCEPTION 'A booking requires its current accepted customer quotation'; END IF;
 IF NEW.status='confirmed' AND NEW.financial_state NOT IN ('not_required','verified') THEN RAISE EXCEPTION 'Payment policy is unsatisfied'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_booking_record BEFORE INSERT OR UPDATE OR DELETE ON bookings FOR EACH ROW EXECUTE FUNCTION guard_booking_record();
CREATE OR REPLACE FUNCTION guard_booking_hold() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Holds are retained'; END IF;
 IF TG_OP='UPDATE' THEN
  IF (OLD.quote_id,OLD.accepted_revision_id,OLD.customer_id,OLD.expires_at,OLD.allocation_starts_at,OLD.allocation_ends_at,OLD.requirements::jsonb,OLD.idempotency_key,OLD.request_hash) IS DISTINCT FROM (NEW.quote_id,NEW.accepted_revision_id,NEW.customer_id,NEW.expires_at,NEW.allocation_starts_at,NEW.allocation_ends_at,NEW.requirements::jsonb,NEW.idempotency_key,NEW.request_hash) THEN RAISE EXCEPTION 'Hold identity, interval and expiry are immutable'; END IF;
  IF OLD.status<>'active' AND NEW.status IS DISTINCT FROM OLD.status THEN RAISE EXCEPTION 'A closed hold cannot be reused'; END IF;
 END IF;
 IF NEW.status='active' AND NOT EXISTS (SELECT 1 FROM quotes q JOIN quote_revisions r ON r.id=NEW.accepted_revision_id AND r.quote_id=q.id WHERE q.id=NEW.quote_id AND q.tenant_id=NEW.tenant_id AND q.customer_id=NEW.customer_id AND q.status='accepted' AND q.simulation_price_book_id IS NULL AND q.current_revision_id=r.id AND r.expires_at>CURRENT_TIMESTAMP) THEN RAISE EXCEPTION 'A hold requires a current unexpired accepted quotation'; END IF;
 IF NEW.booking_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.id=NEW.booking_id AND b.tenant_id=NEW.tenant_id AND b.quote_id=NEW.quote_id AND b.accepted_revision_id=NEW.accepted_revision_id AND b.customer_id=NEW.customer_id) THEN RAISE EXCEPTION 'Replacement hold must match its booking'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_booking_hold BEFORE INSERT OR UPDATE OR DELETE ON booking_holds FOR EACH ROW EXECUTE FUNCTION guard_booking_hold();
CREATE OR REPLACE FUNCTION guard_resource_allocation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE h booking_holds;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Allocations are released, never deleted'; END IF;
 IF TG_OP='UPDATE' THEN
  IF (OLD.resource_id,OLD.hold_id,OLD.starts_at,OLD.ends_at) IS DISTINCT FROM (NEW.resource_id,NEW.hold_id,NEW.starts_at,NEW.ends_at) THEN RAISE EXCEPTION 'Release and replace allocations rather than changing their scope'; END IF;
  IF OLD.status='released' AND NEW.status<>'released' THEN RAISE EXCEPTION 'Released allocations cannot be reused'; END IF;
  IF OLD.status='confirmed' AND NEW.status='held' THEN RAISE EXCEPTION 'Confirmed allocations cannot become holds'; END IF;
 END IF;
 SELECT * INTO h FROM booking_holds WHERE id=NEW.hold_id AND tenant_id=NEW.tenant_id;
 IF NEW.starts_at<h.allocation_starts_at OR NEW.ends_at>h.allocation_ends_at OR NOT EXISTS(SELECT 1 FROM booking_hold_resources m WHERE m.hold_id=h.id AND m.resource_id=NEW.resource_id AND m.tenant_id=NEW.tenant_id) THEN RAISE EXCEPTION 'Allocation must belong to its hold resource set and interval'; END IF;
 IF NEW.booking_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM bookings b WHERE b.id=NEW.booking_id AND b.tenant_id=NEW.tenant_id AND b.quote_id=h.quote_id) THEN RAISE EXCEPTION 'Allocation booking must match its held quotation'; END IF;
 IF NEW.status='held' AND (h.status<>'active' OR h.expires_at<=CURRENT_TIMESTAMP) THEN RAISE EXCEPTION 'Expired or closed hold cannot allocate resources'; END IF;
 IF NEW.status='confirmed' AND (NEW.booking_id IS NULL OR NOT EXISTS (SELECT 1 FROM bookings b WHERE b.id=NEW.booking_id AND b.tenant_id=NEW.tenant_id AND b.quote_id=h.quote_id AND b.accepted_revision_id=h.accepted_revision_id AND b.status='confirmed')) THEN RAISE EXCEPTION 'Confirmed allocations require their matching confirmed booking'; END IF;
 IF NEW.status IN ('held','confirmed') AND NOT EXISTS (SELECT 1 FROM resources r LEFT JOIN vehicles v ON v.id=r.vehicle_id AND v.tenant_id=r.tenant_id LEFT JOIN staff_members s ON s.id=r.staff_id AND s.tenant_id=r.tenant_id WHERE r.id=NEW.resource_id AND r.tenant_id=NEW.tenant_id AND r.active AND ((r.kind='vehicle' AND v.capacity_verified AND v.capacity_verified_at IS NOT NULL AND v.demonstration_key IS NULL AND v.status='available' AND v.archived_at IS NULL) OR (r.kind IN ('driver','mover') AND s.active AND s.archived_at IS NULL AND s.engagement_status IN ('employed','contractor') AND s.role=r.kind))) THEN RAISE EXCEPTION 'An operational verified resource is required'; END IF;
 IF NEW.status IN ('held','confirmed') AND EXISTS (SELECT 1 FROM resource_blocks x WHERE x.tenant_id=NEW.tenant_id AND x.resource_id=NEW.resource_id AND x.starts_at<NEW.ends_at AND x.ends_at>NEW.starts_at) THEN RAISE EXCEPTION 'Resource is blocked'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER protect_resource_allocation BEFORE INSERT OR UPDATE OR DELETE ON resource_allocations FOR EACH ROW EXECUTE FUNCTION guard_resource_allocation();
CREATE OR REPLACE FUNCTION check_booking_allocations() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE bid bigint; hid bigint; b bookings; h booking_holds; wanted jsonb; actual jsonb;
BEGIN
 IF TG_TABLE_NAME='bookings' THEN bid:=NEW.id;
 ELSIF TG_TABLE_NAME='booking_holds' THEN hid:=NEW.id; bid:=NEW.booking_id;
 ELSIF TG_TABLE_NAME='booking_hold_resources' THEN hid:=NEW.hold_id; SELECT booking_id INTO bid FROM booking_holds WHERE id=hid;
 ELSE hid:=NEW.hold_id; bid:=NEW.booking_id; END IF;
 IF hid IS NOT NULL THEN
  SELECT * INTO h FROM booking_holds WHERE id=hid;
  IF h.status='active' THEN
   SELECT jsonb_build_object('vehicle',count(*) FILTER(WHERE r.kind='vehicle'),'driver',count(*) FILTER(WHERE r.kind='driver'),'mover',count(*) FILTER(WHERE r.kind='mover')) INTO actual FROM booking_hold_resources a JOIN resources r ON r.id=a.resource_id AND r.tenant_id=a.tenant_id WHERE a.hold_id=h.id AND a.tenant_id=h.tenant_id;
   wanted:=jsonb_build_object('vehicle',(h.requirements->>'vehicles')::int,'driver',(h.requirements->>'drivers')::int,'mover',(h.requirements->>'movers')::int);
   IF actual IS DISTINCT FROM wanted THEN RAISE EXCEPTION 'All hold resources must be reserved atomically'; END IF;
   IF EXISTS (SELECT 1 FROM booking_hold_resources m WHERE m.hold_id=h.id AND NOT COALESCE((SELECT range_agg(tstzrange(a.starts_at,a.ends_at,'[)')) @> tstzrange(h.allocation_starts_at,h.allocation_ends_at,'[)') FROM resource_allocations a WHERE a.resource_id=m.resource_id AND a.tenant_id=h.tenant_id AND ((a.hold_id=h.id AND a.status='held') OR (h.purpose='reschedule' AND a.booking_id=h.booking_id AND a.status='confirmed'))),false)) THEN RAISE EXCEPTION 'Hold intervals must be covered completely without overlapping allocations'; END IF;
  ELSIF EXISTS(SELECT 1 FROM resource_allocations WHERE hold_id=h.id AND status='held') THEN RAISE EXCEPTION 'Closed hold retains held resources'; END IF;
 END IF;
 IF bid IS NOT NULL THEN
  SELECT * INTO b FROM bookings WHERE id=bid;
  IF b.status='confirmed' THEN
   SELECT jsonb_build_object('vehicle',count(*) FILTER(WHERE r.kind='vehicle'),'driver',count(*) FILTER(WHERE r.kind='driver'),'mover',count(*) FILTER(WHERE r.kind='mover')) INTO actual FROM resource_allocations a JOIN resources r ON r.id=a.resource_id AND r.tenant_id=a.tenant_id WHERE a.booking_id=b.id AND a.tenant_id=b.tenant_id AND a.status='confirmed' AND a.starts_at=b.allocation_starts_at AND a.ends_at=b.allocation_ends_at;
   wanted:=jsonb_build_object('vehicle',(b.requirements->>'vehicles')::int,'driver',(b.requirements->>'drivers')::int,'mover',(b.requirements->>'movers')::int);
   IF actual IS DISTINCT FROM wanted THEN RAISE EXCEPTION 'A confirmed booking must have its complete exclusive resource set'; END IF;
  ELSIF b.status IN ('cancelled','expired') AND EXISTS(SELECT 1 FROM resource_allocations WHERE booking_id=b.id AND status IN ('held','confirmed')) THEN RAISE EXCEPTION 'Closed bookings must release resources'; END IF;
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER complete_booking_allocations AFTER INSERT OR UPDATE ON bookings DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_booking_allocations();
CREATE CONSTRAINT TRIGGER complete_hold_allocations AFTER INSERT OR UPDATE ON booking_holds DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_booking_allocations();
CREATE CONSTRAINT TRIGGER complete_hold_members AFTER INSERT OR UPDATE ON booking_hold_resources DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_booking_allocations();
CREATE TRIGGER immutable_hold_members BEFORE UPDATE OR DELETE ON booking_hold_resources FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation();
CREATE CONSTRAINT TRIGGER complete_resource_allocations AFTER INSERT OR UPDATE ON resource_allocations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_booking_allocations();
CREATE OR REPLACE FUNCTION protect_blocked_allocations() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM 1 FROM tenants WHERE id=NEW.tenant_id FOR UPDATE;
 IF EXISTS(SELECT 1 FROM resource_allocations a WHERE a.tenant_id=NEW.tenant_id AND a.resource_id=NEW.resource_id AND a.status IN ('held','confirmed') AND a.starts_at<NEW.ends_at AND a.ends_at>NEW.starts_at) THEN RAISE EXCEPTION 'Reschedule or release existing allocations before blocking this resource'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER prevent_block_allocation_conflict BEFORE INSERT OR UPDATE ON resource_blocks FOR EACH ROW EXECUTE FUNCTION protect_blocked_allocations();
CREATE TRIGGER immutable_booking_history BEFORE UPDATE OR DELETE ON booking_status_history FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation();
CREATE TRIGGER immutable_booking_changes BEFORE UPDATE OR DELETE ON booking_change_requests FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS prevent_block_allocation_conflict ON resource_blocks');
            foreach (['check_booking_allocations', 'guard_resource_allocation', 'guard_booking_hold', 'guard_booking_record', 'protect_blocked_allocations'] as $fn) {
                DB::statement("DROP FUNCTION IF EXISTS {$fn}() CASCADE");
            }
        }
        foreach (['booking_deliveries', 'booking_change_requests', 'booking_status_history', 'resource_allocations', 'booking_hold_resources', 'booking_holds', 'bookings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('tenant_services',fn (Blueprint $t) => $t->dropColumn('booking_rules'));
        Schema::table('company_settings',fn (Blueprint $t) => $t->dropColumn('booking_policy'));
    }
};
