<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_invitations', function (Blueprint $t): void {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('staff_id');
            $t->foreign(['tenant_id', 'staff_id'])->references(['tenant_id', 'id'])->on('staff_members')->restrictOnDelete();
            $t->string('email', 254);
            $t->string('token_hash', 64)->unique();
            $t->timestampTz('expires_at');
            $t->timestampTz('accepted_at')->nullable();
            $t->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->index(['tenant_id', 'staff_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE TRIGGER tenant_ownership_immutable BEFORE UPDATE OF tenant_id ON driver_invitations FOR EACH ROW EXECUTE FUNCTION reject_tenant_reassignment()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_invitations');
    }
};
