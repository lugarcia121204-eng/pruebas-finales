<?php

namespace Tests\Feature;

use App\Models\{Announcement, Application, Evaluation, NationalEvaluation, NationalReview, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NationalEvaluationRecencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_national_assignment_is_personal_stable_across_filters_and_moves_to_new_arrivals(): void
    {
        Role::findOrCreate('Evaluador', 'web');
        $evaluator = User::factory()->create();
        $evaluator->assignRole('Evaluador');
        $other = User::factory()->create();
        $admin = User::factory()->create();
        $announcement = Announcement::create(['name' => 'Asignaciones recientes']);
        $create = function ($name, $recipient, $localStatus = 'approved') use ($admin, $announcement) {
            $teacher = User::factory()->create(['name' => $name]);
            $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => $localStatus]);
            $review = NationalReview::create(['application_id' => $application->id, 'administrator_id' => $admin->id, 'assigned_by' => $admin->id, 'status' => 'pending']);
            $evaluation = NationalEvaluation::create(['national_review_id' => $review->id, 'evaluator_id' => $recipient->id, 'status' => 'pending']);
            $evaluation->forceFill(['created_at' => now()->startOfDay()])->save();
            return $evaluation;
        };
        $old = $create('Docente anterior', $evaluator);
        $recent = $create('Docente reciente', $evaluator);
        $create('Asignacion ajena', $other);
        $create('Solicitud no disponible', $evaluator, 'rejected');
        $this->actingAs($evaluator);
        $url = route('national-evaluations.index');
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('applications.total', 2)->where('applications.data.0.evaluation_id', $recent->id)
            ->where('applications.data.0.is_latest', true)->where('applications.data.1.is_latest', false));
        $this->get($url.'?search=anterior')->assertInertia(fn (Assert $page) => $page
            ->where('applications.total', 1)->where('applications.data.0.is_latest', false));
        $old->update(['status' => 'completed']);
        $this->get($url.'?status=completed')->assertInertia(fn (Assert $page) => $page
            ->where('applications.total', 1)->where('applications.data.0.is_latest', false));
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('applications.data.0.evaluation_id', $recent->id));

        $localApplication = Application::create(['user_id' => User::factory()->create()->id, 'announcement_id' => $announcement->id, 'status' => 'pending']);
        Evaluation::create(['application_id' => $localApplication->id, 'evaluator_id' => $evaluator->id, 'status' => 'pending']);
        $dashboard = $this->get(route('evaluator.dashboard'))->assertOk();
        $cards = collect($dashboard->inertiaProps('applications.data'));
        $this->assertTrue($cards->firstWhere('stage', 'national')['is_latest']);
        $this->assertTrue($cards->firstWhere('stage', 'local')['is_latest']);

        $new = $create('Nueva asignacion', $evaluator);
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('applications.data.0.evaluation_id', $new->id)->where('applications.data.0.is_latest', true)
            ->where('applications.data.1.evaluation_id', $recent->id)->where('applications.data.1.is_latest', false));
        $this->get(route('evaluator.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('applications.data', fn ($rows) => collect($rows)->where('stage', 'national')->where('is_latest', true)->pluck('evaluation_id')->all() === [$new->id]));
        $new->update(['status' => 'completed']);
        $this->get($url.'?status=pending')->assertInertia(fn (Assert $page) => $page
            ->where('applications.data.0.evaluation_id', $recent->id)->where('applications.data.0.is_latest', false));
    }
}
