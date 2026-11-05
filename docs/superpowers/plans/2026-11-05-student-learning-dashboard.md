# Student Learning Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a student (or their linked guardian, or a management/oversight-role viewer) see a read-only rollup of that student's video, material, and exam progress — per chapter and per subject — for their current class-version/academic-year enrollment.

**Architecture:** `App\Support\StudentDashboardBuilder` is a pure computation class (no HTTP, no persistence) that reads `ChapterVideoProgress`, `ContentAccessLog`, and `ExamAttempt` on the fly and returns a plain nested array — the exact shape the controller wraps in `{"data": ...}`. No new table, no caching layer: a single student's own content tree is a bounded query, not a cross-school aggregate. Visibility reuses the exact 3-way pattern (owner / linked guardian / permission-holder) already established for `ExamAttempt` in the prior sub-project, added as a **new** `StudentProfilePolicy::viewDashboard()` ability — never a reuse of the existing `view()` ability, which is flatly gated on `student_profiles.view`, a permission the `student` role already holds broadly (confirmed: any student can currently view any other student's basic profile in their tenant). `StudentProfile` is itself `TenantScoped`, so route-model-binding already prevents a cross-tenant request from ever reaching the policy — same mechanism every prior sub-project relies on.

**Tech Stack:** Laravel 13 (PHP 8.3+), PHPUnit, SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-11-05-student-learning-dashboard-design.md`

## Global Constraints

- No new database table or model. Every number in the response is computed on read from existing tables (`ChapterVideo`, `ChapterVideoProgress`, `ChapterMaterial`, `ContentAccessLog`, `Exam`, `ExamAttempt`, `Chapter`, `Book`).
- Exclusions apply at every level: only `Book.is_active = true` chapters are considered; only `ChapterVideo.is_enabled = true` videos count toward `video_total`; only `ChapterMaterial.status = 'active'` materials count toward `materials_total`; only `Exam.status = 'published'` exams are listed.
- `best_score`/`total_possible_marks` for an exam come from the single highest-scoring **`submitted`** `ExamAttempt` — paired together from that same attempt, never mixed across different attempts. An `in_progress` attempt counts toward `attempts_used` but contributes nothing to `best_score`/`total_possible_marks`/`passed`.
- `passed` is `true` if **any** submitted attempt for that exam has `passed = true`, `false` if at least one submitted attempt exists and none passed, `null` if no submitted attempt exists yet.
- A `StudentProfile` with no `section_id` (never assigned to a section) returns a 200 with `class_version_id`/`academic_year_id` both `null` and `subjects: []` — not an error.
- `StudentProfilePolicy::viewDashboard()` is a brand-new ability. It must never delegate to or reuse `view()` — `view()`'s flat `student_profiles.view` gate is too broad for this payload (exam scores, video-watching history).
- After every PHP file change, `vendor/bin/pint --dirty --format agent` must have been run.
- All commands below run from the `backend/` directory.

---

### Task 1: `StudentDashboardBuilder` support class

**Files:**
- Create: `backend/app/Support/StudentDashboardBuilder.php`
- Test: `backend/tests/Unit/StudentDashboardBuilderTest.php`

**Interfaces:**
- Consumes: `App\Models\StudentProfile` (existing, via `->section->shift` for `class_version_id`/`academic_year_id`), `App\Models\ClassVersion::subjects()`, `App\Models\Chapter`/`Book`/`ChapterVideo`/`ChapterVideoProgress`/`ChapterMaterial`/`ContentAccessLog`/`Exam`/`ExamAttempt` (all existing).
- Produces: `App\Support\StudentDashboardBuilder::build(StudentProfile $profile): array` — Task 3's controller action consumes this by this exact name, wrapping its return value directly under a `"data"` key with no further transformation.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Unit/StudentDashboardBuilderTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\ChapterVideo;
use App\Models\ChapterVideoProgress;
use App\Models\ClassVersion;
use App\Models\ContentAccessLog;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Question;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use App\Support\StudentDashboardBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentDashboardBuilderTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private ClassVersion $version;

    private AcademicYear $academicYear;

    private Subject $subject;

    private User $studentUser;

    private StudentProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->subject = Subject::create(['name' => 'Mathematics']);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class 6']);
        $this->version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Bangla Version']);
        $this->version->subjects()->attach($this->subject->id);

        $this->academicYear = AcademicYear::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $shift = Shift::create(['class_version_id' => $this->version->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section A']);

        $this->studentUser = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $this->profile = StudentProfile::create(['user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'section_id' => $section->id]);
    }

    public function test_build_returns_empty_subjects_when_student_has_no_section(): void
    {
        $unassigned = StudentProfile::create(['user_id' => User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id])->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $result = (new StudentDashboardBuilder)->build($unassigned);

        $this->assertNull($result['class_version_id']);
        $this->assertNull($result['academic_year_id']);
        $this->assertSame([], $result['subjects']);
    }

    public function test_build_includes_a_subject_with_zero_chapters_and_zero_exams(): void
    {
        $result = (new StudentDashboardBuilder)->build($this->profile);

        $this->assertSame($this->version->id, $result['class_version_id']);
        $this->assertSame($this->academicYear->id, $result['academic_year_id']);
        $this->assertCount(1, $result['subjects']);
        $this->assertSame($this->subject->id, $result['subjects'][0]['subject_id']);
        $this->assertSame([], $result['subjects'][0]['chapters']);
        $this->assertSame([], $result['subjects'][0]['exams']);
    }

    public function test_video_and_material_counts_exclude_inactive_book_disabled_video_and_archived_material(): void
    {
        $activeBook = Book::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Active Book']);
        $inactiveBook = Book::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Inactive Book', 'is_active' => false]);

        $chapter = Chapter::create(['book_id' => $activeBook->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Chapter 1']);
        Chapter::create(['book_id' => $inactiveBook->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Hidden Chapter']);

        $enabledVideo = ChapterVideo::create(['chapter_id' => $chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'youtube_video_id' => 'aaaaaaaaaaa', 'title' => 'Enabled']);
        ChapterVideo::create(['chapter_id' => $chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'youtube_video_id' => 'bbbbbbbbbbb', 'title' => 'Disabled', 'is_enabled' => false]);

        ChapterVideoProgress::create(['chapter_video_id' => $enabledVideo->id, 'user_id' => $this->studentUser->id, 'status' => 'completed']);

        $activeMaterial = ChapterMaterial::create(['chapter_id' => $chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'type' => 'supplementary', 'title' => 'Active', 'original_filename' => 'a.pdf', 'stored_path' => 'a.pdf', 'disk' => 'local', 'mime_type' => 'application/pdf', 'size_bytes' => 10]);
        ChapterMaterial::create(['chapter_id' => $chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'type' => 'supplementary', 'title' => 'Archived', 'original_filename' => 'b.pdf', 'stored_path' => 'b.pdf', 'disk' => 'local', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'status' => 'archived']);

        ContentAccessLog::create(['chapter_id' => $chapter->id, 'chapter_material_id' => $activeMaterial->id, 'user_id' => $this->studentUser->id, 'action' => 'downloaded']);

        $result = (new StudentDashboardBuilder)->build($this->profile);
        $chapters = $result['subjects'][0]['chapters'];

        $this->assertCount(1, $chapters, 'the inactive book\'s chapter must not appear');
        $this->assertSame(1, $chapters[0]['video_total'], 'disabled video excluded from total');
        $this->assertSame(1, $chapters[0]['video_completed']);
        $this->assertSame(1, $chapters[0]['materials_total'], 'archived material excluded from total');
        $this->assertSame(1, $chapters[0]['materials_accessed']);
    }

    public function test_exam_rollup_excludes_unpublished_exams_and_pairs_best_score_with_its_own_total(): void
    {
        $exam = Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Midterm', 'selection_mode' => 'manual', 'status' => 'published', 'max_attempts' => 3]);
        Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Draft Exam', 'selection_mode' => 'manual', 'status' => 'draft']);

        ExamAttempt::create(['exam_id' => $exam->id, 'user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'attempt_number' => 1, 'status' => 'submitted', 'started_at' => now(), 'submitted_at' => now(), 'score' => 4, 'total_possible_marks' => 10, 'passed' => false]);
        ExamAttempt::create(['exam_id' => $exam->id, 'user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'attempt_number' => 2, 'status' => 'submitted', 'started_at' => now(), 'submitted_at' => now(), 'score' => 8, 'total_possible_marks' => 10, 'passed' => true]);
        ExamAttempt::create(['exam_id' => $exam->id, 'user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'attempt_number' => 3, 'status' => 'in_progress', 'started_at' => now()]);

        $result = (new StudentDashboardBuilder)->build($this->profile);
        $exams = $result['subjects'][0]['exams'];

        $this->assertCount(1, $exams, 'the draft exam must not appear');
        $this->assertSame(3, $exams[0]['attempts_used'], 'in_progress counts toward attempts_used');
        $this->assertSame(3, $exams[0]['max_attempts']);
        $this->assertSame(8, $exams[0]['best_score']);
        $this->assertSame(10, $exams[0]['total_possible_marks']);
        $this->assertTrue($exams[0]['passed'], 'passed is true because SOME submitted attempt passed, independent of which one scored highest');
    }

    public function test_exam_passed_is_null_when_no_attempt_has_been_submitted_yet(): void
    {
        $exam = Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Final', 'selection_mode' => 'manual', 'status' => 'published']);
        ExamAttempt::create(['exam_id' => $exam->id, 'user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'attempt_number' => 1, 'status' => 'in_progress', 'started_at' => now()]);

        $result = (new StudentDashboardBuilder)->build($this->profile);
        $exams = $result['subjects'][0]['exams'];

        $this->assertSame(1, $exams[0]['attempts_used']);
        $this->assertNull($exams[0]['best_score']);
        $this->assertNull($exams[0]['total_possible_marks']);
        $this->assertNull($exams[0]['passed']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/StudentDashboardBuilderTest.php`
Expected: FAIL — `App\Support\StudentDashboardBuilder` does not exist.

- [ ] **Step 3: Create the support class**

`backend/app/Support/StudentDashboardBuilder.php`:

```php
<?php

namespace App\Support;

use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\ChapterVideo;
use App\Models\ChapterVideoProgress;
use App\Models\ClassVersion;
use App\Models\ContentAccessLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\StudentProfile;
use App\Models\Subject;
use Illuminate\Support\Collection;

class StudentDashboardBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(StudentProfile $profile): array
    {
        $shift = $profile->section?->shift;
        $classVersionId = $shift?->class_version_id;
        $academicYearId = $shift?->academic_year_id;

        if ($classVersionId === null || $academicYearId === null) {
            return [
                'student_profile_id' => $profile->id,
                'class_version_id' => null,
                'academic_year_id' => null,
                'subjects' => [],
            ];
        }

        $classVersion = ClassVersion::findOrFail($classVersionId);

        $subjects = $classVersion->subjects->map(
            fn (Subject $subject) => $this->buildSubjectRollup($profile, $classVersionId, $academicYearId, $subject)
        )->values()->all();

        return [
            'student_profile_id' => $profile->id,
            'class_version_id' => $classVersionId,
            'academic_year_id' => $academicYearId,
            'subjects' => $subjects,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSubjectRollup(StudentProfile $profile, int $classVersionId, int $academicYearId, Subject $subject): array
    {
        $userId = $profile->user_id;

        $chapters = Chapter::query()
            ->whereHas('book', fn ($query) => $query
                ->where('class_version_id', $classVersionId)
                ->where('subject_id', $subject->id)
                ->where('is_active', true))
            ->orderBy('order_index')
            ->get(['id', 'name']);

        $chapterIds = $chapters->pluck('id');

        $videos = ChapterVideo::query()
            ->whereIn('chapter_id', $chapterIds)
            ->where('is_enabled', true)
            ->get(['id', 'chapter_id']);

        $completedVideoIds = ChapterVideoProgress::query()
            ->whereIn('chapter_video_id', $videos->pluck('id'))
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->pluck('chapter_video_id');

        $materials = ChapterMaterial::query()
            ->whereIn('chapter_id', $chapterIds)
            ->where('status', 'active')
            ->get(['id', 'chapter_id']);

        $accessedMaterialIds = ContentAccessLog::query()
            ->whereIn('chapter_material_id', $materials->pluck('id'))
            ->where('user_id', $userId)
            ->distinct()
            ->pluck('chapter_material_id');

        $videosByChapter = $videos->groupBy('chapter_id');
        $materialsByChapter = $materials->groupBy('chapter_id');

        $chapterRollups = $chapters->map(function ($chapter) use ($videosByChapter, $materialsByChapter, $completedVideoIds, $accessedMaterialIds) {
            /** @var Collection $chapterVideos */
            $chapterVideos = $videosByChapter->get($chapter->id, collect());
            /** @var Collection $chapterMaterials */
            $chapterMaterials = $materialsByChapter->get($chapter->id, collect());

            return [
                'chapter_id' => $chapter->id,
                'chapter_name' => $chapter->name,
                'video_total' => $chapterVideos->count(),
                'video_completed' => $chapterVideos->filter(fn ($video) => $completedVideoIds->contains($video->id))->count(),
                'materials_total' => $chapterMaterials->count(),
                'materials_accessed' => $chapterMaterials->filter(fn ($material) => $accessedMaterialIds->contains($material->id))->count(),
            ];
        })->values()->all();

        $exams = Exam::query()
            ->where('class_version_id', $classVersionId)
            ->where('subject_id', $subject->id)
            ->where('academic_year_id', $academicYearId)
            ->where('status', 'published')
            ->get(['id', 'name', 'max_attempts']);

        $attempts = ExamAttempt::query()
            ->whereIn('exam_id', $exams->pluck('id'))
            ->where('user_id', $userId)
            ->get(['id', 'exam_id', 'status', 'score', 'total_possible_marks', 'passed']);

        $attemptsByExam = $attempts->groupBy('exam_id');

        $examRollups = $exams->map(function ($exam) use ($attemptsByExam) {
            /** @var Collection $examAttempts */
            $examAttempts = $attemptsByExam->get($exam->id, collect());
            $submitted = $examAttempts->where('status', 'submitted');
            $best = $submitted->sortByDesc('score')->first();

            return [
                'exam_id' => $exam->id,
                'exam_name' => $exam->name,
                'attempts_used' => $examAttempts->count(),
                'max_attempts' => $exam->max_attempts,
                'best_score' => $best?->score,
                'total_possible_marks' => $best?->total_possible_marks,
                'passed' => $submitted->isEmpty() ? null : $submitted->contains('passed', true),
            ];
        })->values()->all();

        return [
            'subject_id' => $subject->id,
            'subject_name' => $subject->name,
            'chapters' => $chapterRollups,
            'exams' => $examRollups,
        ];
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test tests/Unit/StudentDashboardBuilderTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/StudentDashboardBuilder.php tests/Unit/StudentDashboardBuilderTest.php
git commit -m "Add StudentDashboardBuilder: on-the-fly chapter/subject progress rollup"
```

---

### Task 2: `student_progress.view` permission

**Files:**
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Feature/DatabaseSeederTest.php`

**Interfaces:**
- Consumes: nothing from Task 1 directly.
- Produces: permission `student_progress.view`, seeded and mapped to exactly the same role list as `exam_attempts.view` (`national_admin`, `district_officer`, `school_admin`, `trainer`, `teacher`). Task 3's `StudentProfilePolicy::viewDashboard()` consumes this exact permission-name string.

- [ ] **Step 1: Write the failing test** — modify `backend/tests/Feature/DatabaseSeederTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards against a repeat of the ChapterSeeder regression, where a
     * seeder referencing a dropped column (subject_id on chapters) aborted
     * the entire seed chain before permissions were ever seeded.
     */
    public function test_full_seed_chain_completes_without_error(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Permission::where('name', 'books.view')->exists());
        $this->assertTrue(Permission::where('name', 'chapter_materials.view')->exists());
        $this->assertTrue(Permission::where('name', 'chapter_videos.view')->exists());
        $this->assertTrue(Permission::where('name', 'questions.view')->exists());
        $this->assertTrue(Permission::where('name', 'exams.view')->exists());
        $this->assertTrue(Permission::where('name', 'exam_attempts.view')->exists());
        $this->assertTrue(Permission::where('name', 'student_progress.view')->exists());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/DatabaseSeederTest.php`
Expected: FAIL — `student_progress.view` permission does not exist.

- [ ] **Step 3: Add the permission** — modify `backend/database/seeders/PermissionSeeder.php`, inserting right after the `exam_attempts.create` entry and before `users.view`:

```php
            ['name' => 'exam_attempts.view', 'label' => 'View any exam attempt'],
            ['name' => 'exam_attempts.create', 'label' => 'Start, answer, and submit exam attempts'],

            ['name' => 'student_progress.view', 'label' => 'View any student\'s learning dashboard'],

            ['name' => 'users.view', 'label' => 'View users'],
```

- [ ] **Step 4: Map the permission to roles** — in `backend/database/seeders/RolePermissionSeeder.php`, replace the entire `$rolePermissions = [ ... ];` array with:

```php
        $rolePermissions = [
            'national_admin' => $allPermissions,

            'district_officer' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'exams.view', 'exam_attempts.view', 'student_progress.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view',
                'subject_teacher_assignments.view', 'timetable_entries.view',
                'teacher_profiles.view', 'student_profiles.view',
            ],

            'school_admin' => [
                'divisions.view', 'districts.view', 'upazilas.view',
                'schools.view', 'schools.update',
                'classes.view', 'classes.create', 'classes.update', 'classes.delete',
                'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view',
                'exams.view', 'exams.create', 'exams.update', 'exam_attempts.view', 'student_progress.view',
                'academic_years.view', 'academic_years.create', 'academic_years.update', 'academic_years.delete',
                'classrooms.view', 'classrooms.create', 'classrooms.update', 'classrooms.delete',
                'class_routines.view', 'class_routines.create', 'class_routines.update', 'class_routines.delete',
                'class_versions.view', 'class_versions.create', 'class_versions.update', 'class_versions.delete',
                'shifts.view', 'shifts.create', 'shifts.update', 'shifts.delete',
                'sections.view', 'sections.create', 'sections.update', 'sections.delete', 'sections.override_capacity',
                'subject_teacher_assignments.view', 'subject_teacher_assignments.create',
                'subject_teacher_assignments.update', 'subject_teacher_assignments.delete',
                'timetable_entries.view', 'timetable_entries.create', 'timetable_entries.update',
                'timetable_entries.delete', 'timetable_entries.publish',
                'users.view', 'users.create', 'users.update',
                'teacher_profiles.view', 'teacher_profiles.create', 'teacher_profiles.update', 'teacher_profiles.delete',
                'student_profiles.view', 'student_profiles.create', 'student_profiles.update', 'student_profiles.delete',
                'guardians.view', 'guardians.create', 'guardians.update', 'guardians.delete',
            ],

            'teacher' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
                'questions.view', 'questions.create', 'questions.update',
                'exams.view', 'exams.create', 'exams.update', 'exam_attempts.view', 'student_progress.view',
                'teacher_profiles.view', 'student_profiles.view',
            ],

            'student' => [
                'schools.view', 'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view',
                'student_profiles.view', 'exam_attempts.create',
            ],

            'trainer' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'academic_years.view', 'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
                'subjects.view', 'subjects.create', 'subjects.update',
                'chapter_videos.view', 'chapter_videos.create', 'chapter_videos.update',
                'chapter_materials.view', 'chapter_materials.create', 'chapter_materials.update',
                'books.view', 'books.create', 'books.update',
                'chapters.view', 'chapters.create', 'chapters.update',
                'questions.view', 'questions.create', 'questions.update',
                'exams.view', 'exams.create', 'exams.update', 'exam_attempts.view', 'student_progress.view',
                'teacher_profiles.view',
            ],

            'office_staff' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
                'teacher_profiles.view', 'student_profiles.view',
                'guardians.view', 'guardians.create', 'guardians.update',
            ],

            'parent_guardian' => [
                'schools.view', 'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view',
                'student_profiles.view', 'guardians.view',
            ],

            'it_admin' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view',
            ],

            'auditor' => [
                'divisions.view', 'districts.view', 'upazilas.view', 'schools.view',
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'questions.view', 'academic_years.view',
                'classrooms.view', 'class_routines.view',
                'class_versions.view', 'shifts.view', 'sections.view',
                'subject_teacher_assignments.view', 'timetable_entries.view',
                'users.view', 'roles.view', 'permissions.view',
                'teacher_profiles.view', 'student_profiles.view', 'guardians.view',
            ],
        ];
```

This is a full-array replacement — verify role-by-role that only `district_officer`/`school_admin`/`teacher`/`trainer` gained `student_progress.view`, and every other permission on every role is byte-for-byte identical to before. `student`/`office_staff`/`parent_guardian`/`it_admin`/`auditor` must end up with **no** `student_progress.*` entry — their own-record/linked-guardian access is a relationship check in the Policy (Task 3), not a flat permission grant.

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/DatabaseSeederTest.php`
Expected: PASS.

- [ ] **Step 6: Add a role-mapping regression test** — create `backend/tests/Feature/StudentProgressPermissionMappingTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProgressPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards the exact role list this task's brief specifies — granting
     * student_progress.view to a role that shouldn't have it (a
     * student's own dashboard access is ownership-based, a
     * parent_guardian's access to a linked child is a Policy
     * relationship check, neither is this flat permission) would be a
     * real authorization regression, not just redundancy. Same pattern
     * already established for exam_attempts.* in the prior sub-project.
     */
    public function test_student_progress_view_is_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'student_progress.view'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(
            ['district_officer', 'national_admin', 'school_admin', 'teacher', 'trainer'],
            $roles
        );
    }
}
```

- [ ] **Step 7: Run the new test**

Run: `php artisan test tests/Feature/StudentProgressPermissionMappingTest.php`
Expected: PASS.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php tests/Feature/DatabaseSeederTest.php tests/Feature/StudentProgressPermissionMappingTest.php
git commit -m "Seed student_progress.view permission and map to roles"
```

---

### Task 3: `viewDashboard` policy ability, controller action, route

**Files:**
- Modify: `backend/app/Policies/StudentProfilePolicy.php`
- Modify: `backend/app/Http/Controllers/Api/V1/StudentProfileController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/StudentDashboardControllerTest.php`

**Interfaces:**
- Consumes: `App\Support\StudentDashboardBuilder` (Task 1), `student_progress.view` permission (Task 2), `App\Models\Guardian`/`User::guardian()` (existing, same relation sub-project 5's `ExamAttemptPolicy::view()` already uses).
- Produces: route `GET /api/v1/student-profiles/{student_profile}/dashboard`; `StudentProfilePolicy::viewDashboard()`; `StudentProfileController::dashboard()`.

- [ ] **Step 1: Write the failing test** — create `backend/tests/Feature/StudentDashboardControllerTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Guardian;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class StudentDashboardControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private StudentProfile $profile;

    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        Subject::create(['name' => 'Mathematics']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Bangla Version']);
        $academicYear = AcademicYear::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $shift = Shift::create(['class_version_id' => $version->id, 'academic_year_id' => $academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section A']);

        $this->studentUser = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $this->profile = StudentProfile::create(['user_id' => $this->studentUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'section_id' => $section->id]);
    }

    public function test_the_student_can_view_their_own_dashboard(): void
    {
        $this->actingAs($this->studentUser, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$this->profile->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.student_profile_id', $this->profile->id);
    }

    public function test_a_linked_guardian_can_view_the_dashboard(): void
    {
        $guardianUser = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $guardian = Guardian::create(['user_id' => $guardianUser->id, 'name' => 'Guardian', 'phone' => '01700000001']);
        $guardian->students()->attach($this->profile->id, ['relation' => 'father', 'is_primary' => true]);

        $this->actingAs($guardianUser, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$this->profile->id}/dashboard")
            ->assertOk();
    }

    public function test_an_unrelated_student_is_forbidden(): void
    {
        $otherUser = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$this->profile->id}/dashboard")
            ->assertForbidden();
    }

    public function test_a_management_role_with_student_progress_view_can_view_any_dashboard(): void
    {
        $manager = $this->actorWithPermissions(['student_progress.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$this->profile->id}/dashboard")
            ->assertOk();
    }

    public function test_viewdashboard_is_independent_of_the_broader_view_ability(): void
    {
        // Holds student_profiles.view (the broader, pre-existing ability)
        // but NOT student_progress.view — must still be forbidden from
        // the dashboard, proving viewDashboard() doesn't fall back to
        // view()'s flatter gate.
        $broadViewer = $this->actorWithPermissions(['student_profiles.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($broadViewer, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$this->profile->id}/dashboard")
            ->assertForbidden();
    }

    public function test_a_student_with_no_section_gets_an_empty_dashboard_not_an_error(): void
    {
        $unassignedUser = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $unassignedProfile = StudentProfile::create(['user_id' => $unassignedUser->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $this->actingAs($unassignedUser, 'sanctum')
            ->getJson("/api/v1/student-profiles/{$unassignedProfile->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.class_version_id', null)
            ->assertJsonPath('data.subjects', []);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/StudentDashboardControllerTest.php`
Expected: FAIL — route `/api/v1/student-profiles/{student_profile}/dashboard` does not exist.

- [ ] **Step 3: Add the policy ability** — modify `backend/app/Policies/StudentProfilePolicy.php`, adding after `transferSection()`:

```php
    /**
     * Viewing a student's learning dashboard: the student themselves,
     * their linked guardian, or a management/oversight role holding
     * student_progress.view — deliberately NOT view()'s flat
     * student_profiles.view permission, which the student role already
     * holds broadly and would let any student see any other student's
     * exam scores and video-watching history.
     */
    public function viewDashboard(User $user, StudentProfile $profile): bool
    {
        if ($user->id === $profile->user_id) {
            return true;
        }

        if ($user->hasPermission('student_progress.view')) {
            return true;
        }

        return $user->guardian !== null
            && $user->guardian->students()->where('student_profiles.id', $profile->id)->exists();
    }
```

- [ ] **Step 4: Add the controller action** — modify `backend/app/Http/Controllers/Api/V1/StudentProfileController.php`

Add the import:

```php
use App\Support\StudentDashboardBuilder;
```

Add the method after `transferSection()` and before the private `assertClassBelongsToSchool()` helper:

```php
    /**
     * A read-only rollup of this student's video/material/exam progress
     * per chapter and subject. No Resource class — the payload is a
     * synthetic aggregate computed by StudentDashboardBuilder, not a 1:1
     * transform of this model.
     */
    public function dashboard(StudentProfile $student_profile): JsonResponse
    {
        $this->authorize('viewDashboard', $student_profile);

        return response()->json(['data' => (new StudentDashboardBuilder)->build($student_profile)]);
    }
```

- [ ] **Step 5: Register the route** — modify `backend/routes/api.php`, adding right after the existing `transfer-section` line:

```php
        Route::post('/student-profiles/{student_profile}/transfer-section', [StudentProfileController::class, 'transferSection']);
        Route::get('/student-profiles/{student_profile}/dashboard', [StudentProfileController::class, 'dashboard']);
```

- [ ] **Step 6: Run the tests**

Run: `php artisan test tests/Feature/StudentDashboardControllerTest.php`
Expected: PASS (6 tests).

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS — no regressions in any existing test. This is the last task in the plan.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Policies/StudentProfilePolicy.php app/Http/Controllers/Api/V1/StudentProfileController.php routes/api.php tests/Feature/StudentDashboardControllerTest.php
git commit -m "Add student learning dashboard endpoint with owner+guardian+management visibility"
```
