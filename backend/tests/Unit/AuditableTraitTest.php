<?php

namespace Tests\Unit;

use App\Models\Division;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditableTraitTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_sets_created_by_to_the_authenticated_actor(): void
    {
        $role = Role::create(['name' => 'actor_role', 'label' => 'Actor']);
        $actor = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($actor);

        $division = Division::create(['name' => 'Dhaka', 'bn_name' => 'ঢাকা']);

        $this->assertSame($actor->id, $division->created_by);
        $this->assertNull($division->updated_by);
    }

    public function test_updating_sets_updated_by_to_the_authenticated_actor(): void
    {
        $division = Division::create(['name' => 'Dhaka', 'bn_name' => 'ঢাকা']);

        $role = Role::create(['name' => 'actor_role_2', 'label' => 'Actor 2']);
        $actor = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($actor);

        $division->update(['name' => 'Dhaka Division']);

        $this->assertSame($actor->id, $division->updated_by);
    }

    public function test_creating_and_updating_with_no_authenticated_actor_leaves_columns_null(): void
    {
        $division = Division::create(['name' => 'Dhaka', 'bn_name' => 'ঢাকা']);

        $this->assertNull($division->created_by);

        $division->update(['name' => 'Dhaka Division']);

        $this->assertNull($division->updated_by);
    }

    public function test_a_no_op_update_still_sets_updated_by(): void
    {
        $division = Division::create(['name' => 'Dhaka', 'bn_name' => 'ঢাকা']);

        $role = Role::create(['name' => 'actor_role_3', 'label' => 'Actor 3']);
        $actor = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($actor);

        $division->update([]);

        $this->assertSame($actor->id, $division->fresh()->updated_by);
    }
}
