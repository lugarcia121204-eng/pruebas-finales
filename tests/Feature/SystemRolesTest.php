<?php
namespace Tests\Feature;

use App\Models\{Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_roles_cannot_be_renamed_or_deleted_but_permissions_and_description_are_editable(): void
    {
        $operatorRole = Role::create(['name' => 'Super Admin', 'guard_name' => 'web', 'description' => 'Operador']);
        $operator = User::factory()->create();
        $operator->assignRole($operatorRole);
        $this->actingAs($operator);
        foreach (['Admin', 'Admin Nacional', 'Docente', 'Evaluador'] as $name) {
            $role = Role::create(['name' => $name, 'guard_name' => 'web', 'description' => 'Sistema']);
            $data = ['id' => $role->id, 'name' => 'Nombre cambiado', 'guard_name' => 'web', 'description' => 'Descripcion', 'permissions' => []];
            $this->put(route('security.roles.update', $role->id), $data)->assertSessionHasErrors('name');
            $this->assertSame($name, $role->fresh()->name);
            $this->delete(route('security.roles.destroy', $role->id))->assertSessionHasErrors('role');
            $this->assertDatabaseHas('roles', ['id' => $role->id]);
            $data['name'] = $name;
            $this->put(route('security.roles.update', $role->id), $data)->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame('Descripcion', $role->fresh()->description);
        }
    }

    public function test_custom_role_can_still_be_renamed_and_deleted(): void
    {
        $operatorRole = Role::create(['name' => 'Super Admin', 'guard_name' => 'web']);
        $operator = User::factory()->create(); $operator->assignRole($operatorRole);
        $role = Role::create(['name' => 'Personalizado', 'guard_name' => 'web']);
        $this->actingAs($operator)->put(route('security.roles.update', $role->id), ['id' => $role->id, 'name' => 'Nuevo nombre', 'guard_name' => 'web', 'description' => 'Descripcion', 'permissions' => []])->assertSessionHasNoErrors();
        $this->assertSame('Nuevo nombre', $role->fresh()->name);
        $this->delete(route('security.roles.destroy', $role->id))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }
}
