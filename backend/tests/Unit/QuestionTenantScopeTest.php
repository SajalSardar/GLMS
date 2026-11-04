<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class QuestionTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Question $questionA;

    private School $schoolA;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);
        $schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        $subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Class 6']);
        $classB = SchoolClass::create(['school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Class 6']);

        $versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Bangla Version']);

        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'school_id' => $versionA->school_id, 'district_id' => $versionA->district_id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'school_id' => $versionB->school_id, 'district_id' => $versionB->district_id, 'name' => 'Mathematics Book']);

        $chapterA = Chapter::create(['book_id' => $bookA->id, 'school_id' => $bookA->school_id, 'district_id' => $bookA->district_id, 'name' => 'Chapter 1']);
        $chapterB = Chapter::create(['book_id' => $bookB->id, 'school_id' => $bookB->school_id, 'district_id' => $bookB->district_id, 'name' => 'Chapter 1']);

        $this->questionA = Question::create([
            'chapter_id' => $chapterA->id,
            'school_id' => $chapterA->school_id,
            'district_id' => $chapterA->district_id,
            'question_type' => 'mcq_single',
            'question_text' => 'What is 2+2?',
        ]);

        Question::create([
            'chapter_id' => $chapterB->id,
            'school_id' => $chapterB->school_id,
            'district_id' => $chapterB->district_id,
            'question_type' => 'mcq_single',
            'question_text' => 'What is 3+3?',
        ]);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_questions(): void
    {
        $role = Role::create(['name' => 'school_admin_questions', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = Question::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->questionA->id, $visible->first()->id);
    }

    public function test_unscoped_actor_sees_both_schools_questions(): void
    {
        $role = Role::create(['name' => 'national_admin_questions', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, Question::all());
    }

    public function test_defaults_are_applied(): void
    {
        $this->assertSame(1, $this->questionA->marks);
        $this->assertSame('medium', $this->questionA->difficulty);
        $this->assertSame(0, $this->questionA->order_index);
        $this->assertTrue($this->questionA->is_active);
    }

    public function test_question_option_has_no_tenant_columns_and_belongs_to_question(): void
    {
        $option = QuestionOption::create([
            'question_id' => $this->questionA->id,
            'option_text' => '4',
            'is_correct' => true,
        ]);

        $this->assertFalse($this->questionA->getConnection()->getSchemaBuilder()->hasColumn('question_options', 'school_id'));
        $this->assertSame($this->questionA->id, $option->question->id);
        $this->assertTrue($option->is_correct);
        $this->assertSame(0, $option->order_index);
    }

    /**
     * Confirms the denormalize-at-creation pattern was followed
     * correctly, mirroring the same check Book/Chapter's own test suite
     * runs for their denormalized school_id/district_id.
     */
    public function test_denormalized_tenant_columns_match_the_parent_chapter(): void
    {
        // No actor is logged in at this point in the test, so TenantScope
        // is inactive and the relation loads unfiltered.
        $chapter = $this->questionA->chapter;

        $this->assertSame($chapter->school_id, $this->questionA->school_id);
        $this->assertSame($chapter->district_id, $this->questionA->district_id);
    }
}
