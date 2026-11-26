<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\KioskDevice;
use App\Models\School;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class KioskDeviceControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);
        $this->schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);
    }

    public function test_school_admin_creates_a_device_scoped_to_their_own_school_and_receives_the_token_once(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['kiosk_devices.view', 'kiosk_devices.create'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->postJson('/api/v1/kiosk-devices', ['name' => 'Front Gate'])
            ->assertCreated();

        $response->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.name', 'Front Gate')
            ->assertJsonPath('data.is_active', true);

        $plainToken = $response->json('data.token');
        $this->assertNotEmpty($plainToken);

        $this->assertDatabaseHas('kiosk_devices', [
            'school_id' => $this->schoolA->id,
            'name' => 'Front Gate',
            'token' => hash('sha256', $plainToken),
        ]);
    }

    public function test_national_admin_must_specify_a_school_when_creating_a_device(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['kiosk_devices.view', 'kiosk_devices.create']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/kiosk-devices', ['name' => 'Front Gate'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('school_id');

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/kiosk-devices', ['name' => 'Front Gate', 'school_id' => $this->schoolA->id])
            ->assertCreated()
            ->assertJsonPath('data.school_id', $this->schoolA->id);
    }

    public function test_store_requires_a_name(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['kiosk_devices.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->postJson('/api/v1/kiosk-devices', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_store_is_forbidden_without_the_create_permission(): void
    {
        $schoolAdmin = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->postJson('/api/v1/kiosk-devices', ['name' => 'Front Gate'])
            ->assertForbidden();
    }

    public function test_school_admin_sees_only_their_own_schools_devices(): void
    {
        KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'A Gate', 'token' => hash('sha256', 'a'), 'is_active' => true]);
        KioskDevice::create(['school_id' => $this->schoolB->id, 'name' => 'B Gate', 'token' => hash('sha256', 'b'), 'is_active' => true]);

        $schoolAdminA = $this->actorWithPermissions(['kiosk_devices.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdminA, 'sanctum')
            ->getJson('/api/v1/kiosk-devices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'A Gate');
    }

    public function test_national_admin_sees_every_schools_devices(): void
    {
        KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'A Gate', 'token' => hash('sha256', 'a'), 'is_active' => true]);
        KioskDevice::create(['school_id' => $this->schoolB->id, 'name' => 'B Gate', 'token' => hash('sha256', 'b'), 'is_active' => true]);

        $nationalAdmin = $this->actorWithPermissions(['kiosk_devices.view']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->getJson('/api/v1/kiosk-devices')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_is_forbidden_without_the_view_permission(): void
    {
        $schoolAdmin = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson('/api/v1/kiosk-devices')
            ->assertForbidden();
    }

    public function test_school_admin_can_rename_and_deactivate_their_own_device(): void
    {
        $device = KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'Old Name', 'token' => hash('sha256', 'a'), 'is_active' => true]);

        $schoolAdmin = $this->actorWithPermissions(['kiosk_devices.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson("/api/v1/kiosk-devices/{$device->id}", ['name' => 'New Name', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_school_admin_cannot_update_another_schools_device(): void
    {
        $device = KioskDevice::create(['school_id' => $this->schoolB->id, 'name' => 'B Gate', 'token' => hash('sha256', 'b'), 'is_active' => true]);

        $schoolAdminA = $this->actorWithPermissions(['kiosk_devices.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdminA, 'sanctum')
            ->patchJson("/api/v1/kiosk-devices/{$device->id}", ['name' => 'Hijacked'])
            ->assertNotFound();
    }

    public function test_update_is_forbidden_without_the_update_permission(): void
    {
        $device = KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'Old Name', 'token' => hash('sha256', 'a'), 'is_active' => true]);

        $schoolAdmin = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson("/api/v1/kiosk-devices/{$device->id}", ['name' => 'New Name'])
            ->assertForbidden();
    }

    public function test_school_admin_can_delete_their_own_device(): void
    {
        $device = KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'Old Name', 'token' => hash('sha256', 'a'), 'is_active' => true]);

        $schoolAdmin = $this->actorWithPermissions(['kiosk_devices.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->deleteJson("/api/v1/kiosk-devices/{$device->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('kiosk_devices', ['id' => $device->id]);
    }

    public function test_destroy_is_forbidden_without_the_delete_permission(): void
    {
        $device = KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'Old Name', 'token' => hash('sha256', 'a'), 'is_active' => true]);

        $schoolAdmin = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->deleteJson("/api/v1/kiosk-devices/{$device->id}")
            ->assertForbidden();
    }
}
