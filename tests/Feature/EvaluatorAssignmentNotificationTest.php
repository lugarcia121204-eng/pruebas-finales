<?php

namespace Tests\Feature;

use App\Mail\EvaluatorAssigned;
use App\Models\{Announcement, Application, Institution, NationalEvaluation, NationalReview, Notification, User};
use App\Services\AssignmentService;
use Database\Seeders\NationalRubricSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Mail};
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EvaluatorAssignmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Mail::fake();
        foreach (['Admin', 'Admin Nacional', 'Evaluador', 'Docente'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $state = DB::table('states')->insertGetId(['name' => 'Prueba', 'abbreviation' => 'PR']);
        $campus = Institution::create(['name' => 'Sede local', 'state_id' => $state]);
        $otherCampus = Institution::create(['name' => 'Sede externa', 'state_id' => $state]);
        $teacher = User::factory()->create(['institution_id' => $campus->id]);
        $teacher->assignRole('Docente');
        $local = User::factory()->create(['institution_id' => $campus->id]);
        $local->assignRole('Evaluador');
        $external = User::factory()->create(['institution_id' => $otherCampus->id]);
        $external->assignRole('Evaluador');
        $admin = User::factory()->create(['institution_id' => $campus->id]);
        $admin->assignRole('Admin');
        $national = User::factory()->create();
        $national->assignRole('Admin Nacional');
        $announcement = Announcement::create(['name' => 'Convocatoria de prueba', 'status' => 'activa']);
        $announcement->calendar()->create([
            'publication_start' => today()->subDays(10), 'registration_start' => today()->subDays(9),
            'registration_end' => today()->subDays(7), 'evaluation_start' => today()->subDays(6),
            'evaluation_end' => today()->subDays(3), 'results_start' => today()->subDays(2),
            'results_end' => today()->subDay(), 'national_evaluation_start' => today(),
            'national_evaluation_end' => today()->addDays(3),
        ]);
        $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'pending']);

        return [$application, $local, $external, $admin, $national];
    }

    public function test_each_new_local_assignment_notifies_only_its_evaluator_and_retries_do_not_duplicate_it(): void
    {
        [$application, $evaluator, $outsider, $admin] = $this->fixture();
        $application->announcement->calendar->update([
            'evaluation_end' => today()->addDays(3), 'results_start' => today()->addDays(4),
            'results_end' => today()->addDays(5), 'national_evaluation_start' => null, 'national_evaluation_end' => null,
        ]);
        $this->actingAs($admin);
        $url = route('admin.applications.assign');
        $payload = ['application_id' => $application->id, 'evaluator_ids' => [$evaluator->id]];
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $evaluation = $application->evaluations()->sole();
        $notification = Notification::where('type', 'evaluator_assignment')->sole();
        $this->assertSame($evaluator->id, $notification->user_id);
        $this->assertNull($notification->read_at);
        $this->assertSame('local', $notification->data['stage']);
        $this->assertSame($evaluation->id, $notification->data['evaluation_id']);
        $this->assertSame($application->id, $notification->data['application_id']);
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('notifications', 1);
        Mail::assertQueued(EvaluatorAssigned::class, 1);

        $second = Application::create([
            'user_id' => $application->user_id, 'announcement_id' => $application->announcement_id, 'status' => 'pending',
        ]);
        $this->post($url, ['application_id' => $second->id, 'evaluator_ids' => [$evaluator->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('notifications', 2);
        Mail::assertQueued(EvaluatorAssigned::class, 2);
        $this->actingAs($evaluator)->getJson(route('evaluator.notifications.index'))->assertOk()
            ->assertJsonPath('unread_count', 2)->assertJsonCount(2, 'notifications.data');
        $this->actingAs($outsider)->getJson(route('evaluator.notifications.index'))->assertOk()
            ->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'notifications.data');

        $this->actingAs($admin);
        app(AssignmentService::class)->removeEvaluator($application->id, $evaluator->id);
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_national_assignment_notifies_once_with_its_stage_link_and_real_deadline(): void
    {
        [$application, $outsider, $evaluator, , $national] = $this->fixture();
        $application->update(['status' => 'approved']);
        $review = NationalReview::create([
            'application_id' => $application->id, 'administrator_id' => $national->id,
            'assigned_by' => $national->id, 'status' => 'pending',
        ]);
        $this->seed(NationalRubricSeeder::class);
        $url = route('national-review.assign-evaluators', $review->id);
        $this->actingAs($national)->post($url, ['evaluator_ids' => [$evaluator->id]])->assertSessionHasNoErrors();
        $evaluation = NationalEvaluation::sole();
        $notification = Notification::where('type', 'evaluator_assignment')->sole();
        $this->assertSame($evaluator->id, $notification->user_id);
        $this->assertSame('national', $notification->data['stage']);
        $this->assertSame($evaluation->id, $notification->data['evaluation_id']);
        $this->assertSame($application->id, $notification->data['application_id']);
        $this->assertStringContainsString('nacional', $notification->data['message']);
        $this->post($url, ['evaluator_ids' => [$evaluator->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('notifications', 1);
        Mail::assertQueued(EvaluatorAssigned::class, 1);
        Mail::assertQueued(EvaluatorAssigned::class, fn ($mail) => $mail->hasTo($evaluator->email)
            && $mail->stage === 'national' && $mail->daysLimit === null
            && $mail->deadline === today()->addDays(3)->format('d/m/Y')
            && $mail->evaluationUrl === route('national-evaluations.show', $evaluation->id));
        $this->actingAs($evaluator)->getJson(route('evaluator.notifications.index'))->assertOk()
            ->assertJsonPath('notifications.data.0.data.evaluation_id', $evaluation->id);
        $this->actingAs($outsider)->getJson(route('evaluator.notifications.index'))->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_failed_national_batch_does_not_send_notifications_for_rolled_back_assignments(): void
    {
        [$application, $sameCampus, $evaluator, , $national] = $this->fixture();
        $application->update(['status' => 'approved']);
        $review = NationalReview::create([
            'application_id' => $application->id, 'administrator_id' => $national->id,
            'assigned_by' => $national->id, 'status' => 'pending',
        ]);
        $this->seed(NationalRubricSeeder::class);
        $this->actingAs($national)->post(route('national-review.assign-evaluators', $review->id), [
            'evaluator_ids' => [$evaluator->id, $sameCampus->id],
        ])->assertSessionHasErrors('evaluator_ids');
        $this->assertDatabaseCount('national_evaluations', 0);
        $this->assertDatabaseCount('notifications', 0);
        Mail::assertNothingQueued();
    }
}
