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
            DB::unprepared(file_get_contents(base_path('../supabase/migrations/020_add_account_reactivation.sql')));

            return;
        }
        if (DB::getDriverName() !== 'sqlite' || ! app()->environment('testing')) {
            throw new LogicException('Account reactivation requires PostgreSQL.');
        }
        Schema::table('user_profiles', fn (Blueprint $table) => $table->timestampTz('auth_sessions_valid_after')->nullable());
        Schema::create('account_reactivations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('user_profiles');
            $table->foreignUuid('requested_by')->constrained('user_profiles');
            $table->foreignId('role_id')->constrained('roles');
            $table->string('email', 254);
            $table->char('reference_hash', 64)->unique();
            $table->char('review_hash', 64);
            $table->unsignedInteger('revocation_count');
            $table->string('state', 32);
            $table->timestampTz('expires_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        throw new LogicException('Session exclusion is a forward-only security boundary.');
    }
};
