<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookPdfTest extends TestCase
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

    public function test_book_has_no_available_pdf_by_default(): void
    {
        $book = $this->makeBook();

        $this->assertFalse($book->hasAvailablePdf());
    }

    public function test_book_has_available_pdf_once_uploaded_and_active(): void
    {
        $book = $this->makeBook();
        $book->update([
            'pdf_original_filename' => 'math.pdf',
            'pdf_stored_path' => 'books/1/pdf/abc.pdf',
            'pdf_disk' => 'local',
            'pdf_mime_type' => 'application/pdf',
            'pdf_size_bytes' => 1024,
            'pdf_status' => 'active',
        ]);

        $this->assertTrue($book->hasAvailablePdf());
    }

    public function test_book_does_not_have_available_pdf_once_archived(): void
    {
        $book = $this->makeBook();
        $book->update([
            'pdf_original_filename' => 'math.pdf',
            'pdf_stored_path' => 'books/1/pdf/abc.pdf',
            'pdf_disk' => 'local',
            'pdf_mime_type' => 'application/pdf',
            'pdf_size_bytes' => 1024,
            'pdf_status' => 'archived',
        ]);

        $this->assertFalse($book->hasAvailablePdf());
    }
}
