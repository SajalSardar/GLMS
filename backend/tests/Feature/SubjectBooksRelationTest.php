<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class SubjectBooksRelationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_subject_destroy_is_blocked_while_it_still_has_books(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);

        $subject = Subject::create(['name' => 'Mathematics']);
        Book::create(['class_version_id' => $version->id, 'subject_id' => $subject->id, 'school_id' => $version->school_id, 'district_id' => $version->district_id, 'name' => 'Mathematics Book']);

        $actor = $this->actorWithPermissions(['subjects.delete'], null, null);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/subjects/{$subject->id}")
            ->assertUnprocessable();
    }

    public function test_subject_destroy_is_blocked_by_a_book_belonging_to_another_school(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);

        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);

        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);
        $schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        $classB = SchoolClass::create(['school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Class 6']);
        $versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Bangla Version']);

        $subject = Subject::create(['name' => 'Mathematics']);
        Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'school_id' => $versionB->school_id, 'district_id' => $versionB->district_id, 'name' => 'Mathematics Book']);

        // Actor belongs to School A; the only book under this subject belongs
        // to School B. The guard must still see it despite Book being
        // tenant-scoped, proving it bypasses the tenant global scope.
        $actor = $this->actorWithPermissions(['subjects.delete'], $schoolA->id, $schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/subjects/{$subject->id}")
            ->assertUnprocessable();
    }

    public function test_subject_show_no_longer_exposes_a_chapters_key(): void
    {
        $subject = Subject::create(['name' => 'Mathematics']);

        $response = $this->getJson("/api/v1/subjects/{$subject->id}");

        $response->assertOk()->assertJsonMissingPath('data.chapters');
    }
}
