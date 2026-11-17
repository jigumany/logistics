<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        }
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 160);
            $table->string('slug', 100)->unique();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('role', ['owner', 'admin', 'dispatcher', 'driver', 'viewer']);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['user_id', 'tenant_id']);
        });
        Schema::create('company_settings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->string('display_name', 160);
            $table->string('contact_email', 254);
            $table->string('contact_phone', 30)->nullable();
            $table->string('currency', 3)->default('ZAR');
            $table->string('timezone', 64)->default('Africa/Johannesburg');
            $table->enum('payment_terms', ['deposit', 'full', 'invoice'])->default('deposit');
            $table->unsignedSmallInteger('deposit_basis_points')->default(2500);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
        });
        Schema::create('service_types', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->enum('code', ['household', 'office', 'general']);
            $table->string('name', 100);
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 100);
            $table->ulid('subject_public_id');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'actor_id'])->references(['tenant_id', 'user_id'])->on('memberships')->restrictOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 100);
            $table->json('payload');
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['published_at', 'id']);
            $table->index(['tenant_id', 'id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE company_settings ADD CONSTRAINT valid_deposit CHECK (deposit_basis_points BETWEEN 0 AND 10000 AND (payment_terms <> 'deposit' OR deposit_basis_points > 0))");
            DB::statement("ALTER TABLE company_settings ADD CONSTRAINT zar_currency CHECK (currency = 'ZAR')");
            DB::unprepared("CREATE OR REPLACE FUNCTION reject_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Audit entries are immutable'; END $$; CREATE TRIGGER audit_entries_immutable BEFORE UPDATE OR DELETE ON audit_entries FOR EACH ROW EXECUTE FUNCTION reject_audit_mutation();");
        }
    }

    public function down(): void
    {
        foreach (['outbox_messages', 'audit_entries', 'service_types', 'company_settings', 'memberships', 'tenants'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS reject_audit_mutation()');
        }
    }
};
