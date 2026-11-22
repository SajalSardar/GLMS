<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\ClassVersion;
use App\Models\ContentAccessLog;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ChapterMaterialControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Chapter $chapterA;

    private Chapter $chapterB;

    private School $schoolA;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);
        $schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        $subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['name' => 'Class 6']);
        $classB = SchoolClass::create(['name' => 'Class 6 B']);

        $versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Bangla Version']);

        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);

        $this->chapterA = Chapter::create(['book_id' => $bookA->id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $bookB->id, 'name' => 'Chapter 1']);
    }

    public function test_store_and_update_are_authorized(): void
    {
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');
        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($denied, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $file])
            ->assertForbidden();

        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);

        $createResponse = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $created = $createResponse->json('data.id');

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$created}", ['order_index' => 2])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_only_one_active_primary_material_allowed_per_chapter(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $file])
            ->assertCreated();

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $file])
            ->assertUnprocessable();
    }

    public function test_reactivating_a_material_is_rejected_if_another_active_primary_exists(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $firstId = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $file])
            ->json('data.id');

        // Step 1: archive the chapter's only active primary material.
        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$firstId}", ['status' => 'archived'])
            ->assertOk();

        // Step 2: create a new primary material for the same chapter — succeeds, since
        // there is no active primary right now.
        $secondFile = UploadedFile::fake()->create('chapter1-v2.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $secondFile])
            ->assertCreated();

        // Step 3: re-activating the first (now-archived) material must be rejected,
        // since it would leave two active primaries for the same chapter.
        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$firstId}", ['status' => 'active'])
            ->assertUnprocessable();
    }

    public function test_two_sections_can_each_have_their_own_active_primary_material_for_the_same_chapter(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacherOne = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $teacherTwo = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $sectionOne = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        $sectionTwo = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section B']);
        SubjectTeacherAssignment::create(['section_id' => $sectionOne->id, 'subject_id' => $subjectId, 'teacher_id' => $teacherOne->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        SubjectTeacherAssignment::create(['section_id' => $sectionTwo->id, 'subject_id' => $subjectId, 'teacher_id' => $teacherTwo->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $fileOne = UploadedFile::fake()->create('chapter1-a.pdf', 100, 'application/pdf');
        $fileTwo = UploadedFile::fake()->create('chapter1-b.pdf', 100, 'application/pdf');

        $this->actingAs($teacherOne, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $sectionOne->id, 'type' => 'primary', 'file' => $fileOne])
            ->assertCreated();

        $this->actingAs($teacherTwo, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $sectionTwo->id, 'type' => 'primary', 'file' => $fileTwo])
            ->assertCreated();
    }

    public function test_multiple_supplementary_materials_are_allowed(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('extra.pdf', 100, 'application/pdf');

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'supplementary', 'title' => 'Extra 1', 'file' => $file])
            ->assertCreated();

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'supplementary', 'title' => 'Extra 2', 'file' => $file])
            ->assertCreated();

        $this->assertSame(2, ChapterMaterial::where('chapter_id', $this->chapterA->id)->where('type', 'supplementary')->count());
    }

    public function test_store_accepts_an_image_file_and_stores_it_with_its_real_extension(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $image = UploadedFile::fake()->create('scan.jpg', 100, 'image/jpeg');

        $response = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $image])
            ->assertCreated()
            ->assertJsonPath('data.original_filename', 'scan.jpg');

        $material = ChapterMaterial::find($response->json('data.id'));
        $this->assertStringEndsWith('.jpg', $material->stored_path);
        Storage::disk('local')->assertExists($material->stored_path);
    }

    public function test_store_rejects_an_unsupported_file_type(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_update_replaces_the_file_with_a_different_type_and_stores_the_new_extension(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);
        $first = UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $first])
            ->json('data.id');

        $image = UploadedFile::fake()->create('v2.png', 100, 'image/png');
        $this->actingAs($allowed, 'sanctum')
            ->post("/api/v1/chapter-materials/{$id}", ['_method' => 'PATCH', 'file' => $image])
            ->assertOk()
            ->assertJsonPath('data.original_filename', 'v2.png');

        $material = ChapterMaterial::find($id);
        $this->assertStringEndsWith('.png', $material->stored_path);
    }

    public function test_actor_with_permission_can_update_a_material_belonging_to_a_different_schools_chapter(): void
    {
        $material = ChapterMaterial::create([
            'chapter_id' => $this->chapterB->id,
            'type' => 'primary',
            'title' => 'School B Material',
            'original_filename' => 'notes.pdf',
            'stored_path' => 'demo/notes.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'order_index' => 1,
        ]);

        $actor = $this->actorWithPermissions(['chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$material->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');
    }

    public function test_update_can_replace_the_file(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);
        $first = UploadedFile::fake()->create('v1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $first])
            ->json('data.id');

        $material = ChapterMaterial::find($id);
        $oldPath = $material->stored_path;

        $second = UploadedFile::fake()->create('v2.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')
            ->post("/api/v1/chapter-materials/{$id}", ['_method' => 'PATCH', 'file' => $second])
            ->assertOk()
            ->assertJsonPath('data.original_filename', 'v2.pdf');

        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_destroy_deletes_the_physical_file(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.delete'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $file])
            ->json('data.id');

        $path = ChapterMaterial::find($id)->stored_path;

        $this->actingAs($allowed, 'sanctum')
            ->deleteJson("/api/v1/chapter-materials/{$id}")
            ->assertNoContent();

        Storage::disk('local')->assertMissing($path);
    }

    public function test_download_link_logs_access(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $file])
            ->json('data.id');

        $url = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/chapter-materials/{$id}/download-link")
            ->assertOk()
            ->json('download_url');

        $this->get($url)->assertOk();

        $log = ContentAccessLog::where('chapter_material_id', $id)->where('action', 'downloaded')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, ContentAccessLog::where('chapter_material_id', $id)->where('action', 'downloaded')->count());
        $this->assertSame($allowed->id, $log->user_id);
    }

    public function test_preview_link_returns_a_working_signed_url_that_logs_access(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $file])
            ->json('data.id');

        $response = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/chapter-materials/{$id}/download-link")
            ->assertOk();

        $previewUrl = $response->json('preview_url');
        $this->assertNotNull($previewUrl);

        $this->get($previewUrl)->assertOk();

        $log = ContentAccessLog::where('chapter_material_id', $id)->where('action', 'previewed')->first();
        $this->assertNotNull($log);
        $this->assertSame(1, ContentAccessLog::where('chapter_material_id', $id)->where('action', 'previewed')->count());
        $this->assertSame($allowed->id, $log->user_id);
    }

    public function test_archived_material_download_link_returns_404(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.update', 'chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $file])
            ->json('data.id');

        $url = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/chapter-materials/{$id}/download-link")
            ->assertOk()
            ->json('download_url');

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$id}", ['status' => 'archived'])
            ->assertOk();

        $this->get($url)->assertNotFound();
    }

    public function test_store_scopes_the_material_to_the_teachers_assigned_section(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $section->id, 'type' => 'primary', 'file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.section_id', $section->id)
            ->assertJsonPath('data.shift_id', $shift->id)
            ->assertJsonPath('data.class_version_id', $classVersionId)
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.district_id', $this->schoolA->district_id);
    }

    public function test_store_requires_section_id_for_a_teacher_actor(): void
    {
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('section_id');
    }

    public function test_store_is_forbidden_when_the_teacher_has_no_subject_assignment_for_the_section(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $section->id, 'type' => 'primary', 'file' => $file])
            ->assertForbidden();
    }

    public function test_store_is_forbidden_when_the_assignment_is_for_a_different_subject(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        $otherSubject = Subject::create(['name' => 'English']);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $otherSubject->id, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $section->id, 'type' => 'primary', 'file' => $file])
            ->assertForbidden();
    }

    public function test_store_is_forbidden_when_the_section_and_chapter_are_different_class_versions(): void
    {
        $subjectId = $this->chapterA->book->subject_id;
        // chapterB's book belongs to a different class_version (and school)
        // than chapterA — build the section's shift on THAT class_version,
        // so the section/chapter class_version mismatch branch triggers
        // even though a real, matching SubjectTeacherAssignment exists.
        $otherClassVersionId = $this->chapterB->book->class_version_id;
        $shift = Shift::create(['class_version_id' => $otherClassVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $section->id, 'type' => 'primary', 'file' => $file])
            ->assertForbidden();
    }

    public function test_a_student_outside_the_materials_section_cannot_see_or_download_it(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $classId = $this->chapterA->book->classVersion->class_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $ownSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        $otherSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section B']);
        SubjectTeacherAssignment::create(['section_id' => $ownSection->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');
        $materialId = $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $ownSection->id, 'type' => 'primary', 'file' => $file])
            ->json('data.id');

        $outsideStudent = $this->studentActorWithPermissions(['chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);
        StudentProfile::create(['user_id' => $outsideStudent->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'section_id' => $otherSection->id, 'shift_id' => $shift->id, 'class_version_id' => $classVersionId, 'class_id' => $classId]);

        $this->actingAs($outsideStudent, 'sanctum')
            ->getJson("/api/v1/chapter-materials?chapter_id={$this->chapterA->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($outsideStudent, 'sanctum')
            ->postJson("/api/v1/chapter-materials/{$materialId}/download-link")
            ->assertForbidden();

        $insideStudent = $this->studentActorWithPermissions(['chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);
        StudentProfile::create(['user_id' => $insideStudent->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'section_id' => $ownSection->id, 'shift_id' => $shift->id, 'class_version_id' => $classVersionId, 'class_id' => $classId]);

        $this->actingAs($insideStudent, 'sanctum')
            ->getJson("/api/v1/chapter-materials?chapter_id={$this->chapterA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($insideStudent, 'sanctum')
            ->postJson("/api/v1/chapter-materials/{$materialId}/download-link")
            ->assertOk();
    }

    public function test_an_unscoped_material_is_visible_to_a_guest_but_not_a_logged_in_student(): void
    {
        $material = ChapterMaterial::create([
            'chapter_id' => $this->chapterA->id,
            'type' => 'primary',
            'title' => 'Legacy Material',
            'original_filename' => 'notes.pdf',
            'stored_path' => 'demo/notes.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'order_index' => 1,
        ]);

        $this->getJson("/api/v1/chapter-materials?chapter_id={$this->chapterA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // A logged-in student never falls back to "everyone" materials —
        // an unscoped one was never actually verified to belong to their
        // class, unlike a guest (who has no profile to compare against
        // at all, so showing them the unscoped ones is the only sensible
        // default).
        $studentWithNoProfile = $this->studentActorWithPermissions(['chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($studentWithNoProfile, 'sanctum')
            ->getJson("/api/v1/chapter-materials?chapter_id={$this->chapterA->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($studentWithNoProfile, 'sanctum')
            ->postJson("/api/v1/chapter-materials/{$material->id}/download-link")
            ->assertForbidden();
    }

    public function test_mine_returns_only_materials_matching_the_students_own_full_scope_tuple(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $classId = $this->chapterA->book->classVersion->class_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $ownSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        $otherSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section B']);
        SubjectTeacherAssignment::create(['section_id' => $ownSection->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');
        $scopedMaterialId = $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $ownSection->id, 'type' => 'primary', 'file' => $file])
            ->json('data.id');

        // A legacy/unscoped material (section_id null) must NOT appear via
        // /mine, same as index() — neither one falls back to "visible to
        // everyone" for a logged-in student.
        ChapterMaterial::create([
            'chapter_id' => $this->chapterA->id,
            'type' => 'supplementary',
            'title' => 'Legacy Material',
            'original_filename' => 'notes.pdf',
            'stored_path' => 'demo/notes.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'order_index' => 2,
        ]);

        $studentInSection = $this->studentActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        StudentProfile::create([
            'user_id' => $studentInSection->id,
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'section_id' => $ownSection->id,
            'shift_id' => $shift->id,
            'class_version_id' => $classVersionId,
            'class_id' => $classId,
        ]);

        $this->actingAs($studentInSection, 'sanctum')
            ->getJson('/api/v1/chapter-materials/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $scopedMaterialId);

        $studentInOtherSection = $this->studentActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        StudentProfile::create([
            'user_id' => $studentInOtherSection->id,
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'section_id' => $otherSection->id,
            'shift_id' => $shift->id,
            'class_version_id' => $classVersionId,
            'class_id' => $classId,
        ]);

        $this->actingAs($studentInOtherSection, 'sanctum')
            ->getJson('/api/v1/chapter-materials/mine')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_mine_is_empty_for_a_student_with_no_section_assigned(): void
    {
        $student = $this->studentActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        StudentProfile::create(['user_id' => $student->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/chapter-materials/mine')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_mine_is_forbidden_for_a_non_student_actor(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/chapter-materials/mine')
            ->assertForbidden();
    }

    public function test_teacher_can_update_their_own_uploaded_material(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $section->id, 'type' => 'primary', 'file' => $file])
            ->json('data.id');

        $this->actingAs($teacher, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$id}", ['order_index' => 3])
            ->assertOk();
    }

    public function test_teacher_cannot_update_another_teachers_uploaded_material(): void
    {
        $otherTeacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $material = ChapterMaterial::create([
            'chapter_id' => $this->chapterA->id,
            'type' => 'primary',
            'title' => 'Someone elses material',
            'original_filename' => 'notes.pdf',
            'stored_path' => 'demo/notes.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'order_index' => 1,
            'uploaded_by_id' => $otherTeacher->id,
        ]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$material->id}", ['title' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_teacher_cannot_delete_another_teachers_uploaded_material(): void
    {
        $otherTeacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $material = ChapterMaterial::create([
            'chapter_id' => $this->chapterA->id,
            'type' => 'primary',
            'title' => 'Someone elses material',
            'original_filename' => 'notes.pdf',
            'stored_path' => 'demo/notes.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'order_index' => 1,
            'uploaded_by_id' => $otherTeacher->id,
        ]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/chapter-materials/{$material->id}")
            ->assertForbidden();
    }

    /**
     * Regression test: `uploaded_by_id` is a bare integer with no type
     * discrimination, and actors come from independent tables (users,
     * teacher_users, ...) each with their own auto-increment id sequence
     * — so a staff `User` and a `TeacherUser` can land on the SAME id.
     * Both tables are freshly empty at the start of this test, so the
     * first row created in each gets id 1 — a deliberate id collision.
     * Ownership checks (ChapterMaterialPolicy::update()/delete() and
     * ChapterMaterialController::myUploads()) must key off the
     * Auditable-stamped (created_by_type, created_by_id) pair, not the
     * bare uploaded_by_id column, or the teacher would be able to
     * touch/claim material actually uploaded by the colliding staff actor.
     */
    public function test_teacher_cannot_touch_material_whose_uploaded_by_id_collides_with_a_different_actor_table(): void
    {
        // First row in `users` — id 1 in this fresh test transaction.
        $staffActor = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        // First row in `teacher_users` — also id 1. This is the colliding actor.
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.update', 'chapter_materials.delete'], $this->schoolA->id, $this->schoolA->district_id);
        $this->assertSame($staffActor->id, $teacher->id);

        // Create the material while acting as the staff actor, so the
        // Auditable trait stamps created_by_type/created_by_id to the
        // staff User — and set uploaded_by_id to that same colliding
        // integer, to simulate the exact bug scenario.
        $this->actingAs($staffActor, 'sanctum');
        $material = ChapterMaterial::create([
            'chapter_id' => $this->chapterA->id,
            'type' => 'primary',
            'title' => 'Staff Material',
            'original_filename' => 'notes.pdf',
            'stored_path' => 'demo/notes.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'order_index' => 1,
            'uploaded_by_id' => $staffActor->id,
        ]);
        $this->assertSame(User::class, $material->created_by_type);
        $this->assertSame($staffActor->id, $material->created_by_id);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/chapter-materials/my-uploads')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($teacher, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$material->id}", ['title' => 'Hijacked'])
            ->assertForbidden();

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/chapter-materials/{$material->id}")
            ->assertForbidden();
    }

    public function test_my_assignments_returns_only_this_teachers_subject_assignments(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $otherTeacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        // A different section for the other teacher's assignment, since
        // (section_id, subject_id) is unique — only one teacher can ever
        // be assigned to a given section/subject pair.
        $otherSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section B']);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        SubjectTeacherAssignment::create(['section_id' => $otherSection->id, 'subject_id' => $subjectId, 'teacher_id' => $otherTeacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/chapter-materials/my-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.section_id', $section->id)
            ->assertJsonPath('data.0.subject_id', $subjectId);
    }

    public function test_my_assignments_is_forbidden_for_a_non_teacher_actor(): void
    {
        $staff = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/chapter-materials/my-assignments')
            ->assertForbidden();
    }

    public function test_my_uploads_returns_only_this_teachers_own_materials(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $section->id, 'type' => 'primary', 'file' => $file])
            ->json('data.id');

        $otherTeacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($otherTeacher, 'sanctum')
            ->getJson('/api/v1/chapter-materials/my-uploads')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/chapter-materials/my-uploads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_my_uploads_is_forbidden_for_a_non_teacher_actor(): void
    {
        $staff = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/chapter-materials/my-uploads')
            ->assertForbidden();
    }
}
