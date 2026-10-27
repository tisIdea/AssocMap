<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sql = file_get_contents(base_path('../supabase/migrations/023_add_media_photos.sql'));
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared($sql);

            return;
        }
        if (DB::getDriverName() !== 'sqlite' || ! app()->environment('testing')) {
            throw new LogicException('Media metadata requires PostgreSQL.');
        }
        // Test translation of the same schema, not a second migration definition.
        $sql = explode('-- POSTGRES SECURITY', $sql)[0];
        $sql = preg_replace("/^SET LOCAL [^\n]+\n/m", '', $sql);
        DB::unprepared(str_replace('public.', '', $sql));
    }

    public function down(): void
    {
        throw new LogicException('Media history and objects require a reviewed forward correction.');
    }
};
