<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->string('demonstration_key', 60)->nullable();
            $table->unique(['tenant_id', 'demonstration_key']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'demonstration_key']);
            $table->dropColumn('demonstration_key');
        });
    }
};
