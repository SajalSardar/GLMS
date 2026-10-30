<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\Upazila;
use Illuminate\Database\Seeder;

class SchoolSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Creates a handful of sample schools under real upazilas, spread across
     * every division, so each school's division_id/district_id/upazila_id
     * line up with the geo data seeded by DivisionSeeder/DistrictSeeder/UpazilaSeeder.
     */
    public function run(): void
    {
        $schools = [
            // Chattagram
            ['upazila_id' => 1, 'name' => 'Debidwar Pilot Government High School', 'eiin' => '101001', 'type' => 'government', 'level' => 'secondary', 'established_year' => 1968],
            ['upazila_id' => 18, 'name' => 'Chhagalnaiya Govt. Model High School', 'eiin' => '101002', 'type' => 'government', 'level' => 'higher_secondary', 'established_year' => 1972],

            // Rajshahi
            ['upazila_id' => 104, 'name' => 'Belkuchi Adarsha Secondary School', 'eiin' => '102001', 'type' => 'non_government', 'level' => 'secondary', 'established_year' => 1981],
            ['upazila_id' => 113, 'name' => 'Sujanagar Government College', 'eiin' => '102002', 'type' => 'government', 'level' => 'college', 'established_year' => 1965],

            // Khulna
            ['upazila_id' => 171, 'name' => 'Manirampur Girls High School', 'eiin' => '103001', 'type' => 'non_government', 'level' => 'secondary', 'established_year' => 1975],
            ['upazila_id' => 179, 'name' => 'Assasuni Pilot Primary School', 'eiin' => '103002', 'type' => 'government', 'level' => 'primary', 'established_year' => 1958],

            // Barisal
            ['upazila_id' => 230, 'name' => 'Jhalakathi Sadar High School', 'eiin' => '104001', 'type' => 'non_government', 'level' => 'secondary', 'established_year' => 1978],
            ['upazila_id' => 234, 'name' => 'Bauphal Government College', 'eiin' => '104002', 'type' => 'government', 'level' => 'college', 'established_year' => 1970],

            // Sylhet
            ['upazila_id' => 272, 'name' => 'Balaganj Union High School', 'eiin' => '105001', 'type' => 'non_government', 'level' => 'secondary', 'established_year' => 1983],
            ['upazila_id' => 285, 'name' => 'Barlekha Govt. Primary School', 'eiin' => '105002', 'type' => 'government', 'level' => 'primary', 'established_year' => 1960],

            // Dhaka
            ['upazila_id' => 311, 'name' => 'Belabo Pilot High School', 'eiin' => '106001', 'type' => 'government', 'level' => 'secondary', 'established_year' => 1969],
            ['upazila_id' => 317, 'name' => 'Kaliganj Model School & College', 'eiin' => '106002', 'type' => 'non_government', 'level' => 'higher_secondary', 'established_year' => 1991],

            // Rangpur
            ['upazila_id' => 399, 'name' => 'Panchagarh Sadar Government High School', 'eiin' => '107001', 'type' => 'government', 'level' => 'secondary', 'established_year' => 1963],
            ['upazila_id' => 404, 'name' => 'Nawabganj Adarsha Primary School', 'eiin' => '107002', 'type' => 'government', 'level' => 'primary', 'established_year' => 1955],

            // Mymensingh
            ['upazila_id' => 457, 'name' => 'Sherpur Sadar Government College', 'eiin' => '108001', 'type' => 'government', 'level' => 'college', 'established_year' => 1967],
            ['upazila_id' => 462, 'name' => 'Fulbaria High School', 'eiin' => '108002', 'type' => 'non_government', 'level' => 'secondary', 'established_year' => 1980],
        ];

        $upazilas = Upazila::with('district')->find(array_column($schools, 'upazila_id'))->keyBy('id');

        foreach ($schools as $school) {
            $upazila = $upazilas->get($school['upazila_id']);

            School::updateOrCreate(
                ['eiin' => $school['eiin']],
                [
                    'division_id' => $upazila->district->division_id,
                    'district_id' => $upazila->district_id,
                    'upazila_id' => $upazila->id,
                    'name' => $school['name'],
                    'type' => $school['type'],
                    'level' => $school['level'],
                    'established_year' => $school['established_year'],
                    'address' => "{$upazila->name}, {$upazila->district->name}",
                ],
            );
        }
    }
}
