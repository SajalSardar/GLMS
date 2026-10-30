<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * A national curriculum catalog (not school-specific), similar in
     * spirit to the NCTB subject list referenced in
     * `inspiration/AI_Teacher_Training_Proposal_v2.md` §4.2.
     */
    public function run(): void
    {
        $subjects = [
            ['name' => 'Bangla', 'bn_name' => 'বাংলা', 'code' => 'BAN'],
            ['name' => 'English', 'bn_name' => 'ইংরেজি', 'code' => 'ENG'],
            ['name' => 'Mathematics', 'bn_name' => 'গণিত', 'code' => 'MATH'],
            ['name' => 'Science', 'bn_name' => 'বিজ্ঞান', 'code' => 'SCI'],
            ['name' => 'Bangladesh and Global Studies', 'bn_name' => 'বাংলাদেশ ও বিশ্বপরিচয়', 'code' => 'BGS'],
            ['name' => 'Information and Communication Technology', 'bn_name' => 'তথ্য ও যোগাযোগ প্রযুক্তি', 'code' => 'ICT'],
            ['name' => 'Religious Studies', 'bn_name' => 'ধর্ম শিক্ষা', 'code' => 'REL'],
            ['name' => 'Physics', 'bn_name' => 'পদার্থবিজ্ঞান', 'code' => 'PHY'],
            ['name' => 'Chemistry', 'bn_name' => 'রসায়ন', 'code' => 'CHEM'],
            ['name' => 'Biology', 'bn_name' => 'জীববিজ্ঞান', 'code' => 'BIO'],
            ['name' => 'Higher Mathematics', 'bn_name' => 'উচ্চতর গণিত', 'code' => 'HMATH'],
            ['name' => 'Accounting', 'bn_name' => 'হিসাববিজ্ঞান', 'code' => 'ACC'],
            ['name' => 'Business Studies', 'bn_name' => 'ব্যবসায় শিক্ষা', 'code' => 'BUS'],
            ['name' => 'Economics', 'bn_name' => 'অর্থনীতি', 'code' => 'ECO'],
            ['name' => 'Physical Education', 'bn_name' => 'শারীরিক শিক্ষা', 'code' => 'PE'],
        ];

        foreach ($subjects as $subject) {
            Subject::updateOrCreate(['name' => $subject['name']], $subject);
        }
    }
}
