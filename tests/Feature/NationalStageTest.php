<?php

namespace Tests\Feature;

use App\Models\{Application, Announcement, Institution, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NationalStageTest extends TestCase
{
    use RefreshDatabase;

    private function openNationalEvaluation(Announcement $announcement): void
    {
        $localDate = today()->subDay();
        $announcement->calendar()->create(array_fill_keys([
            'publication_start', 'registration_start', 'registration_end', 'evaluation_start',
            'evaluation_end', 'results_start', 'results_end',
        ], $localDate) + [
            'national_evaluation_start' => today(),
            'national_evaluation_end' => today(),
        ]);
    }

    public function test_national_assignments_exclude_and_reject_evaluators_from_the_teacher_campus(): void
    {
        foreach (['Admin Nacional', 'Evaluador'] as $role) Role::findOrCreate($role, 'web');
        $state = DB::table('states')->insertGetId(['name' => 'Estado', 'abbreviation' => 'ED']);
        $campus = Institution::create(['name' => 'Sede docente', 'state_id' => $state]);
        $otherCampus = Institution::create(['name' => 'Otra sede', 'state_id' => $state]);
        $national = User::factory()->create();
        $national->assignRole('Admin Nacional');
        $teacher = User::factory()->create(['institution_id' => $campus->id]);
        $sameCampus = User::factory()->create(['institution_id' => $campus->id]);
        $other = User::factory()->create(['institution_id' => $otherCampus->id]);
        foreach ([$sameCampus, $other] as $evaluator) $evaluator->assignRole('Evaluador');
        $announcement = Announcement::create(['name' => 'Test']);
        $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'approved']);
        $review = \App\Models\NationalReview::create(['application_id' => $application->id, 'administrator_id' => $national->id, 'assigned_by' => $national->id, 'status' => 'pending']);
        $rubric = \App\Models\Rubric::create(['title' => 'Nacional', 'is_active' => true]);
        $rubric->questions()->create(['text' => 'Criterio'])->options()->create(['text' => 'Cumple', 'score' => 10]);

        $this->actingAs($national)->get(route('national-review.show', $review->id))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('evaluators', 1)->where('evaluators.0.id', $other->id));
        $url = route('national-review.assign-evaluators', $review->id);
        $this->post($url, ['evaluator_ids' => [$other->id]])->assertSessionHasErrors('evaluator_ids');
        $this->openNationalEvaluation($announcement);
        $this->post($url, ['evaluator_ids' => [$other->id, $sameCampus->id]])->assertSessionHasErrors('evaluator_ids');
        $this->assertDatabaseCount('national_evaluations', 0);
        $this->post($url, ['evaluator_ids' => [$other->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('national_evaluations', ['national_review_id' => $review->id, 'evaluator_id' => $other->id]);
    }

    public function test_national_dashboards_show_only_own_assignments_statistics_and_pending_work(): void
    {
        foreach (['Admin Nacional', 'Evaluador'] as $role) Role::findOrCreate($role, 'web');
        $national = User::factory()->create(); $national->assignRole('Admin Nacional');
        $otherNational = User::factory()->create(); $otherNational->assignRole('Admin Nacional');
        $evaluator = User::factory()->create(); $evaluator->assignRole('Evaluador');
        $outsider = User::factory()->create(); $outsider->assignRole('Evaluador');
        $state = DB::table('states')->insertGetId(['name' => 'Estado prueba', 'abbreviation' => 'TS']);
        $institution = Institution::create(['name' => 'Campus nacional', 'state_id' => $state]);
        $teacher = User::factory()->create(['name' => 'Docente nacional', 'institution_id' => $institution->id]);
        $announcement = Announcement::create(['name' => 'Convocatoria nacional']);
        foreach (['pending', 'approved'] as $status) {
            $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'approved']);
            $review = \App\Models\NationalReview::create(['application_id' => $application->id, 'administrator_id' => $national->id, 'assigned_by' => $national->id, 'status' => $status]);
            \App\Models\NationalEvaluation::create(['national_review_id' => $review->id, 'evaluator_id' => $evaluator->id, 'status' => $status === 'pending' ? 'pending' : 'completed']);
        }
        $this->actingAs($national)->get(route('national-review.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('NationalDashboard')->where('stats.total', 2)->where('stats.pending', 1)->where('stats.approved', 1)->where('stats.rejected', 0)
            ->has('institutions.data', 1)->where('institutions.data.0.approved_count', 1)->where('topInstitutions.data.0.applications_count', 2));
        $this->actingAs($otherNational)->get(route('national-review.dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('stats.total', 0)->has('institutions.data', 0));
        $this->actingAs($national)->get(route('national-review.dashboard', ['search' => 'Sin coincidencias', 'rows' => 5]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.total', 2)->has('institutions.data', 0)->where('institutions.meta.per_page', 5));
        \Maatwebsite\Excel\Facades\Excel::fake();
        $this->get(route('national-review.dashboard.export'))->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('resumen_etapa_nacional.xlsx', fn ($export) => $export->collection()->count() === 1 && $export->collection()->first()[2] === 1);
        $this->actingAs($evaluator);
        $this->get(route('national-review.dashboard'))->assertForbidden();
        $this->get(route('national-review.dashboard.export'))->assertForbidden();
    }

    public function test_second_filter_has_separate_evaluations_and_a_protected_national_verdict(): void
    {
        foreach (['Admin Nacional', 'Evaluador'] as $role) Role::findOrCreate($role, 'web');
        $national = User::factory()->create(); $national->assignRole('Admin Nacional');
        $evaluator = User::factory()->create(); $evaluator->assignRole('Evaluador');
        $outsider = User::factory()->create(); $outsider->assignRole('Evaluador');
        $teacher = User::factory()->create();
        $announcement = Announcement::create(['name' => 'Test']);
        $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'approved']);
        $review = \App\Models\NationalReview::create(['application_id' => $application->id, 'administrator_id' => $national->id, 'assigned_by' => $national->id, 'status' => 'pending']);
        $rubric = \App\Models\Rubric::create(['title' => 'Rúbrica nacional', 'is_active' => true]);
        $question = $rubric->questions()->create(['text' => 'Criterio']);
        $option = $question->options()->create(['text' => 'Cumple', 'score' => 10]);
        $this->openNationalEvaluation($announcement);
        $this->actingAs($national)->post(route('national-review.verdict', $review->id), ['status' => 'approved'])->assertSessionHasErrors('status');
        $this->post(route('national-review.assign-evaluators', $review->id), ['evaluator_ids' => [$teacher->id]])->assertSessionHasErrors('evaluator_ids');
        $this->post(route('national-review.assign-evaluators', $review->id), ['evaluator_ids' => [$evaluator->id]])->assertSessionHasNoErrors();
        $evaluation = \App\Models\NationalEvaluation::firstOrFail();
        $this->assertSame('pending', $evaluation->status);
        $this->assertDatabaseCount('evaluations', 0);
        $this->post(route('national-review.verdict', $review->id), ['status' => 'approved'])->assertSessionHasErrors('status');
        $submit = route('national-evaluations.submit', $evaluation->id);
        $this->actingAs($outsider)->get(route('national-evaluations.show', $evaluation->id))->assertNotFound();
        $this->post($submit, ['answers' => [$question->id => $option->id]])->assertNotFound();
        $this->actingAs($evaluator)->get(route('national-evaluations.show', $evaluation->id))->assertOk();
        $this->travel(1)->days();
        $this->post($submit, ['answers' => [$question->id => $option->id]])->assertSessionHasErrors('answers');
        $this->assertSame('pending', $evaluation->fresh()->status);
        $this->travelBack();
        $this->post($submit, ['answers' => [$question->id => 999999]])->assertSessionHasErrors('answers');
        $this->post($submit, ['answers' => [$question->id => $option->id], 'score' => 99999])->assertSessionHasNoErrors();
        $this->assertEquals(10, $evaluation->fresh()->score);
        $this->get(route('national-evaluations.index', ['status' => 'completed', 'rows' => 5]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('NationalEvaluation/Index')
                ->where('stats.completed', 1)->where('stats.pending', 0)->where('stats.total', 1)
                ->where('applications.total', 1)->where('applications.per_page', 5)
                ->where('applications.data.0.documents_count', 0));
        $this->get(route('national-evaluations.index', ['search' => 'no-such-teacher']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('applications.total', 0)->where('stats.total', 1));
        $this->get(route('national-evaluations.index', ['status' => 'pending']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('applications.total', 0));
        $this->post($submit, ['answers' => [$question->id => $option->id]])->assertStatus(409);
        $this->actingAs($national)->post(route('national-review.verdict', $review->id), ['status' => 'rejected'])->assertSessionHasErrors('comment');
        $this->post(route('national-review.verdict', $review->id), ['status' => 'rejected', 'comment' => 'No cumple el segundo filtro.'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $review->fresh()->status);
        $this->assertSame('approved', $application->fresh()->status);
        $this->assertNotNull($review->fresh()->decided_at);
        $this->post(route('national-review.verdict', $review->id), ['status' => 'approved'])->assertStatus(409);
    }

    public function test_assignments_accept_only_national_admins_and_protect_assigned_expedients(): void
    {
        foreach (['Super Admin', 'Admin Nacional', 'Admin'] as $role) Role::findOrCreate($role, 'web');
        $operator = User::factory()->create(); $operator->assignRole('Super Admin');
        $national = User::factory()->create(); $national->assignRole('Admin Nacional');
        $other = User::factory()->create(); $other->assignRole('Admin Nacional');
        $local = User::factory()->create(); $local->assignRole('Admin');
        $teacher = User::factory()->create();
        $announcement = Announcement::create(['name' => 'Test']);
        $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'approved']);
        $url = route('superadmin.national-stage.assign', $application->id);
        $this->actingAs($operator)->post($url, ['administrator_id' => $local->id])->assertSessionHasErrors('administrator_id');
        $this->post($url, ['administrator_id' => $national->id])->assertSessionHasNoErrors();
        $review = \App\Models\NationalReview::firstOrFail();
        $this->assertEquals('pending', $review->status);
        $this->assertEquals('approved', $application->fresh()->status);
        $this->post($url, ['administrator_id' => $national->id])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('national_reviews', 1);
        $this->actingAs($national)->get(route('national-review.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('reviews.data', 1)->where('reviews.data.0.status', 'pending'));
        $this->get(route('national-review.index', ['search' => 'Sin coincidencias', 'rows' => 5, 'sort_field' => 'status', 'sort_direction' => 'asc']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('reviews.total', 0)->where('reviews.per_page', 5));
        $this->get(route('national-review.index', ['status' => 'rejected']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('reviews.total', 0));
        $this->get(route('national-review.show', $review->id))->assertOk();
        $this->actingAs($other)->get(route('national-review.show', $review->id))->assertNotFound();
        $this->get(route('national-review.index', ['search' => $teacher->name]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('reviews.total', 0));
        $this->actingAs($operator)->post($url, ['administrator_id' => $other->id])->assertSessionHasErrors('administrator_id');
        $this->assertEquals($national->id, $review->fresh()->administrator_id);
        $this->assertEquals($operator->id, $review->fresh()->assigned_by);
        $this->actingAs($national)->get(route('national-review.show', $review->id))->assertOk();
        $application->update(['status' => 'rejected']);
        $this->actingAs($other)->get(route('national-review.show', $review->id))->assertNotFound();
        $this->actingAs($operator)->post($url, ['administrator_id' => $national->id])->assertNotFound();
    }

    public function test_approved_applications_arrive_from_all_institutions_and_can_be_filtered(): void
    {
        Role::findOrCreate('Super Admin', 'web');
        $operator = User::factory()->create();
        $operator->assignRole('Super Admin');
        $this->actingAs($operator);
        $state = DB::table('states')->insertGetId(['name' => 'Test', 'abbreviation' => 'TS']);
        $announcement = Announcement::create(['name' => 'Convocatoria prueba']);
        $applications = [];
        foreach (['Campus A', 'Campus B'] as $name) {
            $institution = Institution::create(['name' => $name, 'state_id' => $state]);
            $teacher = User::factory()->create(['institution_id' => $institution->id]);
            $applications[] = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'approved']);
        }
        $pending = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'pending']);
        Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id, 'status' => 'rejected']);
        $url = route('superadmin.national-stage.index');
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SuperAdmin/NationalStage/Index')->has('applications.data', 2)
            ->where('applications.total', 2)->has('institutions', 2));
        $this->get($url . '?institution_id=' . $institution->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('applications.data', 1)->where('applications.data.0.institution', 'Campus B'));
        $this->get($url . '?search=Campus%20A')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('applications.data', 1)->where('applications.data.0.institution', 'Campus A'));
        $pending->update(['status' => 'approved']);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('applications.total', 3));
        $pending->update(['status' => 'rejected']);
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('applications.total', 2));
    }

    public function test_other_roles_cannot_access_national_stage(): void
    {
        $this->get(route('superadmin.national-stage.index'))->assertRedirect();
        foreach (['Admin', 'Admin Nacional', 'Docente', 'Evaluador'] as $role) {
            Role::findOrCreate($role, 'web');
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user)->get(route('superadmin.national-stage.index'))->assertForbidden();
        }
    }
}
