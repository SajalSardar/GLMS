<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class SchoolLogoUploadTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private function basePayload(Division $division, District $district, Upazila $upazila): array
    {
        return [
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'Logo Test School',
            'type' => 'government',
            'level' => 'secondary',
        ];
    }

    public function test_store_with_logo_uploads_and_destroy_removes_the_file(): void
    {
        Storage::fake('public');

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $allowed = $this->actorWithPermissions(['schools.create', 'schools.delete']);
        $logo = UploadedFile::fake()->image('logo.png');

        $response = $this->actingAs($allowed, 'sanctum')->post('/api/v1/schools', array_merge(
            $this->basePayload($division, $district, $upazila),
            ['logo' => $logo],
        ))->assertCreated();

        $url = $response->json('data.logo_url');
        $this->assertNotNull($url);

        $school = School::findOrFail($response->json('data.id'));
        Storage::disk('public')->assertExists($school->logo_path);

        $this->actingAs($allowed, 'sanctum')->deleteJson("/api/v1/schools/{$school->id}")->assertNoContent();
        Storage::disk('public')->assertMissing($school->logo_path);
    }

    public function test_update_with_a_new_logo_replaces_the_old_file(): void
    {
        Storage::fake('public');

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $allowed = $this->actorWithPermissions(['schools.create', 'schools.update']);

        $response = $this->actingAs($allowed, 'sanctum')->post('/api/v1/schools', array_merge(
            $this->basePayload($division, $district, $upazila),
            ['logo' => UploadedFile::fake()->image('first.png')],
        ))->assertCreated();

        $school = School::findOrFail($response->json('data.id'));
        $firstPath = $school->logo_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($allowed, 'sanctum')
            ->put("/api/v1/schools/{$school->id}", ['logo' => UploadedFile::fake()->image('second.jpg')])
            ->assertOk();

        $school->refresh();
        $this->assertNotSame($firstPath, $school->logo_path);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($school->logo_path);
    }

    public function test_stored_logo_extension_is_derived_from_file_content_not_the_client_supplied_filename(): void
    {
        Storage::fake('public');

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $allowed = $this->actorWithPermissions(['schools.create']);
        $maliciousLogo = UploadedFile::fake()->image('shell.pht')->mimeType('image/jpeg');

        $response = $this->actingAs($allowed, 'sanctum')->post('/api/v1/schools', array_merge(
            $this->basePayload($division, $district, $upazila),
            ['logo' => $maliciousLogo],
        ))->assertCreated();

        $school = School::findOrFail($response->json('data.id'));
        $this->assertStringEndsWith('.jpg', $school->logo_path);
        Storage::disk('public')->assertExists($school->logo_path);
    }
}
