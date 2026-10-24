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
            DB::unprepared(file_get_contents(base_path('../supabase/migrations/022_support_verified_email_change.sql')));

            return;
        }
        if (DB::getDriverName() !== 'sqlite' || ! app()->environment('testing')) {
            throw new LogicException('Verified email changes require PostgreSQL.');
        }
        Schema::table('email_change_requests', function (Blueprint $table) {
            $table->string('phase', 32)->default('legacy');
            $table->foreignId('role_id')->nullable()->constrained('roles');
            $table->char('reference_hash', 64)->nullable()->unique();
            $table->char('scope_hash', 64)->nullable();
            $table->char('browser_hash', 64)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('first_confirmation_at')->nullable();
            $table->timestampTz('password_confirmed_at')->nullable();
            $table->timestampTz('signout_confirmed_at')->nullable();
        });
        DB::statement("CREATE UNIQUE INDEX uq_email_change_pending_destination ON email_change_requests(lower(new_email)) WHERE status = 'pending'");
        Schema::create('email_change_proofs', function (Blueprint $table) {
            $table->char('proof_hash', 64)->primary();
            $table->foreignUuid('request_id')->constrained('email_change_requests');
            $table->timestampsTz();
        });
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('approved_email')->nullable();
            $table->foreignUuid('email_change_hold_id')->nullable()->constrained('email_change_requests');
        });
    }

    public function down(): void
    {
        throw new LogicException('Email bindings and session cutoffs require a reviewed forward correction.');
    }
};
