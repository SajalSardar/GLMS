<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\ContentAccessLog;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class BookPdfControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Book $book;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Bangla Version']);
        $subject = Subject::create(['name' => 'Mathematics']);

        $this->book = Book::create([
            'class_version_id' => $version->id,
            'subject_id' => $subject->id,
            'school_id' => $version->school_id,
            'district_id' => $version->district_id,
            'name' => 'Mathematics Book',
        ]);
    }

    public function test_upload_requires_books_update_permission(): void
    {
        $denied = $this->actorWithNoPermissions($this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');

        $this->actingAs($denied, 'sanctum')
            ->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file])
            ->assertForbidden();
    }

    public function test_upload_stores_the_file_and_updates_metadata(): void
    {
        $allowed = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');

        $response = $this->actingAs($allowed, 'sanctum')
            ->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);

        $response->assertOk()
            ->assertJsonPath('data.pdf_original_filename', 'math.pdf')
            ->assertJsonPath('data.pdf_status', 'active');

        $this->book->refresh();
        $this->assertTrue($this->book->hasAvailablePdf());
        Storage::disk('local')->assertExists($this->book->pdf_stored_path);
    }

    public function test_upload_rejects_a_non_pdf_file(): void
    {
        $allowed = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.docx', 100, 'application/msword');

        $this->actingAs($allowed, 'sanctum')
            ->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file])
            ->assertUnprocessable();
    }

    public function test_replacing_an_existing_pdf_deletes_the_old_file(): void
    {
        $allowed = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $first = UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $first]);
        $this->book->refresh();
        $oldPath = $this->book->pdf_stored_path;

        $second = UploadedFile::fake()->create('v2.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $second]);

        Storage::disk('local')->assertMissing($oldPath);
        $this->book->refresh();
        $this->assertSame('v2.pdf', $this->book->pdf_original_filename);
    }

    public function test_status_endpoint_archives_and_unarchives(): void
    {
        $allowed = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/books/{$this->book->id}/pdf/status", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.pdf_status', 'archived');

        $this->book->refresh();
        $this->assertFalse($this->book->hasAvailablePdf());
    }

    public function test_delete_removes_the_file_and_clears_metadata(): void
    {
        $allowed = $this->actorWithPermissions(['books.update', 'books.delete'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);
        $this->book->refresh();
        $path = $this->book->pdf_stored_path;

        $this->actingAs($allowed, 'sanctum')
            ->deleteJson("/api/v1/books/{$this->book->id}/pdf")
            ->assertNoContent();

        Storage::disk('local')->assertMissing($path);
        $this->book->refresh();
        $this->assertNull($this->book->pdf_stored_path);
        $this->assertFalse($this->book->hasAvailablePdf());
    }

    public function test_download_link_requires_an_available_pdf(): void
    {
        $allowed = $this->actorWithPermissions(['books.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertUnprocessable();
    }

    public function test_download_link_returns_a_working_signed_url_that_logs_access(): void
    {
        $allowed = $this->actorWithPermissions(['books.update', 'books.view'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);

        $response = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertOk();

        $url = $response->json('download_url');
        $this->assertNotNull($url);

        $this->get($url)->assertOk();

        $this->assertSame(1, ContentAccessLog::where('book_id', $this->book->id)->where('action', 'downloaded')->count());
    }

    public function test_download_rejects_an_invalid_signature(): void
    {
        $this->get("/api/v1/books/{$this->book->id}/pdf/download?signature=invalid&expires=9999999999")
            ->assertForbidden();
    }
}
