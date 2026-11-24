<?php

namespace Tests\Feature;

use App\Models\{Announcement, Application, NationalEvaluation, NationalReview, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NationalEvaluatorInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_and_registration_link_are_available_without_exposing_other_administrators_evaluators(): void
    {
        foreach (['Admin Nacional', 'Evaluador'] as $role) Role::findOrCreate($role, 'web');
        $admin = User::factory()->create()->assignRole('Admin Nacional');
        $other = User::factory()->create()->assignRole('Admin Nacional');
        $announcement = Announcement::create(['name' => 'Invitaciones']);
        $assign = function ($owner, $name) use ($announcement) {
            $evaluator = User::factory()->create(['name' => $name])->assignRole('Evaluador');
            $application = Application::create(['user_id' => User::factory()->create()->id, 'announcement_id' => $announcement->id, 'status' => 'approved']);
            $review = NationalReview::create(['application_id' => $application->id, 'administrator_id' => $owner->id, 'assigned_by' => $owner->id, 'status' => 'pending']);
            NationalEvaluation::create(['national_review_id' => $review->id, 'evaluator_id' => $evaluator->id, 'status' => 'pending']);
            return $evaluator;
        };
        $ana = $assign($admin, 'Ana');
        $assign($admin, 'Zeta');
        $assign($other, 'Ajeno');
        User::factory()->create(['name' => 'Sin asignar'])->assignRole('Evaluador');
        $url = route('national-review.evaluators.index');
        $this->actingAs($admin)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Security/Evaluators/Index')->where('users.meta.total', 2)
            ->where('users.data.0.id', $ana->id)->where('registrationLink', route('register.evaluator'))
            ->where('indexRoute', 'national-review.evaluators.index')->where('canDelete', false)
            ->missing('users.data.0.password'));
        $this->get($url.'?search=Ana&rows=5')->assertInertia(fn (Assert $page) => $page
            ->where('users.meta.total', 1)->where('users.meta.per_page', 5));
        $this->get($url.'?sort_field=name&sort_direction=desc')->assertInertia(fn (Assert $page) => $page->where('users.data.0.name', 'Zeta'));
        $this->getJson($url.'?sort_field=password')->assertUnprocessable();
        $this->getJson($url.'?rows=0')->assertUnprocessable();
        $this->actingAs($other)->get($url)->assertInertia(fn (Assert $page) => $page->where('users.meta.total', 1)->where('users.data.0.name', 'Ajeno'));
        auth()->logout();
        $this->get(route('register.evaluator'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/RegisterEvaluator'));
    }

    public function test_directory_requires_national_administrator_role(): void
    {
        $url = route('national-review.evaluators.index');
        $this->get($url)->assertRedirect(route('login'));
        foreach (['Admin', 'Docente', 'Evaluador'] as $role) {
            Role::findOrCreate($role, 'web');
            $user = User::factory()->create()->assignRole($role);
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }
}
