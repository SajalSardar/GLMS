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

        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);

        $this->chapterA = Chapter::create(['book_id' => $bookA->id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $bookB->id, 'name' => 'Chapter 1']);
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

    public function test_import_reports_a_ragged_row_as_an_error_without_aborting(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        // The middle row is missing its trailing correct_options column
        // (12 fields instead of the header's 13) — this must not crash
        // array_combine() for the whole request; it must be recorded as
        // a per-row error while the rows before and after it still import.
        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n"
            ."{$this->chapterA->id},mcq_single,What is 2+2?,,1,easy,3,4,,,,,2\n"
            ."{$this->chapterA->id},mcq_single,Ragged row,,1,easy,3,4,,,,\n"
            ."{$this->chapterA->id},mcq_single,What is 3+3?,,1,easy,5,6,,,,,2\n";

        $response = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertOk();

        $response->assertJsonPath('imported', 2);
        $response->assertJsonPath('failed', 1);
        $response->assertJsonPath('rows.0.status', 'imported');
        $response->assertJsonPath('rows.1.status', 'error');
        $response->assertJsonPath('rows.1.message', 'Row has a different number of columns than the header.');
        $response->assertJsonPath('rows.2.status', 'imported');

        $this->assertDatabaseCount('questions', 2);
    }

    public function test_import_succeeds_for_a_chapter_belonging_to_a_different_school(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n"
            ."{$this->chapterB->id},mcq_single,Cross-tenant question,,1,easy,3,4,,,,,2\n";

        $response = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertOk();

        $response->assertJsonPath('imported', 1);
        $response->assertJsonPath('failed', 0);
        $this->assertDatabaseCount('questions', 1);
    }

    public function test_import_is_forbidden_without_create_permission(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);

        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n";

        $this->actingAs($denied, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertForbidden();
    }

    public function test_export_produces_a_csv_that_reimports_cleanly(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create', 'questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n"
            ."{$this->chapterA->id},mcq_multiple,Which are even?,,3,hard,1,2,3,4,,,2;4\n";

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertOk()
            ->assertJsonPath('imported', 1);

        $exported = $this->actingAs($allowed, 'sanctum')
            ->get("/api/v1/questions/export?chapter_id={$this->chapterA->id}")
            ->assertOk()
            ->streamedContent();

        $this->assertDatabaseCount('questions', 1);

        $reimported = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($exported)])
            ->assertOk();

        $reimported->assertJsonPath('imported', 1);
        $reimported->assertJsonPath('failed', 0);
        $this->assertDatabaseCount('questions', 2);
    }

    public function test_export_is_forbidden_without_view_permission(): void
    {
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($denied, 'sanctum')
            ->get('/api/v1/questions/export')
            ->assertForbidden();
    }

    public function test_export_is_forbidden_for_a_view_only_actor(): void
    {
        // questions.view is granted to students and other non-management
        // roles — the exported CSV's correct_options column names the
        // answer key, so a view-only actor must not be able to reach it
        // even though they can view questions on screen.
        $viewer = $this->actorWithPermissions(['questions.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($viewer, 'sanctum')
            ->get('/api/v1/questions/export')
            ->assertForbidden();
    }

    public function test_import_rejects_a_marks_value_that_overflows_the_column(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        $csv = "chapter_id,question_type,question_text,bn_question_text,marks,difficulty,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n"
            ."{$this->chapterA->id},mcq_single,Overflowing marks,,99999,easy,3,4,,,,,2\n";

        $response = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertOk();

        $response->assertJsonPath('imported', 0);
        $response->assertJsonPath('failed', 1);
        $response->assertJsonPath('rows.0.status', 'error');
        $this->assertDatabaseCount('questions', 0);
    }

    public function test_import_rejects_a_csv_with_a_malformed_header_row(): void
    {
        $allowed = $this->actorWithPermissions(['questions.create'], $this->schoolA->id, $this->schoolA->district_id);

        // "marks" and "difficulty" are swapped relative to
        // QuestionCsvImporter::HEADERS.
        $csv = "chapter_id,question_type,question_text,bn_question_text,difficulty,marks,option_1,option_2,option_3,option_4,option_5,option_6,correct_options\n"
            ."{$this->chapterA->id},mcq_single,What is 2+2?,,easy,1,3,4,,,,,2\n";

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/questions/import', ['file' => $this->csvFile($csv)])
            ->assertStatus(422);

        $this->assertDatabaseCount('questions', 0);
    }
}
