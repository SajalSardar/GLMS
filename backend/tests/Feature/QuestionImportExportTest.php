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
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class QuestionImportExportTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Chapter $chapterA;

    private Chapter $chapterB;

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

        $this->chapterA = Chapter::create(['book_id' => $bookA->id, 'school_id' => $bookA->school_id, 'district_id' => $bookA->district_id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $bookB->id, 'school_id' => $bookB->school_id, 'district_id' => $bookB->district_id, 'name' => 'Chapter 1']);
    }

    private function csvFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'questions').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'questions.csv', 'text/csv', null, true);
    }

    public function test_import_creates_valid_rows_and_reports_bad_rows_without_aborting(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n"
            ."{$this->chapterA->id},mcq_single,What is 2+2?,,1,easy,3,4,,,,,2\n"
            .'999999,mcq_single,Bad chapter,,1,easy,3,4,,,,,2'."\n"
            ."{$this->chapterA->id},essay,Unsupported type,,1,easy,3,4,,,,,2\n";

        $response = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertOk();

        $response->assertJsonPath('imported', 1);
        $response->assertJsonPath('failed', 2);
        $response->assertJsonPath('rows.0.status', 'imported');
        $response->assertJsonPath('rows.1.status', 'error');
        $response->assertJsonPath('rows.2.status', 'error');

        $this->assertDatabaseCount('questions', 1);
    }

    public function test_import_cannot_target_a_chapter_outside_the_actors_tenant(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n"
            ."{$this->chapterB->id},mcq_single,Cross-tenant question,,1,easy,3,4,,,,,2\n";

        $response = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertOk();

        $response->assertJsonPath('imported', 0);
        $response->assertJsonPath('failed', 1);
        $this->assertDatabaseCount('questions', 0);
    }

    public function test_import_is_forbidden_without_create_permission(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);

        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n";

        $this->actingAs($denied, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertForbidden();
    }
}
