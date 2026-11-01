<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            DivisionSeeder::class,
            DistrictSeeder::class,
            UpazilaSeeder::class,
            SchoolSeeder::class,
            AcademicYearSeeder::class,
            ClassroomSeeder::class,
            SubjectSeeder::class,
            ClassSeeder::class,
            ClassSubjectSeeder::class,
            PermissionSeeder::class,
            RoleSeeder::class,
            RolePermissionSeeder::class,
            UserSeeder::class,
            TeacherProfileSeeder::class,
            StudentProfileSeeder::class,
            GuardianSeeder::class,
            ClassRoutineSeeder::class,
            SectionBackfillSeeder::class,
        ]);
    }
}
