<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Spatie\Permission\Models\Role;

class AdminInstitutionFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_national_admin_does_not_require_an_institution_and_preserves_access_controls(): void
    {
        Role::findOrCreate('Super Admin', 'web');
        Role::findOrCreate('Admin', 'web');
        $this->seed(\Database\Seeders\AdminNacionalRoleSeeder::class);
        $role = Role::findByName('Admin Nacional', 'web');
        $operator = User::factory()->create();
        $operator->assignRole('Super Admin');
        $this->actingAs($operator);
        $state = DB::table('states')->insertGetId(['name' => 'Test', 'abbreviation' => 'TS']);
        $institution = DB::table('institutions')->insertGetId(['name' => 'Test campus', 'state_id' => $state]);
        $data = ['name' => 'National admin', 'email' => 'national@example.test', 'password' => 'TestPassword123', 'roles' => [$role->id]];
        $this->post(route('security.users.store'), $data)->assertSessionHasNoErrors();
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertTrue($user->hasRole('Admin Nacional'));
        $this->assertNull($user->institution_id);
        $this->put(route('security.users.update', $user), $data + ['id' => $user->id, 'institution_id' => $institution])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->institution_id);
        $this->assertSame('AdminLayout', $user->getLayoutName());
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user);
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('security.users.index'))->assertForbidden();
        $this->get(route('superadmin.dashboard'))->assertForbidden();
    }

    public function test_admin_institution_is_required_saved_and_other_roles_preserve_existing_affiliation(): void
    {
        Role::findOrCreate('Super Admin', 'web');
        $admin = Role::findOrCreate('Admin', 'web');
        $teacher = Role::findOrCreate('Docente', 'web');
        $operator = User::factory()->create();
        $operator->assignRole('Super Admin');
        $this->actingAs($operator);
        $state = DB::table('states')->insertGetId(['name' => 'Estado prueba', 'abbreviation' => 'PR']);
        $institution = DB::table('institutions')->insertGetId(['name' => 'Institución prueba', 'state_id' => $state]);
        $data = ['name' => 'Admin prueba', 'email' => 'admin-field@example.test', 'password' => 'TestPassword123', 'roles' => [$admin->id]];
        $this->post(route('security.users.store'), $data)->assertSessionHasErrors('institution_id');
        $this->post(route('security.users.store'), $data + ['institution_id' => 999999])->assertSessionHasErrors('institution_id');
        $this->post(route('security.users.store'), $data + ['institution_id' => $institution])->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertEquals($institution, $user->institution_id);
        $this->assertTrue($user->hasRole('Admin'));
        $this->get(route('security.users.edit', $user))->assertOk();
        $data['roles'] = [$teacher->id];
        $data['id'] = $user->id;
        $this->put(route('security.users.update', $user), $data + ['institution_id' => 999999])->assertSessionHasNoErrors();
        $this->assertEquals($institution, $user->fresh()->institution_id);
        $data['email'] = 'teacher-field@example.test';
        unset($data['id']);
        $this->post(route('security.users.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => $data['email'], 'institution_id' => null]);
    }
}
