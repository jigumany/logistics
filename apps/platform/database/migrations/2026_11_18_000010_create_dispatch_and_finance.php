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

    private function ref(Blueprint $t, string $field, string $table): void
    {
        $t->unsignedBigInteger($field);
        $t->foreign(['tenant_id', $field])->references(['tenant_id', 'id'])->on($table)->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::create('dispatch_jobs', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'booking_id', 'bookings');
            $t->unsignedBigInteger('booking_version');
            $t->string('status', 25)->default('assigned');
            $t->unsignedInteger('lock_version')->default(0);
            $t->json('resource_snapshot');
            $t->json('work_snapshot');
            $t->timestampTz('completed_at')->nullable();
            $t->index(['tenant_id', 'booking_id']);
        });
        DB::statement("CREATE UNIQUE INDEX dispatch_one_current_job ON dispatch_jobs (tenant_id,booking_id) WHERE status <> 'recalled'");
        Schema::create('dispatch_events', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'job_id', 'dispatch_jobs');
            $t->uuid('idempotency_key');
            $t->string('request_hash', 64);
            $t->string('from_status', 25)->nullable();
            $t->string('to_status', 25);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->text('note')->nullable();
            $t->unique(['tenant_id', 'idempotency_key']);
        });
        Schema::create('delivery_proofs', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'job_id', 'dispatch_jobs');
            $t->uuid('idempotency_key');
            $t->string('request_hash', 64);
            $t->string('recipient_name', 120);
            $t->boolean('recipient_acknowledged');
            $t->text('notes')->nullable();
            $t->string('photo_path');
            $t->string('sha256', 64);
            $t->string('mime_type', 80);
            $t->unsignedInteger('size_bytes');
            $t->foreignId('captured_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('captured_at');
            $t->unique(['tenant_id', 'job_id']);
            $t->unique(['tenant_id', 'idempotency_key']);
        });
        Schema::create('payment_accounts', function (Blueprint $t): void {
            $this->owned($t);
            $t->string('provider', 20);
            $t->string('mode', 10);
            $t->text('secret_key');
            $t->text('webhook_secret');
            $t->unique(['tenant_id', 'provider']);
        });
        Schema::create('payment_attempts', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'booking_id', 'bookings');
            $t->uuid('idempotency_key');
            $t->string('request_hash', 64);
            $t->string('purpose', 20);
            $t->string('mode', 10);
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3);
            $t->string('status', 25)->default('creating');
            $t->string('provider_checkout_id', 160)->nullable()->unique();
            $t->string('provider_payment_id', 160)->nullable()->unique();
            $t->text('redirect_url')->nullable();
            $t->json('checkout_request');
            $t->timestampTz('verified_at')->nullable();
            $t->unique(['tenant_id', 'idempotency_key']);
            $t->index(['tenant_id', 'booking_id', 'status']);
        });
        Schema::create('payment_webhook_events', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'attempt_id', 'payment_attempts');
            $t->string('provider_event_id', 160)->unique();
            $t->string('payload_hash', 64);
            $t->string('type', 40);
            $t->string('outcome', 30);
            $t->timestampTz('processed_at');
        });
        Schema::create('invoice_sequences', function (Blueprint $t): void {
            $t->foreignId('tenant_id')->primary()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('next_number')->default(1);
        });
        Schema::create('invoices', function (Blueprint $t): void {
            $this->owned($t);
            $this->ref($t, 'booking_id', 'bookings');
            $this->ref($t, 'revision_id', 'quote_revisions');
            $t->string('number', 40);
            $t->string('currency', 3);
            $t->unsignedBigInteger('subtotal_minor');
            $t->unsignedBigInteger('tax_minor');
            $t->unsignedBigInteger('total_minor');
            $t->json('supplier_snapshot');
            $t->json('customer_snapshot');
            $t->json('lines');
            $t->date('due_date');
            $t->timestampTz('issued_at');
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $t->unique(['tenant_id', 'booking_id']);
            $t->unique(['tenant_id', 'number']);
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['dispatch_jobs', 'dispatch_events', 'delivery_proofs', 'payment_attempts', 'payment_webhook_events', 'payment_accounts', 'invoices'] as $table) {
            DB::statement("CREATE TRIGGER tenant_ownership_immutable BEFORE UPDATE OF tenant_id ON {$table} FOR EACH ROW EXECUTE FUNCTION reject_tenant_reassignment()");
        }
        DB::unprepared(<<<'SQL'
 CREATE OR REPLACE FUNCTION phase5_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Financial and delivery evidence is immutable'; END $$;
 CREATE TRIGGER retain_dispatch_events BEFORE UPDATE OR DELETE ON dispatch_events FOR EACH ROW EXECUTE FUNCTION phase5_immutable();
 CREATE TRIGGER retain_delivery_proofs BEFORE UPDATE OR DELETE ON delivery_proofs FOR EACH ROW EXECUTE FUNCTION phase5_immutable();
 CREATE TRIGGER retain_payment_events BEFORE UPDATE OR DELETE ON payment_webhook_events FOR EACH ROW EXECUTE FUNCTION phase5_immutable();
 CREATE TRIGGER retain_invoices BEFORE UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION phase5_immutable();
 ALTER TABLE dispatch_jobs ADD CONSTRAINT dispatch_state_valid CHECK(status IN ('assigned','accepted','en_route','arrived','loading','in_transit','delivered','completed','recalled'));
 ALTER TABLE delivery_proofs ADD CONSTRAINT delivery_acknowledged CHECK(recipient_acknowledged AND size_bytes>0);
 ALTER TABLE payment_attempts ADD CONSTRAINT payment_values_valid CHECK(amount_minor>0 AND currency='ZAR' AND mode IN ('test','live') AND purpose IN ('reservation','balance') AND status IN ('creating','ready','failed','succeeded'));
 ALTER TABLE invoices ADD CONSTRAINT invoice_totals_valid CHECK(total_minor=subtotal_minor+tax_minor);
 CREATE OR REPLACE FUNCTION guard_phase5_job() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Dispatch jobs are retained'; END IF;
 IF TG_OP='INSERT' AND NEW.status<>'assigned' THEN RAISE EXCEPTION 'A job must begin assigned'; END IF;
 IF TG_OP='UPDATE' AND NEW.status IS DISTINCT FROM OLD.status AND (NEW.lock_version<>OLD.lock_version+1 OR NOT (NEW.status=CASE OLD.status WHEN 'assigned' THEN 'accepted' WHEN 'accepted' THEN 'en_route' WHEN 'en_route' THEN 'arrived' WHEN 'arrived' THEN 'loading' WHEN 'loading' THEN 'in_transit' WHEN 'in_transit' THEN 'delivered' WHEN 'delivered' THEN 'completed' ELSE '' END OR (OLD.status IN ('assigned','accepted') AND NEW.status='recalled'))) THEN RAISE EXCEPTION 'Invalid job transition'; END IF;
 IF NOT EXISTS(SELECT 1 FROM bookings b WHERE b.tenant_id=NEW.tenant_id AND b.id=NEW.booking_id AND b.status='confirmed' AND b.lock_version=NEW.booking_version) THEN RAISE EXCEPTION 'Dispatch requires the unchanged confirmed booking'; END IF;
 IF TG_OP='UPDATE' AND (OLD.booking_id,OLD.booking_version,OLD.resource_snapshot::jsonb,OLD.work_snapshot::jsonb) IS DISTINCT FROM (NEW.booking_id,NEW.booking_version,NEW.resource_snapshot::jsonb,NEW.work_snapshot::jsonb) THEN RAISE EXCEPTION 'Dispatched scope is immutable'; END IF;
 IF NEW.status IN ('delivered','completed') AND NOT EXISTS(SELECT 1 FROM delivery_proofs p WHERE p.tenant_id=NEW.tenant_id AND p.job_id=NEW.id) THEN RAISE EXCEPTION 'Delivery proof is required'; END IF;
 RETURN NEW; END $$;
 CREATE TRIGGER protect_phase5_job BEFORE INSERT OR UPDATE OR DELETE ON dispatch_jobs FOR EACH ROW EXECUTE FUNCTION guard_phase5_job();
 CREATE OR REPLACE FUNCTION protect_dispatched_booking() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF EXISTS(SELECT 1 FROM dispatch_jobs j WHERE j.tenant_id=OLD.tenant_id AND j.booking_id=OLD.id AND j.status<>'recalled') AND (OLD.status,OLD.lock_version) IS DISTINCT FROM (NEW.status,NEW.lock_version) THEN RAISE EXCEPTION 'Dispatched bookings require operational resolution before changes'; END IF;
 RETURN NEW; END $$;
 CREATE TRIGGER booking_dispatched_scope BEFORE UPDATE ON bookings FOR EACH ROW EXECUTE FUNCTION protect_dispatched_booking();
 CREATE OR REPLACE FUNCTION guard_payment_attempt() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Payment attempts are retained'; END IF;
 IF (OLD.tenant_id,OLD.booking_id,OLD.idempotency_key,OLD.amount_minor,OLD.currency,OLD.mode,OLD.checkout_request::jsonb) IS DISTINCT FROM (NEW.tenant_id,NEW.booking_id,NEW.idempotency_key,NEW.amount_minor,NEW.currency,NEW.mode,NEW.checkout_request::jsonb) THEN RAISE EXCEPTION 'Payment identity is immutable'; END IF;
 IF OLD.status='succeeded' AND NEW IS DISTINCT FROM OLD THEN RAISE EXCEPTION 'Succeeded payments are immutable'; END IF;
 RETURN NEW; END $$;
 CREATE TRIGGER payment_attempt_identity BEFORE UPDATE OR DELETE ON payment_attempts FOR EACH ROW EXECUTE FUNCTION guard_payment_attempt();
 CREATE OR REPLACE FUNCTION guard_issued_invoice() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF NOT EXISTS(SELECT 1 FROM bookings b JOIN quote_revisions r ON r.id=b.accepted_revision_id AND r.tenant_id=b.tenant_id WHERE b.id=NEW.booking_id AND b.tenant_id=NEW.tenant_id AND b.status='confirmed' AND r.id=NEW.revision_id AND r.total_minor=NEW.total_minor AND r.subtotal_minor=NEW.subtotal_minor AND r.tax_minor=NEW.tax_minor AND b.currency=NEW.currency) THEN RAISE EXCEPTION 'Invoice must retain accepted booking totals'; END IF;
 IF (SELECT coalesce(sum((v->>'amount_minor')::bigint),0) FROM jsonb_array_elements(NEW.lines::jsonb) v)<>NEW.total_minor THEN RAISE EXCEPTION 'Invoice lines must reconcile to the total'; END IF;
 RETURN NEW; END $$;
 CREATE TRIGGER invoice_accepted_totals BEFORE INSERT ON invoices FOR EACH ROW EXECUTE FUNCTION guard_issued_invoice();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS booking_dispatched_scope ON bookings');
        }
        foreach (['invoices', 'invoice_sequences', 'payment_webhook_events', 'payment_attempts', 'payment_accounts', 'delivery_proofs', 'dispatch_events', 'dispatch_jobs'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach (['phase5_immutable', 'guard_phase5_job', 'protect_dispatched_booking', 'guard_payment_attempt', 'guard_issued_invoice'] as $f) {
                DB::statement("DROP FUNCTION IF EXISTS {$f}()");
            }
        }
    }
};
