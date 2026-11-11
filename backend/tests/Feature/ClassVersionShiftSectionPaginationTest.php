<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassVersionShiftSectionPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function makeClass(): SchoolClass
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
            'type' => 'government',
            'level' => 'secondary',
        ]);

        return SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class One']);
    }

    private function makeShift(ClassVersion $version): Shift
    {
        return Shift::create([
            'class_version_id' => $version->id,
            'school_id' => $version->school_id,
            'district_id' => $version->district_id,
            'name' => 'Morning',
            'start_time' => '08:00',
            'end_time' => '12:00',
            'days_of_week' => ['saturday', 'sunday'],
        ]);
    }

    public function test_class_version_index_is_paginated_and_includes_its_class(): void
    {
        $class = $this->makeClass();

        for ($i = 1; $i <= 18; $i++) {
            ClassVersion::create(['class_id' => $class->id, 'school_id' => $class->school_id, 'district_id' => $class->district_id, 'name' => "Version {$i}"]);
        }

        $response = $this->getJson('/api/v1/class-versions');

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('meta.total', 18);
        $response->assertJsonPath('data.0.class.id', $class->id);
    }

    public function test_class_version_index_filters_by_class_id_and_search_while_paginated(): void
    {
        $class = $this->makeClass();

        ClassVersion::create(['class_id' => $class->id, 'school_id' => $class->school_id, 'district_id' => $class->district_id, 'name' => 'English']);
        ClassVersion::create(['class_id' => $class->id, 'school_id' => $class->school_id, 'district_id' => $class->district_id, 'name' => 'Bangla']);

        $this->getJson("/api/v1/class-versions?class_id={$class->id}&search=eng")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'English');
    }

    public function test_shift_index_is_paginated_and_includes_its_class_version(): void
    {
        $class = $this->makeClass();
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $class->school_id, 'district_id' => $class->district_id, 'name' => 'English']);

        for ($i = 1; $i <= 16; $i++) {
            Shift::create([
                'class_version_id' => $version->id,
                'school_id' => $version->school_id,
                'district_id' => $version->district_id,
                'name' => "Shift {$i}",
                'start_time' => '08:00',
                'end_time' => '12:00',
                'days_of_week' => ['saturday'],
            ]);
        }

        $response = $this->getJson('/api/v1/shifts');

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('meta.total', 16);
        $response->assertJsonPath('data.0.class_version.id', $version->id);
    }

    public function test_shift_index_filters_by_class_version_id_while_paginated(): void
    {
        $class = $this->makeClass();
        $versionA = ClassVersion::create(['class_id' => $class->id, 'school_id' => $class->school_id, 'district_id' => $class->district_id, 'name' => 'English']);
        $versionB = ClassVersion::create(['class_id' => $class->id, 'school_id' => $class->school_id, 'district_id' => $class->district_id, 'name' => 'Bangla']);

        $this->makeShift($versionA);
        Shift::create([
            'class_version_id' => $versionA->id, 'school_id' => $versionA->school_id, 'district_id' => $versionA->district_id,
            'name' => 'Day', 'start_time' => '13:00', 'end_time' => '17:00', 'days_of_week' => ['sunday'],
        ]);
        $this->makeShift($versionB);

        $this->getJson("/api/v1/shifts?class_version_id={$versionA->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_section_index_is_paginated_and_includes_its_shift(): void
    {
        $class = $this->makeClass();
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $class->school_id, 'district_id' => $class->district_id, 'name' => 'English']);
        $shift = $this->makeShift($version);

        for ($i = 1; $i <= 17; $i++) {
            Section::create(['shift_id' => $shift->id, 'school_id' => $shift->school_id, 'district_id' => $shift->district_id, 'name' => "Section {$i}"]);
        }

        $response = $this->getJson('/api/v1/sections');

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('meta.total', 17);
        $response->assertJsonPath('data.0.shift.id', $shift->id);
    }

    public function test_section_index_filters_by_shift_id_and_search_while_paginated(): void
    {
        $class = $this->makeClass();
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $class->school_id, 'district_id' => $class->district_id, 'name' => 'English']);
        $shiftA = $this->makeShift($version);
        $shiftB = Shift::create([
            'class_version_id' => $version->id, 'school_id' => $version->school_id, 'district_id' => $version->district_id,
            'name' => 'Day', 'start_time' => '13:00', 'end_time' => '17:00', 'days_of_week' => ['sunday'],
        ]);

        Section::create(['shift_id' => $shiftA->id, 'school_id' => $shiftA->school_id, 'district_id' => $shiftA->district_id, 'name' => 'A Section']);
        Section::create(['shift_id' => $shiftA->id, 'school_id' => $shiftA->school_id, 'district_id' => $shiftA->district_id, 'name' => 'B Section']);
        Section::create(['shift_id' => $shiftB->id, 'school_id' => $shiftB->school_id, 'district_id' => $shiftB->district_id, 'name' => 'A Section']);

        $this->getJson("/api/v1/sections?shift_id={$shiftA->id}&search=A")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'A Section');
    }
}
