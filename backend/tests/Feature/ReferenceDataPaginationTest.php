<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceDataPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_division_index_is_paginated(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            Division::create(['name' => "Division {$i}", 'bn_name' => "বিভাগ {$i}"]);
        }

        $response = $this->getJson('/api/v1/divisions');

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('meta.per_page', 15);
        $response->assertJsonPath('meta.total', 20);

        $this->getJson('/api/v1/divisions?per_page=5')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.per_page', 5);
    }

    public function test_division_index_filters_by_search(): void
    {
        Division::create(['name' => 'Dhaka', 'bn_name' => 'ঢাকা']);
        Division::create(['name' => 'Chattogram', 'bn_name' => 'চট্টগ্রাম']);

        $this->getJson('/api/v1/divisions?search=dha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Dhaka');
    }

    public function test_district_index_is_paginated_and_includes_its_division(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);

        for ($i = 1; $i <= 18; $i++) {
            District::create(['division_id' => $division->id, 'name' => "District {$i}", 'bn_name' => "জেলা {$i}"]);
        }

        $response = $this->getJson('/api/v1/districts');

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('meta.total', 18);
        $response->assertJsonPath('data.0.division.id', $division->id);
    }

    public function test_district_index_still_filters_by_division_id_while_paginated(): void
    {
        $divisionA = Division::create(['name' => 'Division A', 'bn_name' => 'বিভাগ এ']);
        $divisionB = Division::create(['name' => 'Division B', 'bn_name' => 'বিভাগ বি']);

        for ($i = 1; $i <= 3; $i++) {
            District::create(['division_id' => $divisionA->id, 'name' => "District A{$i}", 'bn_name' => "জেলা এ{$i}"]);
        }
        for ($i = 1; $i <= 2; $i++) {
            District::create(['division_id' => $divisionB->id, 'name' => "District B{$i}", 'bn_name' => "জেলা বি{$i}"]);
        }

        $this->getJson("/api/v1/districts?division_id={$divisionA->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_district_index_filters_by_search(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        District::create(['division_id' => $division->id, 'name' => 'Comilla', 'bn_name' => 'কুমিল্লা']);
        District::create(['division_id' => $division->id, 'name' => 'Rajshahi', 'bn_name' => 'রাজশাহী']);

        $this->getJson('/api/v1/districts?search=comi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Comilla');
    }

    public function test_upazila_index_is_paginated_and_includes_its_district(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);

        for ($i = 1; $i <= 17; $i++) {
            Upazila::create(['district_id' => $district->id, 'name' => "Upazila {$i}", 'bn_name' => "উপজেলা {$i}"]);
        }

        $response = $this->getJson('/api/v1/upazilas');

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('meta.total', 17);
        $response->assertJsonPath('data.0.district.id', $district->id);
    }

    public function test_upazila_index_still_filters_by_district_id_while_paginated(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);

        for ($i = 1; $i <= 4; $i++) {
            Upazila::create(['district_id' => $districtA->id, 'name' => "Upazila A{$i}", 'bn_name' => "উপজেলা এ{$i}"]);
        }
        for ($i = 1; $i <= 2; $i++) {
            Upazila::create(['district_id' => $districtB->id, 'name' => "Upazila B{$i}", 'bn_name' => "উপজেলা বি{$i}"]);
        }

        $this->getJson("/api/v1/upazilas?district_id={$districtA->id}")
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.total', 4);
    }

    public function test_upazila_index_filters_by_search(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        Upazila::create(['district_id' => $district->id, 'name' => 'Debidwar', 'bn_name' => 'দেবিদ্বার']);
        Upazila::create(['district_id' => $district->id, 'name' => 'Burichang', 'bn_name' => 'বুড়িচং']);

        $this->getJson('/api/v1/upazilas?search=debi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Debidwar');
    }
}
