<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassVersionVersionRelationTest extends TestCase
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
        ]);
    }

    public function test_name_and_bn_name_proxy_from_the_linked_version(): void
    {
        $school = $this->makeSchool();
        $class = SchoolClass::create(['name' => 'Class Six']);
        $bangla = Version::where('name', 'Bangla')->first();

        $classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'version_id' => $bangla->id,
        ]);

        $this->assertSame('Bangla', $classVersion->name);
        $this->assertSame($bangla->bn_name, $classVersion->bn_name);
    }

    public function test_name_is_null_without_a_version_id(): void
    {
        $classVersion = new ClassVersion;

        $this->assertNull($classVersion->name);
        $this->assertNull($classVersion->bn_name);
    }

    public function test_duplicate_version_id_for_same_class_and_school_is_rejected(): void
    {
        $school = $this->makeSchool();
        $class = SchoolClass::create(['name' => 'Class Six']);
        $bangla = Version::where('name', 'Bangla')->first();

        ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'version_id' => $bangla->id,
        ]);

        $this->expectException(QueryException::class);

        ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'version_id' => $bangla->id,
        ]);
    }
}
