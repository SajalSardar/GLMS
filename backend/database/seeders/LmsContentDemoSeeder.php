<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\ChapterVideo;
use App\Models\ClassVersion;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\TeacherUser;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class LmsContentDemoSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Dummy data for the full content/assessment hierarchy — books,
     * chapters, chapter materials/videos, a question bank, and three
     * exams (manual, per_attempt_random unrestricted, and
     * per_attempt_random narrowed to one chapter) with real exam
     * attempts graded via
     * ExamAttempt::finalize() — against Class 6 at Debidwar Pilot
     * Government High School (school_id 1), reusing its "General"
     * ClassVersion and the sample student/teacher users already seeded.
     * Chosen over Class 7 (the timetable-generation demo class) because
     * the sample student/teacher here already exist and the student's
     * `section_id` is already backfilled by SectionBackfillSeeder, so
     * ExamAttemptController::start()'s enrollment check would pass for
     * these exams without any further setup.
     *
     * A no-op if its prerequisites (Class 6, its ClassVersion, the
     * sample teacher/student) aren't present.
     */
    public function run(): void
    {
        $class = SchoolClass::where('name', 'Class 6')->first();
        $teacher = TeacherUser::where('email', 'teacher@tlap.test')->first();
        $studentUser = StudentUser::where('email', 'student@tlap.test')->first();
        $studentProfile = $studentUser ? StudentProfile::where('user_id', $studentUser->id)->first() : null;
        $academicYear = AcademicYear::where('is_current', true)->first();

        if (! $class || ! $teacher || ! $studentProfile || ! $academicYear) {
            return;
        }

        // school_classes.name is national now, so this looks up Class 6's
        // ClassVersion by the sample student's own school_id rather than
        // a hardcoded school — the student's own profile is the only
        // school context this seeder has left once $class carries none.
        // class_versions.name/bn_name were dropped in favor of the linked
        // national Version — SectionBackfillSeeder/ClassRoutineSeeder both
        // link this seeder's prerequisite ClassVersion to Bangla, so that's
        // what's matched here instead of the old "General" placeholder.
        $banglaVersionId = Version::where('name', 'Bangla')->value('id');
        $version = ClassVersion::where('class_id', $class->id)->where('school_id', $studentProfile->school_id)->where('version_id', $banglaVersionId)->first();

        if (! $version) {
            return;
        }

        // books.pdf_uploaded_by_id and chapter_materials.uploaded_by_id stay
        // FK'd to `users` (only trainer/staff accounts hold books.create /
        // chapter_materials.create), so these two columns are stamped with
        // the sample school_admin rather than the now-TeacherUser $teacher.
        $staffUploader = User::where('email', 'school.admin@tlap.test')->first();

        $mathChapters = $this->seedBookWithChapters($version, 'Mathematics', 'Mathematics for Class 6', [
            ['name' => 'Whole Numbers', 'bn_name' => 'পূর্ণ সংখ্যা'],
            ['name' => 'Fractions', 'bn_name' => 'ভগ্নাংশ'],
            ['name' => 'Basic Geometry', 'bn_name' => 'প্রাথমিক জ্যামিতি'],
        ], $staffUploader, $academicYear);

        $scienceChapters = $this->seedBookWithChapters($version, 'Science', 'General Science for Class 6', [
            ['name' => 'Our Environment', 'bn_name' => 'আমাদের পরিবেশ'],
            ['name' => 'Force and Motion', 'bn_name' => 'বল ও গতি'],
            ['name' => 'States of Matter', 'bn_name' => 'পদার্থের অবস্থা'],
        ], $staffUploader, $academicYear);

        $this->seedChapterContent($mathChapters->first(), $staffUploader, $studentProfile);
        $this->seedChapterContent($scienceChapters->first(), $staffUploader, $studentProfile);

        $mathQuestionsByChapter = $mathChapters->mapWithKeys(fn (Chapter $chapter) => [
            $chapter->id => $this->seedQuestions($chapter),
        ]);
        $scienceChapters->each(fn (Chapter $chapter) => $this->seedQuestions($chapter));

        $allMathQuestions = $mathQuestionsByChapter->flatten(1);
        $mathSubject = Subject::where('name', 'Mathematics')->first();

        $examManual = $this->seedManualExam($version, $mathSubject, $academicYear, $allMathQuestions->take(5));
        $examRandom = $this->seedRandomExam($version, $mathSubject, $academicYear);
        $examRandomNarrowed = $this->seedRandomExamNarrowedToChapter($version, $mathSubject, $academicYear, $mathChapters->get(1));

        $this->seedFinalizedAttempt($examManual, $studentUser, attemptNumber: 1, answerAllButLast: true);
        $this->seedFinalizedRandomAttempt($examRandom, $studentUser, attemptNumber: 1);
        $this->seedInProgressAttempt($examManual, $studentUser, attemptNumber: 2);
    }

    /**
     * Builds a real, minimal one-page PDF (hand-rolled, not a library call —
     * this app has no PDF-generation dependency) so the demo books' PDF
     * download/preview routes have an actual file to serve, rather than a
     * database row pointing at a path that was never written to disk.
     */
    private function demoPdfBytes(string $title): string
    {
        $escapedTitle = addcslashes($title, '()\\');
        $stream = "BT /F1 18 Tf 20 100 Td ({$escapedTitle}) Tj ET";

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            4 => '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;

        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    /**
     * @param  list<array{name: string, bn_name: string}>  $chapterDefinitions
     * @return Collection<int, Chapter>
     */
    private function seedBookWithChapters(ClassVersion $version, string $subjectName, string $bookName, array $chapterDefinitions, ?User $staffUploader, AcademicYear $academicYear): Collection
    {
        $subject = Subject::where('name', $subjectName)->first();

        if (! $subject) {
            return collect();
        }

        $pdfStoredPath = 'demo/books/'.str_replace(' ', '-', strtolower($bookName)).'.pdf';
        $pdfContents = $this->demoPdfBytes($bookName);
        Storage::disk('local')->put($pdfStoredPath, $pdfContents);

        $book = Book::updateOrCreate(
            ['class_version_id' => $version->id, 'subject_id' => $subject->id, 'name' => $bookName],
            [
                'academic_year_id' => $academicYear->id,
                'order_index' => 1,
                'description' => "NCTB-aligned {$subjectName} textbook for Class 6.",
                'is_active' => true,
                'pdf_original_filename' => str_replace(' ', '-', $bookName).'.pdf',
                'pdf_stored_path' => $pdfStoredPath,
                'pdf_disk' => 'local',
                'pdf_mime_type' => 'application/pdf',
                'pdf_size_bytes' => strlen($pdfContents),
                'pdf_uploaded_by_id' => $staffUploader?->id,
                'pdf_uploaded_at' => now()->subMonths(2),
                'pdf_status' => 'active',
            ],
        );

        return collect($chapterDefinitions)->values()->map(fn (array $definition, int $index) => Chapter::updateOrCreate(
            ['book_id' => $book->id, 'name' => $definition['name']],
            [
                'order_index' => $index + 1,
                'chapter_number' => 'Chapter '.($index + 1),
                'bn_chapter_number' => 'অধ্যায় '.($index + 1),
                'bn_name' => $definition['bn_name'],
                'description' => "{$definition['name']} — NCTB Class 6 curriculum.",
            ],
        ));
    }

    private function seedChapterContent(Chapter $chapter, ?User $staffUploader, StudentProfile $studentProfile): void
    {
        $notesTitle = "{$chapter->name} — Lecture Notes";
        $notesStoredPath = "demo/materials/chapter-{$chapter->id}-notes.pdf";
        $notesBytes = $this->demoPdfBytes($notesTitle);
        Storage::disk('local')->put($notesStoredPath, $notesBytes);

        ChapterMaterial::updateOrCreate(
            ['chapter_id' => $chapter->id, 'type' => 'primary', 'title' => $notesTitle],
            [
                'original_filename' => 'lecture-notes.pdf',
                'stored_path' => $notesStoredPath,
                'disk' => 'local',
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($notesBytes),
                'uploaded_by_id' => $staffUploader?->id,
                'order_index' => 1,
                'status' => 'active',
            ],
        );

        $worksheetTitle = "{$chapter->name} — Practice Worksheet";
        $worksheetStoredPath = "demo/materials/chapter-{$chapter->id}-worksheet.pdf";
        $worksheetBytes = $this->demoPdfBytes($worksheetTitle);
        Storage::disk('local')->put($worksheetStoredPath, $worksheetBytes);

        ChapterMaterial::updateOrCreate(
            ['chapter_id' => $chapter->id, 'type' => 'supplementary', 'title' => $worksheetTitle],
            [
                'original_filename' => 'practice-worksheet.pdf',
                'stored_path' => $worksheetStoredPath,
                'disk' => 'local',
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($worksheetBytes),
                'uploaded_by_id' => $staffUploader?->id,
                'order_index' => 2,
                'status' => 'active',
            ],
        );

        $videos = [
            ['youtube_video_id' => 'DemoIntro01', 'title' => "{$chapter->name} — Introduction", 'duration_seconds' => 600],
            ['youtube_video_id' => 'DemoDeepDv1', 'title' => "{$chapter->name} — Worked Examples", 'duration_seconds' => 900],
        ];

        collect($videos)->values()->each(fn (array $video, int $index) => ChapterVideo::updateOrCreate(
            ['chapter_id' => $chapter->id, 'youtube_video_id' => $video['youtube_video_id']],
            [
                'title' => $video['title'],
                'instructor_name' => 'Teacher',
                'duration_seconds' => $video['duration_seconds'],
                'completion_threshold_percent' => 90,
                'order_index' => $index + 1,
                'is_enabled' => true,
            ],
        ));
    }

    /**
     * One mcq_single, one mcq_multiple, and one true_false question per
     * chapter, matching StoreQuestionRequest's per-type option-count and
     * correctness rules.
     *
     * @return Collection<int, Question>
     */
    private function seedQuestions(Chapter $chapter): Collection
    {
        $question1 = Question::updateOrCreate(
            ['chapter_id' => $chapter->id, 'question_text' => "{$chapter->name}: which option is correct? (single choice)"],
            ['question_type' => 'mcq_single', 'marks' => 2, 'difficulty' => 'easy', 'order_index' => 1],
        );
        $this->syncOptions($question1, [
            ['text' => 'Option A', 'correct' => true],
            ['text' => 'Option B', 'correct' => false],
            ['text' => 'Option C', 'correct' => false],
            ['text' => 'Option D', 'correct' => false],
        ]);

        $question2 = Question::updateOrCreate(
            ['chapter_id' => $chapter->id, 'question_text' => "{$chapter->name}: which options are correct? (select all that apply)"],
            ['question_type' => 'mcq_multiple', 'marks' => 3, 'difficulty' => 'medium', 'order_index' => 2],
        );
        $this->syncOptions($question2, [
            ['text' => 'Option A', 'correct' => true],
            ['text' => 'Option B', 'correct' => true],
            ['text' => 'Option C', 'correct' => false],
            ['text' => 'Option D', 'correct' => false],
        ]);

        $question3 = Question::updateOrCreate(
            ['chapter_id' => $chapter->id, 'question_text' => "{$chapter->name}: true or false statement."],
            ['question_type' => 'true_false', 'marks' => 1, 'difficulty' => 'hard', 'order_index' => 3],
        );
        $this->syncOptions($question3, [
            ['text' => 'True', 'correct' => true],
            ['text' => 'False', 'correct' => false],
        ]);

        return collect([$question1, $question2, $question3]);
    }

    /**
     * @param  list<array{text: string, correct: bool}>  $options
     */
    private function syncOptions(Question $question, array $options): void
    {
        if ($question->options()->exists()) {
            return;
        }

        foreach ($options as $index => $option) {
            QuestionOption::create([
                'question_id' => $question->id,
                'option_text' => $option['text'],
                'is_correct' => $option['correct'],
                'order_index' => $index + 1,
            ]);
        }
    }

    /**
     * @param  Collection<int, Question>  $questions
     */
    private function seedManualExam(ClassVersion $version, Subject $subject, AcademicYear $academicYear, Collection $questions): Exam
    {
        $exam = Exam::updateOrCreate(
            ['class_version_id' => $version->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYear->id, 'name' => 'Mathematics Midterm Exam'],
            [
                'school_id' => $version->school_id,
                'district_id' => $version->district_id,
                'selection_mode' => 'manual',
                'time_limit_minutes' => 30,
                'max_attempts' => 2,
                'passing_score_percent' => 40,
                'status' => 'published',
            ],
        );

        $exam->questions()->syncWithoutDetaching(
            $questions->values()->mapWithKeys(fn (Question $question, int $index) => [$question->id => ['order_index' => $index + 1]])->all()
        );

        return $exam;
    }

    private function seedRandomExam(ClassVersion $version, Subject $subject, AcademicYear $academicYear): Exam
    {
        return Exam::updateOrCreate(
            ['class_version_id' => $version->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYear->id, 'name' => 'Mathematics Quick Quiz'],
            [
                'school_id' => $version->school_id,
                'district_id' => $version->district_id,
                'selection_mode' => 'per_attempt_random',
                'time_limit_minutes' => 10,
                'max_attempts' => 3,
                'passing_score_percent' => 50,
                'status' => 'published',
                'random_question_count' => 3,
                'marks_per_question' => 2,
            ],
        );
    }

    private function seedRandomExamNarrowedToChapter(ClassVersion $version, Subject $subject, AcademicYear $academicYear, ?Chapter $chapter): Exam
    {
        $exam = Exam::updateOrCreate(
            ['class_version_id' => $version->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYear->id, 'name' => 'Mathematics Fractions Quiz'],
            [
                'school_id' => $version->school_id,
                'district_id' => $version->district_id,
                'selection_mode' => 'per_attempt_random',
                'time_limit_minutes' => 10,
                'max_attempts' => 3,
                'passing_score_percent' => 50,
                'status' => 'published',
                'random_question_count' => 2,
                'marks_per_question' => 3,
            ],
        );

        if ($chapter) {
            $exam->randomChapters()->syncWithoutDetaching([$chapter->id]);
        }

        return $exam;
    }

    /**
     * Snapshots the exam's configured questions into exam_attempt_questions,
     * answers all but the last one (a mix of correct and incorrect
     * selections), then grades it via the real ExamAttempt::finalize() —
     * the same business logic a live submit() call would use — so score,
     * passed, and per-answer is_correct/marks_awarded are all computed
     * consistently rather than hand-filled.
     */
    private function seedFinalizedAttempt(Exam $exam, StudentUser $user, int $attemptNumber, bool $answerAllButLast): void
    {
        $attempt = ExamAttempt::updateOrCreate(
            ['exam_id' => $exam->id, 'user_id' => $user->id, 'attempt_number' => $attemptNumber],
            [
                'school_id' => $exam->school_id,
                'district_id' => $exam->district_id,
                'status' => 'in_progress',
                'started_at' => now()->subMinutes(25),
            ],
        );

        if ($attempt->attemptQuestions()->exists()) {
            return;
        }

        $questions = $exam->questions;

        foreach ($questions as $index => $question) {
            ExamAttemptQuestion::create([
                'exam_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'order_index' => $index + 1,
                'marks' => $question->marks,
            ]);
        }

        $answerable = $answerAllButLast ? $questions->slice(0, -1) : $questions;

        foreach ($answerable as $index => $question) {
            // Alternate correct/incorrect picks for a realistic mixed score.
            $selectedIds = $index % 2 === 0
                ? $question->options->where('is_correct', true)->pluck('id')->all()
                : $question->options->where('is_correct', false)->take(1)->pluck('id')->all();

            ExamAttemptAnswer::create([
                'exam_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_option_ids' => $selectedIds,
            ]);
        }

        $attempt->finalize();
    }

    private function seedFinalizedRandomAttempt(Exam $exam, StudentUser $user, int $attemptNumber): void
    {
        $attempt = ExamAttempt::updateOrCreate(
            ['exam_id' => $exam->id, 'user_id' => $user->id, 'attempt_number' => $attemptNumber],
            [
                'school_id' => $exam->school_id,
                'district_id' => $exam->district_id,
                'status' => 'in_progress',
                'started_at' => now()->subMinutes(8),
            ],
        );

        if ($attempt->attemptQuestions()->exists()) {
            return;
        }

        $questionIds = $exam->eligibleRandomQuestionIds()->shuffle()->take($exam->random_question_count);
        $questions = Question::whereIn('id', $questionIds)->with('options')->get();

        foreach ($questions as $index => $question) {
            ExamAttemptQuestion::create([
                'exam_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'order_index' => $index + 1,
                'marks' => $exam->marks_per_question,
            ]);

            $selectedIds = $index === 0
                ? $question->options->where('is_correct', false)->take(1)->pluck('id')->all()
                : $question->options->where('is_correct', true)->pluck('id')->all();

            ExamAttemptAnswer::create([
                'exam_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_option_ids' => $selectedIds,
            ]);
        }

        $attempt->finalize();
    }

    /**
     * A second, still-open attempt — demonstrates the "currently taking
     * this exam" state: questions snapshotted, one answer saved, nothing
     * finalized yet.
     */
    private function seedInProgressAttempt(Exam $exam, StudentUser $user, int $attemptNumber): void
    {
        $attempt = ExamAttempt::updateOrCreate(
            ['exam_id' => $exam->id, 'user_id' => $user->id, 'attempt_number' => $attemptNumber],
            [
                'school_id' => $exam->school_id,
                'district_id' => $exam->district_id,
                'status' => 'in_progress',
                'started_at' => now()->subMinutes(5),
            ],
        );

        if ($attempt->attemptQuestions()->exists()) {
            return;
        }

        $questions = $exam->questions;

        foreach ($questions as $index => $question) {
            ExamAttemptQuestion::create([
                'exam_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'order_index' => $index + 1,
                'marks' => $question->marks,
            ]);
        }

        $firstQuestion = $questions->first();

        if ($firstQuestion) {
            ExamAttemptAnswer::create([
                'exam_attempt_id' => $attempt->id,
                'question_id' => $firstQuestion->id,
                'selected_option_ids' => $firstQuestion->options->where('is_correct', true)->pluck('id')->all(),
            ]);
        }
    }
}
