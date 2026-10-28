<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sql = file_get_contents(base_path('../supabase/migrations/024_add_gis_site_types.sql'));
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared($sql);

            return;
        }
        if (DB::getDriverName() !== 'sqlite' || ! app()->environment('testing')) {
            throw new LogicException('GIS classification requires PostgreSQL.');
        }
        $sql = explode('-- POSTGRES SECURITY', $sql)[0];
        $sql = preg_replace('/^SET LOCAL [^\n]+\n/m', '', $sql);
        $sql = str_replace(['BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY', " CHECK (code ~ '^[a-z][a-z0-9_]{0,49}$')", 'public.'],
            ['INTEGER PRIMARY KEY AUTOINCREMENT', '', ''], $sql);
        DB::unprepared($sql);
    }

    public function down(): void
    {
        throw new LogicException('GIS classifications require a reviewed forward correction.');
    }
};
