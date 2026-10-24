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
            DB::unprepared(file_get_contents(base_path('../supabase/migrations/021_support_association_account_replacement.sql')));

            return;
        }
        if (DB::getDriverName() !== 'sqlite' || ! app()->environment('testing')) {
            throw new LogicException('Association replacement requires PostgreSQL.');
        }
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

    public function down(): void
    {
        throw new LogicException('Historical appointments require a reviewed forward correction.');
    }
};
