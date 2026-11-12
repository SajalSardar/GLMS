<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TeacherCreatedContentAuditTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_question_created_by_a_teacher_records_teacher_user_as_the_polymorphic_actor(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School',
        ]);

        $subject = Subject::create(['name' => 'Mathematics']);

        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class 6']);

        $classVersion = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);

        $book = Book::create(['class_version_id' => $classVersion->id, 'subject_id' => $subject->id, 'school_id' => $classVersion->school_id, 'district_id' => $classVersion->district_id, 'name' => 'Mathematics Book']);

        $chapter = Chapter::create(['book_id' => $book->id, 'school_id' => $book->school_id, 'district_id' => $book->district_id, 'name' => 'Chapter 1']);

        $teacher = $this->teacherActorWithPermissions(['questions.create'], $school->id, $school->district_id);

        $payload = [
            'chapter_id' => $chapter->id,
            'question_type' => 'mcq_single',
            'question_text' => 'What is 2+2?',
            'options' => [
                ['option_text' => '3', 'is_correct' => false],
                ['option_text' => '4', 'is_correct' => true],
            ],
        ];

        $response = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/questions', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.created_by_type', TeacherUser::class)
            ->assertJsonPath('data.created_by_id', $teacher->id);
    }
}
