<?php

namespace Tests\Feature;

use App\Models\{Announcement, Application, Evaluation, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LocalEvaluationRecencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_local_assignment_is_highlighted_in_the_dashboard_and_history_without_filter_false_positives(): void
    {
        Role::findOrCreate('Evaluador', 'web');
        $evaluator = User::factory()->create();
        $evaluator->assignRole('Evaluador');
        $other = User::factory()->create();
        $announcement = Announcement::create(['name' => 'Prueba local']);
        $create = function ($name, $recipient, $status = 'approved') use ($announcement) {
            $teacher = User::factory()->create(['name' => $name]);
            $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'pending']);
            $evaluation = Evaluation::create(['application_id' => $application->id, 'evaluator_id' => $recipient->id, 'status' => $status]);
            $evaluation->forceFill(['created_at' => now()->startOfDay()])->save();
            return $evaluation;
        };
        $old = $create('Anterior', $evaluator);
        foreach (range(1, 4) as $number) $create('Docente '.$number, $evaluator);
        $recent = $create('Reciente', $evaluator);
        $create('Ajena', $other);
        $deleted = $create('Eliminada', $evaluator);
        $deleted->application->delete();
        $url = route('evaluator.evaluations.index');
        $this->actingAs($evaluator)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('applications.total', 6)->where('applications.data.0.evaluation_id', $recent->id)
            ->where('applications.data.0.is_latest', true)->where('applications.data.1.is_latest', false));
        $this->get($url.'?search=Anterior')->assertInertia(fn (Assert $page) => $page
            ->where('applications.total', 1)->where('applications.data.0.is_latest', false));
        $this->get($url.'?rows=5&page=2')->assertInertia(fn (Assert $page) => $page
            ->where('applications.data.0.evaluation_id', $old->id)->where('applications.data.0.is_latest', false));
        $old->update(['comment' => 'Actualizacion anterior']);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('applications.data.0.evaluation_id', $recent->id));

        $new = $create('Nueva asignacion', $evaluator, 'pending');
        $this->get(route('evaluator.dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('applications.data.0.evaluation_id', $new->id)->where('applications.data.0.is_latest', true));
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('applications.data.0.evaluation_id', $recent->id)->where('applications.data.0.is_latest', true));
        $new->update(['status' => 'rejected']);
        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('applications.data.0.evaluation_id', $new->id)->where('applications.data.0.is_latest', true));
        $this->get($url.'?status=approved')->assertInertia(fn (Assert $page) => $page
            ->where('applications.data', fn ($rows) => collect($rows)->where('is_latest', true)->isEmpty()));
    }
}
