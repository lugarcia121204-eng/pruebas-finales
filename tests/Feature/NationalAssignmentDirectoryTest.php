<?php

namespace Tests\Feature;

use App\Models\{Announcement, Application, Institution, NationalReview, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NationalAssignmentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_filters_assigned_requests_and_preserves_alphabetical_pagination(): void
    {
        foreach (['Super Admin', 'Admin Nacional'] as $role) Role::findOrCreate($role, 'web');
        $operator = User::factory()->create();
        $operator->assignRole('Super Admin');
        $admins = User::factory()->count(2)->create();
        foreach ($admins as $admin) $admin->assignRole('Admin Nacional');
        $state = DB::table('states')->insertGetId(['name' => 'Prueba', 'abbreviation' => 'PR']);
        $campusA = Institution::create(['name' => 'Campus A', 'state_id' => $state]);
        $campusB = Institution::create(['name' => 'Campus B', 'state_id' => $state]);
        $first = Announcement::create(['name' => 'Convocatoria A']);
        $second = Announcement::create(['name' => 'Convocatoria B']);
        $create = function ($name, $campus, $announcement, $admin, $status = 'pending', $localStatus = 'approved') use ($operator) {
            $teacher = User::factory()->create(['name' => $name, 'institution_id' => $campus->id]);
            $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => $localStatus]);
            if (!$admin) return $application;
            return NationalReview::create(['application_id' => $application->id, 'administrator_id' => $admin->id, 'assigned_by' => $operator->id, 'status' => $status]);
        };
        $carlos = $create('Carlos', $campusB, $first, $admins[0], 'rejected');
        $beto = $create('Beto', $campusB, $second, $admins[1], 'approved');
        $ana = $create('Ana', $campusA, $first, $admins[0]);
        $create('Sin asignar', $campusA, $first, null);
        $create('Local rechazada', $campusA, $first, $admins[0], 'pending', 'rejected');
        $deleted = $create('Eliminada', $campusA, $first, $admins[0]);
        Application::findOrFail($deleted->application_id)->delete();
        $url = route('superadmin.national-stage.assignments');
        $this->actingAs($operator)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/NationalStage/Assignments')->where('reviews.total', 3)
            ->where('reviews.data.0.teacher', 'Ana')->where('reviews.data.0.administrator', $admins[0]->name)
            ->where('reviews.data.1.status', 'approved')->where('reviews.data.2.status', 'rejected')
            ->has('announcements', 2)->has('institutions', 2)->has('nationalAdministrators', 2));
        foreach ([
            ['search' => 'Ana'],
            ['institution_id' => $campusA->id],
            ['search' => 'Ana', 'announcement_id' => $first->id, 'institution_id' => $campusA->id, 'administrator_id' => $admins[0]->id],
        ] as $filters) {
            $this->get($url.'?'.http_build_query($filters))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('reviews.total', 1)->where('reviews.data.0.id', $ana->id));
        }
        $this->get($url.'?announcement_id='.$second->id)->assertInertia(fn (Assert $page) => $page
            ->where('reviews.total', 1)->where('reviews.data.0.id', $beto->id));
        $this->get($url.'?administrator_id='.$admins[0]->id)->assertInertia(fn (Assert $page) => $page
            ->where('reviews.total', 2)->where('reviews.data.1.id', $carlos->id));
        $this->get($url.'?announcement_id='.$first->id.'&administrator_id='.$admins[1]->id)->assertInertia(fn (Assert $page) => $page->where('reviews.total', 0));
        foreach (range(4, 1) as $number) $create('Zeta '.$number, $campusA, $first, $admins[0]);
        $this->get($url.'?rows=5&page=2&announcement_id='.$first->id)->assertInertia(fn (Assert $page) => $page
            ->where('reviews.total', 6)->where('reviews.from', 6)->where('reviews.data.0.teacher', 'Zeta 4')
            ->where('filters.announcement_id', (string) $first->id)
            ->where('reviews.first_page_url', fn ($value) => str_contains($value, 'announcement_id='.$first->id)));
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('reviews.total', 7));
        $this->getJson($url.'?rows=0')->assertUnprocessable();
        $this->getJson($url.'?announcement_id=999999')->assertUnprocessable();
        $this->assertDatabaseCount('national_reviews', 9);
    }

    public function test_directory_requires_a_super_administrator(): void
    {
        $url = route('superadmin.national-stage.assignments');
        $this->get($url)->assertRedirect(route('login'));
        foreach (['Docente', 'Evaluador', 'Admin', 'Admin Nacional'] as $name) {
            Role::findOrCreate($name, 'web');
            $user = User::factory()->create();
            $user->assignRole($name);
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }
}
