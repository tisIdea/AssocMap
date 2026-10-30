<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait CreatesAccountLifecycleSchema
{
    // SQLite fixture equivalents of lifecycle migrations 020, 021 and 022.
    protected function createReactivationSchema(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
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

    protected function createVerifiedEmailChangeSchema(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
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

    protected function createAssociationReplacementSchema(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getConfig('database'));
        if (! Schema::hasColumn('association_accounts', 'ended_at')) {
            Schema::table('association_accounts', function (Blueprint $table) {
                $table->timestampTz('ended_at')->nullable();
                $table->foreignUuid('ended_by')->nullable()->constrained('user_profiles')->nullOnDelete();
            });
        }
        DB::statement('DROP INDEX IF EXISTS association_accounts_association_id_unique');
        DB::statement('DROP INDEX IF EXISTS association_accounts_user_id_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_association_accounts_active_association ON association_accounts(association_id) WHERE is_active = 1');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_association_accounts_active_user ON association_accounts(user_id) WHERE is_active = 1');
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS uq_user_invitations_unresolved_association ON user_invitations(association_id) WHERE association_id IS NOT NULL AND status IN ('pending', 'processing', 'sent', 'failed')");
    }
}
