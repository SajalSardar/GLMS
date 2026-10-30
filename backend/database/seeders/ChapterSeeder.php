<?php

namespace Database\Seeders;

use App\Models\Chapter;
use App\Models\Subject;
use App\Support\Ordinal;
use Illuminate\Database\Seeder;

class ChapterSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * A representative chapter outline per core subject — not an exact
     * NCTB curriculum reproduction, just enough structure to exercise the
     * subject-chapter configuration end to end.
     */
    public function run(): void
    {
        $chaptersBySubject = [
            'Bangla' => [
                'Prose' => 'গদ্য',
                'Poetry' => 'কবিতা',
                'Grammar' => 'ব্যাকরণ',
                'Essay Writing' => 'রচনা',
                'Letter Writing' => 'পত্র লিখন',
            ],
            'English' => [
                'Grammar' => 'ব্যাকরণ',
                'Reading Comprehension' => 'পাঠ্য বোধগম্যতা',
                'Writing Skills' => 'লিখন দক্ষতা',
                'Vocabulary' => 'শব্দভান্ডার',
            ],
            'Mathematics' => [
                'Real Numbers' => 'বাস্তব সংখ্যা',
                'Algebraic Expressions' => 'বীজগণিতীয় রাশি',
                'Geometry' => 'জ্যামিতি',
                'Trigonometry' => 'ত্রিকোণমিতি',
                'Statistics' => 'পরিসংখ্যান',
            ],
            'Science' => [
                'Our Environment' => 'আমাদের পরিবেশ',
                'Matter and Energy' => 'পদার্থ ও শক্তি',
                'The Living World' => 'জীবজগৎ',
                'Force and Motion' => 'বল ও গতি',
                'Natural Resources' => 'প্রাকৃতিক সম্পদ',
            ],
            'Physics' => [
                'Motion' => 'গতি',
                'Force and Pressure' => 'বল ও চাপ',
                'Work and Energy' => 'কাজ ও শক্তি',
                'Heat' => 'তাপ',
                'Electricity' => 'বিদ্যুৎ',
            ],
            'Chemistry' => [
                'States of Matter' => 'পদার্থের অবস্থা',
                'Chemical Reactions' => 'রাসায়নিক বিক্রিয়া',
                'Acids, Bases and Salts' => 'অম্ল, ক্ষার ও লবণ',
                'The Periodic Table' => 'পর্যায় সারণি',
            ],
            'Biology' => [
                'Cell and Tissue' => 'কোষ ও টিস্যু',
                'Classification of Living Organisms' => 'জীবের শ্রেণিবিন্যাস',
                'Human Body Systems' => 'মানবদেহের তন্ত্র',
                'Reproduction' => 'জীবের বংশবিস্তার',
            ],
            'Information and Communication Technology' => [
                'Introduction to Computers' => 'কম্পিউটার পরিচিতি',
                'Internet and Communication' => 'ইন্টারনেট ও যোগাযোগ',
                'Programming Basics' => 'প্রোগ্রামিং এর মূল ধারণা',
            ],
            'Bangladesh and Global Studies' => [
                'History of Bangladesh' => 'বাংলাদেশের ইতিহাস',
                'Our Government' => 'আমাদের সরকার',
                'Global Geography' => 'বিশ্ব ভূগোল',
            ],
        ];

        foreach ($chaptersBySubject as $subjectName => $chapters) {
            $subject = Subject::where('name', $subjectName)->first();

            if (! $subject) {
                continue;
            }

            foreach (array_values(array_keys($chapters)) as $index => $name) {
                $orderIndex = $index + 1;

                Chapter::updateOrCreate(
                    ['subject_id' => $subject->id, 'name' => $name],
                    [
                        'bn_name' => $chapters[$name],
                        'order_index' => $orderIndex,
                        'chapter_number' => Ordinal::englishChapter($orderIndex),
                        'bn_chapter_number' => Ordinal::banglaChapter($orderIndex),
                    ],
                );
            }
        }
    }
}
