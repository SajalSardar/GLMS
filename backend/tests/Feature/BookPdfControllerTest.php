<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\ContentAccessLog;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
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
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
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

        $log = ContentAccessLog::where('book_id', $this->book->id)->where('action', 'downloaded')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, ContentAccessLog::where('book_id', $this->book->id)->where('action', 'downloaded')->count());
        $this->assertSame($allowed->id, $log->user_id);
    }

    /**
     * Replaces an earlier test that asserted the opposite (a guest got a
     * working signed URL back). That behavior was the bug: the signature
     * this endpoint mints is itself the download credential for a
     * private-disk, licensed PDF, and the streaming routes only validate
     * the signature — so an anonymous caller could mint and redeem a
     * credential for any book's PDF bytes. The endpoint is authenticated
     * now, so a guest never gets that far.
     */
    public function test_download_link_is_rejected_for_an_unauthenticated_guest(): void
    {
        $allowed = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);

        // actingAs() caches the user on the 'sanctum' guard instance for
        // the rest of this test, so without forgetting it here the
        // "unauthenticated" request below would still resolve $allowed
        // regardless of headers sent.
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertUnauthorized();

        $this->assertSame(0, ContentAccessLog::where('book_id', $this->book->id)->count());
    }

    /**
     * The guest rejection above must not depend on the book happening to
     * have a PDF — a guest is turned away before any book state is
     * considered (this used to 422 with "no available PDF", which leaked
     * that the endpoint was reachable at all).
     */
    public function test_download_link_is_rejected_for_a_guest_even_without_an_available_pdf(): void
    {
        $this->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertUnauthorized();
    }

    public function test_download_link_requires_books_view_permission(): void
    {
        $uploader = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');
        $this->actingAs($uploader, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);

        $denied = $this->actorWithNoPermissions($this->school->id, $this->school->district_id);

        $this->actingAs($denied, 'sanctum')
            ->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertForbidden();
    }

    /**
     * Non-regression test for the real-world caller shape: a student
     * holding `books.view` (every seeded role holds it) still gets a
     * working link, and does so via a genuine `Authorization: Bearer`
     * header rather than actingAs(). This is the shape that matters:
     * actingAs() calls Auth::shouldUse('sanctum') itself, so a route
     * missing `auth:sanctum` would still look authorized under it — in a
     * real request the default guard is the session-based `web` one until
     * the auth middleware switches it, and $this->authorize() resolves
     * its actor from the default guard, so it would see a guest.
     *
     * The PDF is attached to the model directly rather than through the
     * upload endpoint simply to keep this test to a single request. It no
     * longer has to be: an earlier actingAs() call used to leave `sanctum`
     * as the default guard (forgetGuards() drops resolved guard instances
     * but not AuthManager's default), and TenantScope's Auth::user() would
     * then re-enter the half-resolved Sanctum guard while it loads the
     * token's StudentUser tokenable — infinite recursion. That was a real
     * bug, not a test artifact, and TenantScope now carries a re-entrancy
     * guard for it; see the regression test below.
     */
    public function test_download_link_succeeds_for_a_student_authenticated_with_a_real_bearer_token(): void
    {
        Storage::disk('local')->put('books/pdf/math.pdf', 'fake-pdf-bytes');
        $this->book->update([
            'pdf_original_filename' => 'math.pdf',
            'pdf_stored_path' => 'books/pdf/math.pdf',
            'pdf_disk' => 'local',
            'pdf_mime_type' => 'application/pdf',
            'pdf_size_bytes' => 14,
            'pdf_status' => 'active',
        ]);

        $student = $this->studentActorWithPermissions(['books.view'], $this->school->id, $this->school->district_id);
        $token = $student->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertOk();

        $url = $response->json('download_url');
        $this->assertNotNull($url);

        $this->get($url)->assertOk();

        $log = ContentAccessLog::where('book_id', $this->book->id)->where('action', 'downloaded')->first();
        $this->assertNotNull($log);
        $this->assertSame($student->id, $log->user_id);
        $this->assertSame(StudentUser::class, $log->user_type);
    }

    /**
     * Regression test for an infinite recursion in TenantScope.
     *
     * The trigger is a bearer token being resolved while `sanctum` is
     * ALREADY the default guard — i.e. the second or later resolution in a
     * process, not the first. Sanctum then loads the token's `tokenable`
     * row (a TenantScoped StudentUser query), TenantScope::apply() calls
     * Auth::user() to decide how to scope it, and that re-enters the very
     * same half-resolved Sanctum guard, which loads `tokenable` again —
     * unbounded recursion that exhausted PHP's memory limit.
     *
     * forgetGuards() between the two requests is what makes the second
     * resolution real: it drops the resolved guard instance (so the cached
     * user is gone and the token must be parsed again) while leaving
     * AuthManager's default pointing at `sanctum` from the first request's
     * auth middleware.
     */
    public function test_a_bearer_token_resolves_without_recursion_when_sanctum_is_already_the_default_guard(): void
    {
        Storage::disk('local')->put('books/pdf/math.pdf', 'fake-pdf-bytes');
        $this->book->update([
            'pdf_original_filename' => 'math.pdf',
            'pdf_stored_path' => 'books/pdf/math.pdf',
            'pdf_disk' => 'local',
            'pdf_mime_type' => 'application/pdf',
            'pdf_size_bytes' => 14,
            'pdf_status' => 'active',
        ]);

        $student = $this->studentActorWithPermissions(['books.view'], $this->school->id, $this->school->district_id);
        $token = $student->createToken('test')->plainTextToken;

        // First resolution: the auth middleware makes `sanctum` the default guard.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertOk();

        $this->app['auth']->forgetGuards();

        // Second resolution, now with `sanctum` already the default guard:
        // this is the call that used to recurse until memory was exhausted.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertOk();

        $this->assertSame($student->id, Auth::guard('sanctum')->id());
    }

    /**
     * The re-entrancy guard must not leave tenant scoping switched off:
     * once the actor IS resolved, a TenantScoped query made on their
     * behalf is still restricted to their own school. School is itself
     * TenantScoped, so a school-scoped student may see only their own
     * school row out of the two that exist.
     */
    public function test_tenant_scoping_still_applies_after_a_real_bearer_token_resolution(): void
    {
        School::create([
            'division_id' => $this->school->division_id,
            'district_id' => $this->school->district_id,
            'upazila_id' => $this->school->upazila_id,
            'name' => 'Other School',
        ]);

        $student = $this->studentActorWithPermissions(['books.view'], $this->school->id, $this->school->district_id);
        $token = $student->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertUnprocessable();

        $this->app['auth']->forgetGuards();

        // Resolve the token again with `sanctum` already the default guard
        // — the path that used to recurse — then assert the scope is back
        // on and correctly restricted for the now-resolved actor.
        $this->assertSame($student->id, Auth::guard('sanctum')->id());

        $visibleSchools = School::all();

        $this->assertCount(1, $visibleSchools);
        $this->assertSame($this->school->id, $visibleSchools->first()->id);
    }

    public function test_preview_link_returns_a_working_signed_url_that_logs_access(): void
    {
        $allowed = $this->actorWithPermissions(['books.update', 'books.view'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);

        $response = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/books/{$this->book->id}/pdf/download-link")
            ->assertOk();

        $previewUrl = $response->json('preview_url');
        $this->assertNotNull($previewUrl);

        $this->get($previewUrl)->assertOk();

        $log = ContentAccessLog::where('book_id', $this->book->id)->where('action', 'previewed')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, ContentAccessLog::where('book_id', $this->book->id)->where('action', 'previewed')->count());
        $this->assertSame($allowed->id, $log->user_id);
    }

    public function test_download_rejects_an_invalid_signature(): void
    {
        $this->get("/api/v1/books/{$this->book->id}/pdf/download?signature=invalid&expires=9999999999")
            ->assertForbidden();
    }

    public function test_delete_requires_books_delete_permission(): void
    {
        $allowed = $this->actorWithPermissions(['books.update'], $this->school->id, $this->school->district_id);
        $file = UploadedFile::fake()->create('math.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')->post("/api/v1/books/{$this->book->id}/pdf", ['pdf' => $file]);

        $this->actingAs($allowed, 'sanctum')
            ->deleteJson("/api/v1/books/{$this->book->id}/pdf")
            ->assertForbidden();
    }
}
