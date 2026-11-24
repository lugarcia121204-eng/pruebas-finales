<?php

namespace Tests\Feature;

use App\Models\{Announcement, Application, NationalEvaluation, NationalReview, Notification, Recognition, User};
use App\Services\PdfGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NationalEvaluatorRecognitionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Mail::fake();
        foreach (['Admin Nacional', 'Evaluador'] as $role) Role::findOrCreate($role, 'web');
        $admin = User::factory()->create();
        $admin->assignRole('Admin Nacional');
        $other = User::factory()->create();
        $other->assignRole('Admin Nacional');
        $evaluator = User::factory()->create();
        $evaluator->assignRole('Evaluador');
        $announcement = Announcement::create(['name' => 'Prueba nacional']);
        $evaluation = $this->assignment($admin, $evaluator, $announcement, 'pending');
        return [$admin, $other, $evaluator, $announcement, $evaluation];
    }

    private function assignment(User $admin, User $evaluator, Announcement $announcement, string $status): NationalEvaluation
    {
        $application = Application::create(['user_id' => User::factory()->create()->id, 'announcement_id' => $announcement->id, 'status' => 'approved']);
        $review = NationalReview::create(['application_id' => $application->id, 'administrator_id' => $admin->id, 'assigned_by' => $admin->id, 'status' => 'pending']);
        return NationalEvaluation::create(['national_review_id' => $review->id, 'evaluator_id' => $evaluator->id, 'status' => $status]);
    }

    public function test_national_admin_sees_only_own_evaluators_with_search_and_pagination(): void
    {
        [$admin, $other, $evaluator, $announcement] = $this->fixture();
        $this->assignment($admin, $evaluator, $announcement, 'completed');
        $foreign = User::factory()->create();
        $this->assignment($other, $foreign, $announcement, 'completed');
        $url = route('national-review.evaluator-recognitions.index');
        $this->actingAs($admin)->get($url.'?rows=5')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('NationalReview/EvaluatorRecognitions')->where('recognitions.total', 1)
            ->where('recognitions.per_page', 5)->where('recognitions.data.0.evaluator_id', $evaluator->id)
            ->where('recognitions.data.0.applications_reviewed', 1)->where('recognitions.data.0.assigned_count', 2)
            ->where('recognitions.data.0.active', false));
        $this->get($url.'?search=Prueba')->assertInertia(fn (Assert $page) => $page->where('recognitions.total', 1));
        $this->get($url.'?search=NoCoincide')->assertInertia(fn (Assert $page) => $page->where('recognitions.total', 0));
        $this->getJson($url.'?rows=-1')->assertUnprocessable();
    }

    public function test_directory_can_sort_by_evaluator_and_announcement_with_validated_columns(): void
    {
        [$admin, , $evaluator, $announcement] = $this->fixture();
        $evaluator->update(['name' => 'Zeta']);
        $ana = User::factory()->create(['name' => 'Ana']);
        $this->assignment($admin, $ana, $announcement, 'completed');
        $firstAnnouncement = Announcement::create(['name' => 'Alfa']);
        $this->assignment($admin, $evaluator, $firstAnnouncement, 'completed');
        $url = route('national-review.evaluator-recognitions.index');
        $this->actingAs($admin)->get($url.'?sort_field=evaluator_name&sort_direction=asc')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('recognitions.total', 3)->where('recognitions.data.0.evaluator_name', 'Ana'));
        $this->get($url.'?sort_field=evaluator_name&sort_direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('recognitions.data.0.evaluator_name', 'Zeta')->where('filters.sort_direction', 'desc'));
        $this->get($url.'?sort_field=announcement_name&sort_direction=asc')->assertInertia(fn (Assert $page) => $page
            ->where('recognitions.data.0.announcement_name', 'Alfa'));
        $this->get($url.'?sort_field=announcement_name&sort_direction=desc')->assertInertia(fn (Assert $page) => $page
            ->where('recognitions.data.0.announcement_name', $announcement->name));
        $this->getJson($url.'?sort_field=users.password')->assertUnprocessable();
        $this->getJson($url.'?sort_direction=invalid')->assertUnprocessable();
    }

    public function test_activation_requires_own_completed_work_and_keeps_local_recognition_unchanged(): void
    {
        [$admin, $other, $evaluator, $announcement, $evaluation] = $this->fixture();
        $local = Recognition::create(['user_id' => $evaluator->id, 'announcement_id' => $announcement->id, 'type' => 'evaluator', 'stage' => 'local', 'active' => true]);
        $url = route('national-review.evaluator-recognitions.toggle');
        $payload = ['user_id' => $evaluator->id, 'announcement_id' => $announcement->id, 'active' => true];
        $this->actingAs($other)->post($url, $payload)->assertNotFound();
        $this->actingAs($admin)->post($url, $payload)->assertSessionHasErrors('active');
        $this->assertDatabaseCount('recognitions', 1);
        $evaluation->update(['status' => 'completed']);
        $this->mock(PdfGenerationService::class, function ($mock) {
            $mock->shouldReceive('freezeEvaluatorRecognition')->twice()->andReturnUsing(function ($recognition) {
                $recognition->update(['identifier' => 'EVAL-TEST-'.$recognition->id]);
            });
        });
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $recognition = Recognition::where('stage', 'national')->sole();
        $this->assertTrue($recognition->active);
        $this->assertNotNull($recognition->sent_at);
        $this->assertNotNull($recognition->identifier);
        $this->assertTrue($local->fresh()->active);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($evaluator->id, Notification::sole()->user_id);
        $this->assertSame($recognition->id, Notification::sole()->data['recognition_id']);
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('recognitions', 2);
        $this->assertDatabaseCount('notifications', 1);
        $payload['active'] = false;
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertFalse($recognition->fresh()->active);
        $this->assertTrue($local->fresh()->active);
        $payload['active'] = true;
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertTrue($recognition->fresh()->active);
        $this->assertDatabaseCount('notifications', 1);
        $response = $this->actingAs($evaluator)->get(route('evaluator.recognitions.index'))->assertOk();
        $this->assertContains($recognition->id, collect($response->inertiaProps('recognitions.data'))->pluck('id')->all());
    }

    public function test_unrelated_completed_work_and_other_roles_cannot_activate_recognitions(): void
    {
        [$admin, $other, $evaluator, $announcement] = $this->fixture();
        $this->assignment($other, $evaluator, $announcement, 'completed');
        $url = route('national-review.evaluator-recognitions.toggle');
        $payload = ['user_id' => $evaluator->id, 'announcement_id' => $announcement->id, 'active' => true];
        $this->actingAs($admin)->post($url, $payload)->assertSessionHasErrors('active');
        $this->assertDatabaseCount('recognitions', 0);
        foreach (['Evaluador', 'Docente', 'Admin', 'Super Admin'] as $role) {
            Role::findOrCreate($role, 'web');
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user)->get(route('national-review.evaluator-recognitions.index'))->assertForbidden();
            $this->post($url, $payload)->assertForbidden();
        }
    }
}
