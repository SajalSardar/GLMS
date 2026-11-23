<?php

namespace Database\Seeders;

use App\Models\ClassVersion;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Version;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StudentDemoSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * More sample students beyond the lone one StudentProfileSeeder
     * creates — same Class 6 / School 1 / Section "Main" as that one, so
     * they show up together in a class roster, the class routine, leave
     * application review, etc. Runs after ClassRoutineSeeder (which
     * creates that Version/Shift/Section chain itself, same
     * firstOrCreate keys SectionBackfillSeeder also uses — see that
     * seeder's own comment) so section_id/shift_id/class_version_id can
     * all be resolved and set directly here, rather than relying on
     * SectionBackfillSeeder's later raw UPDATE (which only touches
     * section_id).
     *
     * student_id is generated through the same StudentProfile::
     * nextStudentId() the real Add Student flow uses (not hardcoded),
     * so these rows exercise the exact same login-id generation path.
     */
    public function run(): void
    {
        $school = School::find(1);
        $class = SchoolClass::where('name', 'Class 6')->first();
        $studentRole = Role::where('name', 'student')->first();

        if (! $school || ! $class || ! $studentRole) {
            return;
        }

        // class_versions.name/bn_name were dropped in favor of the linked
        // national Version — ClassRoutineSeeder links this seeder's
        // prerequisite ClassVersion to Bangla, so that's what's matched
        // here instead of the old "General" placeholder.
        $banglaVersionId = Version::where('name', 'Bangla')->value('id');

        if ($banglaVersionId === null) {
            throw new \RuntimeException("The 'Bangla' version was not found in the versions catalog — has it been renamed?");
        }

        $version = ClassVersion::where('class_id', $class->id)->where('school_id', $school->id)->where('version_id', $banglaVersionId)->first();
        $shift = $version ? Shift::where('class_version_id', $version->id)->where('name', 'Day Shift')->first() : null;
        $section = $shift ? Section::where('shift_id', $shift->id)->where('name', 'Main')->first() : null;

        $students = [
            ['name' => 'Rahim Uddin', 'bn_name' => 'রহিম উদ্দিন', 'gender' => 'male', 'dob' => '2012-01-12', 'blood' => 'A+'],
            ['name' => 'Fatema Akter', 'bn_name' => 'ফাতেমা আক্তার', 'gender' => 'female', 'dob' => '2012-02-20', 'blood' => 'B+'],
            ['name' => 'Jahidul Islam', 'bn_name' => 'জাহিদুল ইসলাম', 'gender' => 'male', 'dob' => '2012-03-08', 'blood' => 'O+'],
            ['name' => 'Taslima Begum', 'bn_name' => 'তসলিমা বেগম', 'gender' => 'female', 'dob' => '2012-04-30', 'blood' => 'AB+'],
            ['name' => 'Nazmul Hossain', 'bn_name' => 'নাজমুল হোসেন', 'gender' => 'male', 'dob' => '2012-05-17', 'blood' => 'A-'],
            ['name' => 'Salma Khatun', 'bn_name' => 'সালমা খাতুন', 'gender' => 'female', 'dob' => '2012-06-25', 'blood' => 'B-'],
            ['name' => 'Habibur Rahman', 'bn_name' => 'হাবিবুর রহমান', 'gender' => 'male', 'dob' => '2012-07-14', 'blood' => 'O-'],
        ];

        foreach ($students as $index => $data) {
            $slug = Str::slug($data['name']);

            $user = StudentUser::updateOrCreate(
                ['email' => "{$slug}@tlap.test"],
                [
                    'name' => $data['name'],
                    'bn_name' => $data['bn_name'],
                    'phone' => '017100000'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'password' => 'password',
                    'role_id' => $studentRole->id,
                    'school_id' => $school->id,
                    'district_id' => $school->district_id,
                    'email_verified_at' => now(),
                ],
            );

            $profile = StudentProfile::where('user_id', $user->id)->first();

            StudentProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'school_id' => $school->id,
                    'district_id' => $school->district_id,
                    'class_id' => $class->id,
                    'section_id' => $section?->id,
                    'shift_id' => $shift?->id,
                    'class_version_id' => $version?->id,
                    'student_id' => $profile?->student_id ?? StudentProfile::nextStudentId(),
                    'roll_number' => (string) ($index + 2), // roll 1 is the sample student from StudentProfileSeeder
                    'admission_date' => '2023-01-05',
                    'date_of_birth' => $data['dob'],
                    'gender' => $data['gender'],
                    'blood_group' => $data['blood'],
                    'present_address' => 'Debidwar, Comilla',
                    'permanent_address' => 'Debidwar, Comilla',
                ],
            );
        }
    }
}
