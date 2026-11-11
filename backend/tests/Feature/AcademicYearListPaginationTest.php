<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicYearListPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchool(): School
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        return School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
            'type' => 'government',
            'level' => 'secondary',
        ]);
    }

    public function test_academic_year_index_is_paginated_and_includes_its_school(): void
    {
        $school = $this->makeSchool();

        for ($i = 1; $i <= 18; $i++) {
            AcademicYear::create([
                'school_id' => $school->id,
                'district_id' => $school->district_id,
                'name' => "Year {$i}",
                'start_date' => "202{$i}-01-01",
                'end_date' => "202{$i}-12-31",
            ]);
        }

        $response = $this->getJson('/api/v1/academic-years');

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('meta.total', 18);
        $response->assertJsonPath('data.0.school.id', $school->id);
    }

    public function test_academic_year_index_filters_by_school_id_while_paginated(): void
    {
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();

        AcademicYear::create(['school_id' => $schoolA->id, 'district_id' => $schoolA->district_id, 'name' => 'A1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        AcademicYear::create(['school_id' => $schoolA->id, 'district_id' => $schoolA->district_id, 'name' => 'A2', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        AcademicYear::create(['school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'B1', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $this->getJson("/api/v1/academic-years?school_id={$schoolA->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_academic_year_index_filters_by_search(): void
    {
        $school = $this->makeSchool();

        AcademicYear::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Session 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        AcademicYear::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Session 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $this->getJson('/api/v1/academic-years?search=2026')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Session 2026');
    }
}
