<?php

namespace Database\Seeders;

use App\Models\District;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DistrictSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $path = database_path('data/bangladesh_geo_code/districts.json');

        $tables = json_decode(file_get_contents($path), true);
        $districts = collect($tables)->firstWhere('type', 'table')['data'];

        $now = Carbon::now();

        $rows = collect($districts)->map(fn (array $district) => [
            'id' => (int) $district['id'],
            'division_id' => (int) $district['division_id'],
            'name' => $district['name'],
            'bn_name' => $district['bn_name'],
            'lat' => $district['lat'],
            'lon' => $district['lon'],
            'url' => $district['url'],
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        District::upsert($rows, ['id']);
    }
}
