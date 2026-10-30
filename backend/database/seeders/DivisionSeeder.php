<?php

namespace Database\Seeders;

use App\Models\Division;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DivisionSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $path = database_path('data/bangladesh_geo_code/divisions.json');

        $tables = json_decode(file_get_contents($path), true);
        $divisions = collect($tables)->firstWhere('type', 'table')['data'];

        $now = Carbon::now();

        $rows = collect($divisions)->map(fn (array $division) => [
            'id' => (int) $division['id'],
            'name' => $division['name'],
            'bn_name' => $division['bn_name'],
            'url' => $division['url'],
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        Division::upsert($rows, ['id']);
    }
}
