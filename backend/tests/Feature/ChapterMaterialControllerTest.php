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
use App\Models\Upazila;
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
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertForbidden();

        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);

        $createResponse = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
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
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertCreated();

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertUnprocessable();
    }

    public function test_reactivating_a_material_is_rejected_if_another_active_primary_exists(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $firstId = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->json('data.id');

        // Step 1: archive the chapter's only active primary material.
        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$firstId}", ['status' => 'archived'])
            ->assertOk();

        // Step 2: create a new primary material for the same chapter — succeeds, since
        // there is no active primary right now.
        $secondFile = UploadedFile::fake()->create('chapter1-v2.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $secondFile])
            ->assertCreated();

        // Step 3: re-activating the first (now-archived) material must be rejected,
        // since it would leave two active primaries for the same chapter.
        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$firstId}", ['status' => 'active'])
            ->assertUnprocessable();
    }

    public function test_multiple_supplementary_materials_are_allowed(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('extra.pdf', 100, 'application/pdf');

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'supplementary', 'title' => 'Extra 1', 'pdf' => $file])
            ->assertCreated();

        $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'supplementary', 'title' => 'Extra 2', 'pdf' => $file])
            ->assertCreated();

        $this->assertSame(2, ChapterMaterial::where('chapter_id', $this->chapterA->id)->where('type', 'supplementary')->count());
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
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $first])
            ->json('data.id');

        $material = ChapterMaterial::find($id);
        $oldPath = $material->stored_path;

        $second = UploadedFile::fake()->create('v2.pdf', 100, 'application/pdf');
        $this->actingAs($allowed, 'sanctum')
            ->post("/api/v1/chapter-materials/{$id}", ['_method' => 'PATCH', 'pdf' => $second])
            ->assertOk()
            ->assertJsonPath('data.original_filename', 'v2.pdf');

        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_destroy_deletes_the_physical_file(): void
    {
        $allowed = $this->actorWithPermissions(['chapter_materials.create', 'chapter_materials.delete'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($allowed, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
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
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
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
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
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
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
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

    public function test_store_scopes_the_material_to_the_uploading_teachers_own_matching_section(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A', 'class_teacher_id' => $teacher->id]);

        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertCreated()
            ->assertJsonPath('data.section_id', $section->id)
            ->assertJsonPath('data.shift_id', $shift->id)
            ->assertJsonPath('data.class_version_id', $classVersionId)
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.district_id', $this->schoolA->district_id);
    }

    public function test_store_leaves_scope_null_when_the_teacher_has_no_matching_section(): void
    {
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertCreated()
            ->assertJsonPath('data.section_id', null)
            ->assertJsonPath('data.shift_id', null);
    }

    public function test_a_student_outside_the_materials_section_cannot_see_or_download_it(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $ownSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A', 'class_teacher_id' => $teacher->id]);
        $otherSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section B']);

        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');
        $materialId = $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->json('data.id');

        $outsideStudent = $this->studentActorWithPermissions(['chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);
        StudentProfile::create(['user_id' => $outsideStudent->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'section_id' => $otherSection->id]);

        $this->actingAs($outsideStudent, 'sanctum')
            ->getJson("/api/v1/chapter-materials?chapter_id={$this->chapterA->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($outsideStudent, 'sanctum')
            ->postJson("/api/v1/chapter-materials/{$materialId}/download-link")
            ->assertForbidden();

        $insideStudent = $this->studentActorWithPermissions(['chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);
        StudentProfile::create(['user_id' => $insideStudent->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'section_id' => $ownSection->id]);

        $this->actingAs($insideStudent, 'sanctum')
            ->getJson("/api/v1/chapter-materials?chapter_id={$this->chapterA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($insideStudent, 'sanctum')
            ->postJson("/api/v1/chapter-materials/{$materialId}/download-link")
            ->assertOk();
    }

    public function test_an_unscoped_material_remains_visible_to_everyone(): void
    {
        ChapterMaterial::create([
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

        $student = $this->studentActorWithPermissions(['chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($student, 'sanctum')
            ->getJson("/api/v1/chapter-materials?chapter_id={$this->chapterA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_mine_returns_only_materials_matching_the_students_own_full_scope_tuple(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $classId = $this->chapterA->book->classVersion->class_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $ownSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A', 'class_teacher_id' => $teacher->id]);
        $otherSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section B']);

        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');
        $scopedMaterialId = $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->json('data.id');

        // A legacy/unscoped material (section_id null) must NOT appear via
        // /mine — unlike index(), which deliberately shows it to everyone,
        // /mine only answers "is there a material matching MY exact scope."
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
}
