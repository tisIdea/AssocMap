<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CebuBarangaySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        /*
         * Official PSGC barangays belonging only to the
         * SAAD-covered Cebu municipalities.
         *
         * municipality_psgc_code is used to find the UUID
         * generated in our municipalities table.
         */
        $barangays = [
            // Alcantara — 0702201000
['name' => 'Cabadiangan', 'psgc_code' => '0702201001', 'municipality_psgc_code' => '0702201000'],
['name' => 'Cabil-isan', 'psgc_code' => '0702201002', 'municipality_psgc_code' => '0702201000'],
['name' => 'Candabong', 'psgc_code' => '0702201003', 'municipality_psgc_code' => '0702201000'],
['name' => 'Lawaan', 'psgc_code' => '0702201004', 'municipality_psgc_code' => '0702201000'],
['name' => 'Manga', 'psgc_code' => '0702201005', 'municipality_psgc_code' => '0702201000'],
['name' => 'Palanas', 'psgc_code' => '0702201006', 'municipality_psgc_code' => '0702201000'],
['name' => 'Poblacion', 'psgc_code' => '0702201007', 'municipality_psgc_code' => '0702201000'],
['name' => 'Polo', 'psgc_code' => '0702201008', 'municipality_psgc_code' => '0702201000'],
['name' => 'Salagmaya', 'psgc_code' => '0702201009', 'municipality_psgc_code' => '0702201000'],

// Alcoy — 0702202000
['name' => 'Atabay', 'psgc_code' => '0702202001', 'municipality_psgc_code' => '0702202000'],
['name' => 'Daan-Lungsod', 'psgc_code' => '0702202002', 'municipality_psgc_code' => '0702202000'],
['name' => 'Guiwang', 'psgc_code' => '0702202003', 'municipality_psgc_code' => '0702202000'],
['name' => 'Nug-as', 'psgc_code' => '0702202004', 'municipality_psgc_code' => '0702202000'],
['name' => 'Pasol', 'psgc_code' => '0702202005', 'municipality_psgc_code' => '0702202000'],
['name' => 'Poblacion', 'psgc_code' => '0702202006', 'municipality_psgc_code' => '0702202000'],
['name' => 'Pugalo', 'psgc_code' => '0702202007', 'municipality_psgc_code' => '0702202000'],
['name' => 'San Agustin', 'psgc_code' => '0702202008', 'municipality_psgc_code' => '0702202000'],

// Badian — 0702207000
['name' => 'Alawijao', 'psgc_code' => '0702207001', 'municipality_psgc_code' => '0702207000'],
['name' => 'Balhaan', 'psgc_code' => '0702207002', 'municipality_psgc_code' => '0702207000'],
['name' => 'Banhigan', 'psgc_code' => '0702207003', 'municipality_psgc_code' => '0702207000'],
['name' => 'Basak', 'psgc_code' => '0702207004', 'municipality_psgc_code' => '0702207000'],
['name' => 'Basiao', 'psgc_code' => '0702207005', 'municipality_psgc_code' => '0702207000'],
['name' => 'Bato', 'psgc_code' => '0702207006', 'municipality_psgc_code' => '0702207000'],
['name' => 'Bugas', 'psgc_code' => '0702207007', 'municipality_psgc_code' => '0702207000'],
['name' => 'Calangcang', 'psgc_code' => '0702207008', 'municipality_psgc_code' => '0702207000'],
['name' => 'Candiis', 'psgc_code' => '0702207009', 'municipality_psgc_code' => '0702207000'],
['name' => 'Dagatan', 'psgc_code' => '0702207011', 'municipality_psgc_code' => '0702207000'],
['name' => 'Dobdob', 'psgc_code' => '0702207012', 'municipality_psgc_code' => '0702207000'],
['name' => 'Ginablan', 'psgc_code' => '0702207013', 'municipality_psgc_code' => '0702207000'],
['name' => 'Lambug', 'psgc_code' => '0702207014', 'municipality_psgc_code' => '0702207000'],
['name' => 'Malabago', 'psgc_code' => '0702207015', 'municipality_psgc_code' => '0702207000'],
['name' => 'Malhiao', 'psgc_code' => '0702207016', 'municipality_psgc_code' => '0702207000'],
['name' => 'Manduyong', 'psgc_code' => '0702207017', 'municipality_psgc_code' => '0702207000'],
['name' => 'Matutinao', 'psgc_code' => '0702207018', 'municipality_psgc_code' => '0702207000'],
['name' => 'Patong', 'psgc_code' => '0702207019', 'municipality_psgc_code' => '0702207000'],
['name' => 'Poblacion', 'psgc_code' => '0702207020', 'municipality_psgc_code' => '0702207000'],
['name' => 'Sanlagan', 'psgc_code' => '0702207021', 'municipality_psgc_code' => '0702207000'],
['name' => 'Santicon', 'psgc_code' => '0702207022', 'municipality_psgc_code' => '0702207000'],
['name' => 'Sohoton', 'psgc_code' => '0702207023', 'municipality_psgc_code' => '0702207000'],
['name' => 'Sulsugan', 'psgc_code' => '0702207024', 'municipality_psgc_code' => '0702207000'],
['name' => 'Talayong', 'psgc_code' => '0702207025', 'municipality_psgc_code' => '0702207000'],
['name' => 'Taytay', 'psgc_code' => '0702207026', 'municipality_psgc_code' => '0702207000'],
['name' => 'Tigbao', 'psgc_code' => '0702207027', 'municipality_psgc_code' => '0702207000'],
['name' => 'Tiguib', 'psgc_code' => '0702207028', 'municipality_psgc_code' => '0702207000'],
['name' => 'Tubod', 'psgc_code' => '0702207029', 'municipality_psgc_code' => '0702207000'],
['name' => 'Zaragosa', 'psgc_code' => '0702207030', 'municipality_psgc_code' => '0702207000'],

// Boljoon — 0702212000
['name' => 'Baclayan', 'psgc_code' => '0702212001', 'municipality_psgc_code' => '0702212000'],
['name' => 'El Pardo', 'psgc_code' => '0702212002', 'municipality_psgc_code' => '0702212000'],
['name' => 'Granada', 'psgc_code' => '0702212003', 'municipality_psgc_code' => '0702212000'],
['name' => 'Lower Becerril', 'psgc_code' => '0702212004', 'municipality_psgc_code' => '0702212000'],
['name' => 'Poblacion', 'psgc_code' => '0702212005', 'municipality_psgc_code' => '0702212000'],
['name' => 'San Antonio', 'psgc_code' => '0702212006', 'municipality_psgc_code' => '0702212000'],
['name' => 'Upper Becerril', 'psgc_code' => '0702212007', 'municipality_psgc_code' => '0702212000'],
['name' => 'Arbor', 'psgc_code' => '0702212008', 'municipality_psgc_code' => '0702212000'],
['name' => 'Lunop', 'psgc_code' => '0702212009', 'municipality_psgc_code' => '0702212000'],
['name' => 'Nangka', 'psgc_code' => '0702212010', 'municipality_psgc_code' => '0702212000'],
['name' => 'South Granada', 'psgc_code' => '0702212011', 'municipality_psgc_code' => '0702212000'],

// Ginatilan — 0702225000
['name' => 'Anao', 'psgc_code' => '0702225001', 'municipality_psgc_code' => '0702225000'],
['name' => 'Cagsing', 'psgc_code' => '0702225002', 'municipality_psgc_code' => '0702225000'],
['name' => 'Calabawan', 'psgc_code' => '0702225003', 'municipality_psgc_code' => '0702225000'],
['name' => 'Cambagte', 'psgc_code' => '0702225004', 'municipality_psgc_code' => '0702225000'],
['name' => 'Campisong', 'psgc_code' => '0702225005', 'municipality_psgc_code' => '0702225000'],
['name' => 'Canorong', 'psgc_code' => '0702225006', 'municipality_psgc_code' => '0702225000'],
['name' => 'Guiwanon', 'psgc_code' => '0702225007', 'municipality_psgc_code' => '0702225000'],
['name' => 'Looc', 'psgc_code' => '0702225008', 'municipality_psgc_code' => '0702225000'],
['name' => 'Malatbo', 'psgc_code' => '0702225009', 'municipality_psgc_code' => '0702225000'],
['name' => 'Mangaco', 'psgc_code' => '0702225010', 'municipality_psgc_code' => '0702225000'],
['name' => 'Palanas', 'psgc_code' => '0702225011', 'municipality_psgc_code' => '0702225000'],
['name' => 'Poblacion', 'psgc_code' => '0702225012', 'municipality_psgc_code' => '0702225000'],
['name' => 'Salamanca', 'psgc_code' => '0702225013', 'municipality_psgc_code' => '0702225000'],
['name' => 'San Roque', 'psgc_code' => '0702225014', 'municipality_psgc_code' => '0702225000'],
        
// Samboan — 0702240000
['name' => 'Basak', 'psgc_code' => '0702240001', 'municipality_psgc_code' => '0702240000'],
['name' => 'Bonbon', 'psgc_code' => '0702240002', 'municipality_psgc_code' => '0702240000'],
['name' => 'Bulangsuran', 'psgc_code' => '0702240003', 'municipality_psgc_code' => '0702240000'],
['name' => 'Calatagan', 'psgc_code' => '0702240004', 'municipality_psgc_code' => '0702240000'],
['name' => 'Cambigong', 'psgc_code' => '0702240005', 'municipality_psgc_code' => '0702240000'],
['name' => 'Canorong', 'psgc_code' => '0702240006', 'municipality_psgc_code' => '0702240000'],
['name' => 'Colase', 'psgc_code' => '0702240007', 'municipality_psgc_code' => '0702240000'],
['name' => 'Dalahikan', 'psgc_code' => '0702240008', 'municipality_psgc_code' => '0702240000'],
['name' => 'Jumangpas', 'psgc_code' => '0702240009', 'municipality_psgc_code' => '0702240000'],
['name' => 'Camburoy', 'psgc_code' => '0702240010', 'municipality_psgc_code' => '0702240000'],
['name' => 'Poblacion', 'psgc_code' => '0702240011', 'municipality_psgc_code' => '0702240000'],
['name' => 'San Sebastian', 'psgc_code' => '0702240012', 'municipality_psgc_code' => '0702240000'],
['name' => 'Suba', 'psgc_code' => '0702240013', 'municipality_psgc_code' => '0702240000'],
['name' => 'Tangbo', 'psgc_code' => '0702240014', 'municipality_psgc_code' => '0702240000'],
['name' => 'Monteverde', 'psgc_code' => '0702240015', 'municipality_psgc_code' => '0702240000'],

// San Francisco — 0702242000
['name' => 'Montealegre', 'psgc_code' => '0702242001', 'municipality_psgc_code' => '0702242000'],
['name' => 'Cabunga-an', 'psgc_code' => '0702242002', 'municipality_psgc_code' => '0702242000'],
['name' => 'Campo', 'psgc_code' => '0702242003', 'municipality_psgc_code' => '0702242000'],
['name' => 'Consuelo', 'psgc_code' => '0702242004', 'municipality_psgc_code' => '0702242000'],
['name' => 'Esperanza', 'psgc_code' => '0702242006', 'municipality_psgc_code' => '0702242000'],
['name' => 'Himensulan', 'psgc_code' => '0702242007', 'municipality_psgc_code' => '0702242000'],
['name' => 'Northern Poblacion', 'psgc_code' => '0702242008', 'municipality_psgc_code' => '0702242000'],
['name' => 'San Isidro', 'psgc_code' => '0702242009', 'municipality_psgc_code' => '0702242000'],
['name' => 'Santa Cruz', 'psgc_code' => '0702242010', 'municipality_psgc_code' => '0702242000'],
['name' => 'Santiago', 'psgc_code' => '0702242011', 'municipality_psgc_code' => '0702242000'],
['name' => 'Sonog', 'psgc_code' => '0702242012', 'municipality_psgc_code' => '0702242000'],
['name' => 'Southern Poblacion', 'psgc_code' => '0702242013', 'municipality_psgc_code' => '0702242000'],
['name' => 'Unidos', 'psgc_code' => '0702242014', 'municipality_psgc_code' => '0702242000'],
['name' => 'Union', 'psgc_code' => '0702242015', 'municipality_psgc_code' => '0702242000'],
['name' => 'Western Poblacion', 'psgc_code' => '0702242016', 'municipality_psgc_code' => '0702242000'],

// Malabuyoc — 0702229000
['name' => 'Armeña', 'psgc_code' => '0702229001', 'municipality_psgc_code' => '0702229000'],
['name' => 'Tolosa', 'psgc_code' => '0702229004', 'municipality_psgc_code' => '0702229000'],
['name' => 'Cerdeña', 'psgc_code' => '0702229005', 'municipality_psgc_code' => '0702229000'],
['name' => 'Labrador', 'psgc_code' => '0702229006', 'municipality_psgc_code' => '0702229000'],
['name' => 'Looc', 'psgc_code' => '0702229007', 'municipality_psgc_code' => '0702229000'],
['name' => 'Lombo', 'psgc_code' => '0702229008', 'municipality_psgc_code' => '0702229000'],
['name' => 'Mahanlud', 'psgc_code' => '0702229009', 'municipality_psgc_code' => '0702229000'],
['name' => 'Mindanao', 'psgc_code' => '0702229010', 'municipality_psgc_code' => '0702229000'],
['name' => 'Montañeza', 'psgc_code' => '0702229011', 'municipality_psgc_code' => '0702229000'],
['name' => 'Salmeron', 'psgc_code' => '0702229012', 'municipality_psgc_code' => '0702229000'],
['name' => 'Santo Niño', 'psgc_code' => '0702229013', 'municipality_psgc_code' => '0702229000'],
['name' => 'Sorsogon', 'psgc_code' => '0702229014', 'municipality_psgc_code' => '0702229000'],
['name' => 'Barangay I', 'psgc_code' => '0702229015', 'municipality_psgc_code' => '0702229000'],
['name' => 'Barangay II', 'psgc_code' => '0702229016', 'municipality_psgc_code' => '0702229000'],

// Pilar — 0702236000
['name' => 'Biasong', 'psgc_code' => '0702236001', 'municipality_psgc_code' => '0702236000'],
['name' => 'Cawit', 'psgc_code' => '0702236002', 'municipality_psgc_code' => '0702236000'],
['name' => 'Dapdap', 'psgc_code' => '0702236003', 'municipality_psgc_code' => '0702236000'],
['name' => 'Esperanza', 'psgc_code' => '0702236004', 'municipality_psgc_code' => '0702236000'],
['name' => 'Lanao', 'psgc_code' => '0702236005', 'municipality_psgc_code' => '0702236000'],
['name' => 'Lower Poblacion', 'psgc_code' => '0702236006', 'municipality_psgc_code' => '0702236000'],
['name' => 'Moabog', 'psgc_code' => '0702236007', 'municipality_psgc_code' => '0702236000'],
['name' => 'Montserrat', 'psgc_code' => '0702236008', 'municipality_psgc_code' => '0702236000'],
['name' => 'San Isidro', 'psgc_code' => '0702236011', 'municipality_psgc_code' => '0702236000'],
['name' => 'San Juan', 'psgc_code' => '0702236012', 'municipality_psgc_code' => '0702236000'],
['name' => 'Upper Poblacion', 'psgc_code' => '0702236013', 'municipality_psgc_code' => '0702236000'],
['name' => 'Villahermosa', 'psgc_code' => '0702236014', 'municipality_psgc_code' => '0702236000'],
['name' => 'Imelda', 'psgc_code' => '0702236015', 'municipality_psgc_code' => '0702236000'],

// Ronda — 0702239000
['name' => 'Butong', 'psgc_code' => '0702239001', 'municipality_psgc_code' => '0702239000'],
['name' => 'Can-abuhon', 'psgc_code' => '0702239002', 'municipality_psgc_code' => '0702239000'],
['name' => 'Canduling', 'psgc_code' => '0702239003', 'municipality_psgc_code' => '0702239000'],
['name' => 'Cansalonoy', 'psgc_code' => '0702239004', 'municipality_psgc_code' => '0702239000'],
['name' => 'Cansayahon', 'psgc_code' => '0702239005', 'municipality_psgc_code' => '0702239000'],
['name' => 'Ilaya', 'psgc_code' => '0702239006', 'municipality_psgc_code' => '0702239000'],
['name' => 'Langin', 'psgc_code' => '0702239007', 'municipality_psgc_code' => '0702239000'],
['name' => 'Libo-o', 'psgc_code' => '0702239008', 'municipality_psgc_code' => '0702239000'],
['name' => 'Malalay', 'psgc_code' => '0702239009', 'municipality_psgc_code' => '0702239000'],
['name' => 'Palanas', 'psgc_code' => '0702239010', 'municipality_psgc_code' => '0702239000'],
['name' => 'Poblacion', 'psgc_code' => '0702239011', 'municipality_psgc_code' => '0702239000'],
['name' => 'Santa Cruz', 'psgc_code' => '0702239012', 'municipality_psgc_code' => '0702239000'],
['name' => 'Tupas', 'psgc_code' => '0702239013', 'municipality_psgc_code' => '0702239000'],
['name' => 'Vive', 'psgc_code' => '0702239014', 'municipality_psgc_code' => '0702239000'],

// Tudela — 0702253000
['name' => 'Buenavista', 'psgc_code' => '0702253001', 'municipality_psgc_code' => '0702253000'],
['name' => 'Calmante', 'psgc_code' => '0702253002', 'municipality_psgc_code' => '0702253000'],
['name' => 'Daan Secante', 'psgc_code' => '0702253003', 'municipality_psgc_code' => '0702253000'],
['name' => 'General', 'psgc_code' => '0702253004', 'municipality_psgc_code' => '0702253000'],
['name' => 'McArthur', 'psgc_code' => '0702253005', 'municipality_psgc_code' => '0702253000'],
['name' => 'Northern Poblacion', 'psgc_code' => '0702253006', 'municipality_psgc_code' => '0702253000'],
['name' => 'Puertobello', 'psgc_code' => '0702253007', 'municipality_psgc_code' => '0702253000'],
['name' => 'Santander', 'psgc_code' => '0702253008', 'municipality_psgc_code' => '0702253000'],
['name' => 'Secante Bag-o', 'psgc_code' => '0702253009', 'municipality_psgc_code' => '0702253000'],
['name' => 'Southern Poblacion', 'psgc_code' => '0702253010', 'municipality_psgc_code' => '0702253000'],
['name' => 'Villahermosa', 'psgc_code' => '0702253011', 'municipality_psgc_code' => '0702253000'],
];

        /*
         * Load the Cebu municipalities once and create a lookup:
         *
         * PSGC code => database UUID
         */
        $municipalities = DB::table('municipalities')
            ->where('province', 'Cebu')
            ->whereNotNull('psgc_code')
            ->pluck('id', 'psgc_code');

        $rows = [];

        foreach ($barangays as $barangay) {
            $municipalityId = $municipalities[
                $barangay['municipality_psgc_code']
            ] ?? null;

            if (! $municipalityId) {
                throw new RuntimeException(
                    'Parent municipality not found for barangay: '
                    . $barangay['name']
                    . ' (Municipality PSGC: '
                    . $barangay['municipality_psgc_code']
                    . ')'
                );
            }

            $rows[] = [
                'municipality_id' => $municipalityId,
                'name' => $barangay['name'],
                'psgc_code' => $barangay['psgc_code'],
                'source' => 'psgc',
                'is_active' => true,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('barangays')->upsert(
            $rows,
            ['psgc_code'],
            [
                'municipality_id',
                'name',
                'source',
                'is_active',
                'updated_at',
            ]
        );
    }
}