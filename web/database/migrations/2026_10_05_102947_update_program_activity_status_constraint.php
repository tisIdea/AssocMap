<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE program_activity_records
            DROP CONSTRAINT IF EXISTS program_activity_records_status_check
        ");

        DB::statement("
            ALTER TABLE program_activity_records
            ADD CONSTRAINT program_activity_records_status_check
            CHECK (
                status IN (
                    'planned',
                    'ongoing',
                    'completed',
                    'cancelled'
                )
            )
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE program_activity_records
            DROP CONSTRAINT IF EXISTS program_activity_records_status_check
        ");

        DB::statement("
            ALTER TABLE program_activity_records
            ADD CONSTRAINT program_activity_records_status_check
            CHECK (
                status IN (
                    'planned',
                    'completed',
                    'cancelled'
                )
            )
        ");
    }
};