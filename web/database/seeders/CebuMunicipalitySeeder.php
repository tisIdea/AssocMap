<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CebuMunicipalitySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $localities = [
            ['name' => 'Alcantara', 'psgc_code' => '0702201000', 'locality_type' => 'municipality'],
            ['name' => 'Alcoy', 'psgc_code' => '0702202000', 'locality_type' => 'municipality'],
            ['name' => 'Alegria', 'psgc_code' => '0702203000', 'locality_type' => 'municipality'],
            ['name' => 'Aloguinsan', 'psgc_code' => '0702204000', 'locality_type' => 'municipality'],
            ['name' => 'Argao', 'psgc_code' => '0702205000', 'locality_type' => 'municipality'],
            ['name' => 'Asturias', 'psgc_code' => '0702206000', 'locality_type' => 'municipality'],
            ['name' => 'Badian', 'psgc_code' => '0702207000', 'locality_type' => 'municipality'],
            ['name' => 'Balamban', 'psgc_code' => '0702208000', 'locality_type' => 'municipality'],
            ['name' => 'Bantayan', 'psgc_code' => '0702209000', 'locality_type' => 'municipality'],
            ['name' => 'Barili', 'psgc_code' => '0702210000', 'locality_type' => 'municipality'],

            ['name' => 'City of Bogo', 'psgc_code' => '0702211000', 'locality_type' => 'city'],

            ['name' => 'Boljoon', 'psgc_code' => '0702212000', 'locality_type' => 'municipality'],
            ['name' => 'Borbon', 'psgc_code' => '0702213000', 'locality_type' => 'municipality'],

            ['name' => 'City of Carcar', 'psgc_code' => '0702214000', 'locality_type' => 'city'],

            ['name' => 'Carmen', 'psgc_code' => '0702215000', 'locality_type' => 'municipality'],
            ['name' => 'Catmon', 'psgc_code' => '0702216000', 'locality_type' => 'municipality'],
            ['name' => 'Compostela', 'psgc_code' => '0702218000', 'locality_type' => 'municipality'],
            ['name' => 'Consolacion', 'psgc_code' => '0702219000', 'locality_type' => 'municipality'],
            ['name' => 'Cordova', 'psgc_code' => '0702220000', 'locality_type' => 'municipality'],
            ['name' => 'Daanbantayan', 'psgc_code' => '0702221000', 'locality_type' => 'municipality'],
            ['name' => 'Dalaguete', 'psgc_code' => '0702222000', 'locality_type' => 'municipality'],

            ['name' => 'Danao City', 'psgc_code' => '0702223000', 'locality_type' => 'city'],

            ['name' => 'Dumanjug', 'psgc_code' => '0702224000', 'locality_type' => 'municipality'],
            ['name' => 'Ginatilan', 'psgc_code' => '0702225000', 'locality_type' => 'municipality'],
            ['name' => 'Liloan', 'psgc_code' => '0702227000', 'locality_type' => 'municipality'],
            ['name' => 'Madridejos', 'psgc_code' => '0702228000', 'locality_type' => 'municipality'],
            ['name' => 'Malabuyoc', 'psgc_code' => '0702229000', 'locality_type' => 'municipality'],
            ['name' => 'Medellin', 'psgc_code' => '0702231000', 'locality_type' => 'municipality'],
            ['name' => 'Minglanilla', 'psgc_code' => '0702232000', 'locality_type' => 'municipality'],
            ['name' => 'Moalboal', 'psgc_code' => '0702233000', 'locality_type' => 'municipality'],

            ['name' => 'City of Naga', 'psgc_code' => '0702234000', 'locality_type' => 'city'],

            ['name' => 'Oslob', 'psgc_code' => '0702235000', 'locality_type' => 'municipality'],
            ['name' => 'Pilar', 'psgc_code' => '0702236000', 'locality_type' => 'municipality'],
            ['name' => 'Pinamungajan', 'psgc_code' => '0702237000', 'locality_type' => 'municipality'],
            ['name' => 'Poro', 'psgc_code' => '0702238000', 'locality_type' => 'municipality'],
            ['name' => 'Ronda', 'psgc_code' => '0702239000', 'locality_type' => 'municipality'],
            ['name' => 'Samboan', 'psgc_code' => '0702240000', 'locality_type' => 'municipality'],
            ['name' => 'San Fernando', 'psgc_code' => '0702241000', 'locality_type' => 'municipality'],
            ['name' => 'San Francisco', 'psgc_code' => '0702242000', 'locality_type' => 'municipality'],
            ['name' => 'San Remigio', 'psgc_code' => '0702243000', 'locality_type' => 'municipality'],
            ['name' => 'Santa Fe', 'psgc_code' => '0702244000', 'locality_type' => 'municipality'],
            ['name' => 'Santander', 'psgc_code' => '0702245000', 'locality_type' => 'municipality'],
            ['name' => 'Sibonga', 'psgc_code' => '0702246000', 'locality_type' => 'municipality'],
            ['name' => 'Sogod', 'psgc_code' => '0702247000', 'locality_type' => 'municipality'],
            ['name' => 'Tabogon', 'psgc_code' => '0702248000', 'locality_type' => 'municipality'],
            ['name' => 'Tabuelan', 'psgc_code' => '0702249000', 'locality_type' => 'municipality'],

            ['name' => 'City of Talisay', 'psgc_code' => '0702250000', 'locality_type' => 'city'],
            ['name' => 'City of Toledo', 'psgc_code' => '0702251000', 'locality_type' => 'city'],

            ['name' => 'Tuburan', 'psgc_code' => '0702252000', 'locality_type' => 'municipality'],
            ['name' => 'Tudela', 'psgc_code' => '0702253000', 'locality_type' => 'municipality'],
        ];

        $rows = array_map(
            fn (array $locality) => [
                ...$locality,
                'province' => 'Cebu',
                'region' => 'Region VII',
                'source' => 'psgc',
                'is_active' => true,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $localities
        );

        DB::table('municipalities')->upsert(
            $rows,
            ['psgc_code'],
            [
                'name',
                'province',
                'region',
                'locality_type',
                'source',
                'is_active',
                'updated_at',
            ]
        );
    }
}