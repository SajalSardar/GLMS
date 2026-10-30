<?php

namespace Database\Seeders;

use App\Models\Upazila;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class UpazilaSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $path = database_path('data/bangladesh_geo_code/upazilas.json');

        $tables = json_decode(file_get_contents($path), true);
        $upazilas = collect($tables)->firstWhere('type', 'table')['data'];

        $now = Carbon::now();

        $rows = collect($upazilas)->map(fn (array $upazila) => [
            'id' => (int) $upazila['id'],
            'district_id' => (int) $upazila['district_id'],
            'name' => $upazila['name'],
            'bn_name' => $upazila['bn_name'],
            'url' => $upazila['url'],
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        Upazila::upsert($rows, ['id']);
    }
}
