<?php

namespace Tests\Unit;

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
use Tests\TestCase;

class ContentAccessLogTest extends TestCase
{
    use RefreshDatabase;

    private function makeBook(): Book
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);
        $subject = Subject::create(['name' => 'Mathematics']);

        return Book::create([
            'class_version_id' => $version->id,
            'subject_id' => $subject->id,
            'school_id' => $version->school_id,
            'district_id' => $version->district_id,
            'name' => 'Mathematics Book',
        ]);
    }

    public function test_a_log_row_can_be_created_with_only_a_book_id(): void
    {
        $book = $this->makeBook();

        $log = ContentAccessLog::create([
            'book_id' => $book->id,
            'action' => 'downloaded',
        ]);

        $this->assertNotNull($log->created_at);
        $this->assertNull($log->chapter_id);
        $this->assertNull($log->chapter_material_id);
        $this->assertNull($log->user_id);
    }

    public function test_the_model_has_no_updated_at_column(): void
    {
        $book = $this->makeBook();

        $log = ContentAccessLog::create(['book_id' => $book->id, 'action' => 'previewed']);

        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }
}
