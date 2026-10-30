<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GisSiteTypeSeeder extends Seeder
{
    public function run(): void
    {
        // Proposed project classifications pending final BFAR Region VII validation.
        $types = [
            'fish_cage' => 'Fish Cage',
            'fish_pen' => 'Fish Pen',
            'culture_pond' => 'Fishpond / Culture Pond',
            'seaweed_cultivation_site' => 'Seaweed Cultivation Site',
            'hatchery_nursery' => 'Hatchery / Nursery',
            'payao_installation' => 'Payao Installation',
            'post_harvest_facility' => 'Post-Harvest Facility',
        ];

        DB::transaction(function () use ($types): void {
            $codes = array_keys($types);
            $existingCodes = DB::table('gis_site_types')->whereIn('code', $codes)->pluck('code')->all();
            $missing = [];
            foreach ($types as $code => $name) {
                if (! in_array($code, $existingCodes, true)) {
                    $missing[] = ['code' => $code, 'name' => $name];
                }
            }

            if ($missing !== []) {
                // Concurrent inserts may claim a code; verify its label before committing.
                DB::table('gis_site_types')->insertOrIgnore($missing);
            }

            $catalog = DB::table('gis_site_types')->whereIn('code', $codes)
                ->orderBy('code')->sharedLock()->pluck('name', 'code');
            foreach ($types as $code => $name) {
                if ($catalog->get($code) !== $name) {
                    throw new RuntimeException('GIS site-type catalog conflict or missing row for code ['.$code.']. '
                        .'Existing rows were not changed. Review the catalog before retrying.');
                }
            }
        }, 3);
    }
}
