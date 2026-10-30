<?php

namespace Database\Seeders;

use App\Models\Classroom;
use App\Models\School;
use Illuminate\Database\Seeder;

class ClassroomSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * A handful of generic rooms per school, enough to exercise classroom
     * assignment on the class routine.
     */
    public function run(): void
    {
        $rooms = [
            ['name' => 'Room 101', 'building' => 'Main Building', 'floor' => 'Ground Floor', 'capacity' => 40],
            ['name' => 'Room 102', 'building' => 'Main Building', 'floor' => '1st Floor', 'capacity' => 40],
            ['name' => 'Room 103', 'building' => 'Main Building', 'floor' => '1st Floor', 'capacity' => 35],
            ['name' => 'Science Lab', 'building' => 'Main Building', 'floor' => '2nd Floor', 'capacity' => 30],
        ];

        School::all()->each(function (School $school) use ($rooms) {
            foreach ($rooms as $room) {
                Classroom::updateOrCreate(
                    ['school_id' => $school->id, 'name' => $room['name']],
                    [
                        'district_id' => $school->district_id,
                        'building' => $room['building'],
                        'floor' => $room['floor'],
                        'capacity' => $room['capacity'],
                    ],
                );
            }
        });
    }
}
