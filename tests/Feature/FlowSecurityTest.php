<?php

namespace Tests\Feature;

use App\Models\{Announcement, Application, Calendar, Evaluation, Institution, PositionType, PriorityArea, Rubric, SubArea, User};
use Database\Seeders\NationalRubricSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Mail, Storage};
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FlowSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function registrationData(): array
    {
        Mail::fake();
        Storage::fake('public');
        foreach (['Docente', 'Evaluador', 'Super Admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
        $state = DB::table('states')->insertGetId(['name' => 'Prueba', 'abbreviation' => 'PR']);
        $institution = Institution::create(['name' => 'Campus de prueba', 'state_id' => $state]);
        $area = PriorityArea::create(['name' => 'Área de prueba']);
        $sub = SubArea::create(['name' => 'Subárea de prueba', 'priority_area_id' => $area->id]);

        return [
            'curp' => 'TEST900101HDFABC01', 'name' => 'Persona de prueba',
            'email' => 'flow-security@example.test', 'password' => 'Password123!',
            'password_confirmation' => 'Password123!', 'institution_id' => $institution->id,
            'priority_area_id' => $area->id, 'sub_area_id' => $sub->id, 'role_type' => 'docente',
        ];
    }

    public function test_registration_cannot_access_privileged_or_mismatched_accounts(): void
    {
        $data = $this->registrationData();
        $existing = User::factory()->create([
            'email' => $data['email'], 'curp' => $data['curp'],
            'password' => Hash::make($data['password']),
        ]);
        $existing->assignRole('Super Admin');
        $this->post(route('register.store'), $data)->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertFalse($existing->fresh()->hasRole('Docente'));
        $existing->syncRoles(['Evaluador']);
        $data['password'] = $data['password_confirmation'] = 'WrongPassword123!';
        $this->post(route('register.store'), $data)->assertSessionHasErrors('email');
        $this->assertGuest();
        $data['password'] = $data['password_confirmation'] = 'Password123!';
        $data['curp'] = 'OTRO900101HDFABC01';
        $this->post(route('register.store'), $data)->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNotNull($existing->fresh()->email_verified_at);
        Mail::assertNothingQueued();
    }

    public function test_owner_can_add_public_role_without_overwriting_profile_or_creation_date(): void
    {
        $data = $this->registrationData();
        $existing = User::factory()->create([
            'email' => $data['email'], 'curp' => $data['curp'],
            'password' => Hash::make($data['password']), 'name' => 'Nombre original',
            'institution_id' => $data['institution_id'], 'created_at' => '2020-01-01 12:00:00',
        ]);
        $existing->assignRole('Evaluador');
        $this->post(route('register.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('verification.notice'));
        $this->assertAuthenticatedAs($existing);
        $this->assertTrue($existing->fresh()->hasAllRoles(['Docente', 'Evaluador']));
        $this->assertSame('Nombre original', $existing->fresh()->name);
        $this->assertSame('2020-01-01 12:00:00', $existing->fresh()->created_at->format('Y-m-d H:i:s'));
        $this->get(route('verification.notice'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('auth.user.email_verification_code')->missing('auth.user.email_verification_code_expires_at'));
        Mail::assertQueued(\App\Mail\VerificationCode::class, 1);
    }

    public function test_manual_registration_validates_curp_and_area_relationship(): void
    {
        $data = $this->registrationData();
        $data['curp'] = 'XXXXXXXXXXXXXXXXXX';
        $this->post(route('register.store'), $data)->assertSessionHasErrors('curp');
        $data['curp'] = 'TEST900101HDFABC01';
        $otherArea = PriorityArea::create(['name' => 'Otra área']);
        $otherSubarea = SubArea::create(['name' => 'Otra subárea', 'priority_area_id' => $otherArea->id]);
        $validSubarea = $data['sub_area_id'];
        $data['sub_area_id'] = $otherSubarea->id;
        $this->post(route('register.store'), $data)->assertSessionHasErrors('sub_area_id');
        $data['sub_area_id'] = $validSubarea;
        $data['curp'] = strtolower($data['curp']);
        $this->post(route('register.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('verification.notice'));
        $this->assertDatabaseHas('users', ['email' => $data['email'], 'curp' => strtoupper($data['curp'])]);
    }

    public function test_adding_a_role_respects_existing_two_factor_authentication(): void
    {
        $data = $this->registrationData();
        $user = User::factory()->create([
            'email' => $data['email'], 'curp' => $data['curp'],
            'password' => Hash::make($data['password']),
        ]);
        $user->assignRole('Evaluador');
        $user->forceFill(['two_factor_secret' => encrypt('test-secret'), 'two_factor_confirmed_at' => now()])->save();
        $this->post(route('register.store'), $data)->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertFalse($user->fresh()->hasRole('Docente'));
        $this->actingAs($user)->post(route('register.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('verification.notice'));
        $this->assertTrue($user->fresh()->hasRole('Docente'));
    }

    public function test_public_curp_lookup_does_not_expose_existing_account_details(): void
    {
        $data = $this->registrationData();
        $user = User::factory()->create(['curp' => $data['curp'], 'email' => $data['email']]);
        $user->assignRole('Evaluador');
        $this->mock(\App\Services\CurpService::class, function ($mock) {
            $mock->shouldReceive('buscarPorCurp')->once()->andReturn([
                'nombres' => 'Persona', 'apellidoPaterno' => 'De', 'apellidoMaterno' => 'Prueba',
            ]);
        });
        $response = $this->postJson('/api/buscar-curp', ['curp' => $data['curp'], 'role_type' => 'docente'])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('existing_data', null);
        $this->assertStringNotContainsString($data['email'], $response->getContent());
    }

    public function test_applications_reject_closed_periods_duplicates_and_other_pending_work(): void
    {
        $data = $this->registrationData();
        $teacher = User::factory()->create(['institution_id' => $data['institution_id'], 'curp' => $data['curp']]);
        $teacher->assignRole('Docente');
        $announcement = Announcement::create(['name' => 'Prueba', 'status' => 'cerrada']);
        $position = PositionType::create(['code' => 'E3505', 'name' => 'Docente']);
        $payload = ['announcement_id' => $announcement->id, 'position_type_id' => $position->id, 'via' => 'larga'];
        $url = route('teacher.applications.store');
        $this->actingAs($teacher)->post($url, $payload)->assertSessionHasErrors('announcement_id');
        $announcement->update(['status' => 'activa']);
        $calendar = Calendar::create([
            'announcement_id' => $announcement->id, 'publication_start' => today()->subDays(10),
            'registration_start' => today()->subDays(8), 'registration_end' => today()->subDay(),
            'evaluation_start' => today()->addDay(), 'evaluation_end' => today()->addDays(4),
            'results_start' => today()->addDays(5), 'results_end' => today()->addDays(8),
        ]);
        $this->post($url, $payload)->assertSessionHasErrors('announcement_id');
        $calendar->update(['registration_end' => today()]);
        $this->post($url, $payload)->assertSessionHasNoErrors()->assertRedirect(route('teacher.dashboard'));
        $this->post($url, $payload)->assertSessionHasErrors('announcement_id');
        $this->assertDatabaseCount('applications', 1);
        $other = Announcement::create(['name' => 'Otra', 'status' => 'activa']);
        $secondCalendar = $calendar->replicate();
        $secondCalendar->announcement_id = $other->id;
        $secondCalendar->save();
        $payload['announcement_id'] = $other->id;
        $this->post($url, $payload)->assertSessionHasErrors('announcement_id');
        $this->assertDatabaseCount('applications', 1);
    }

    public function test_evaluation_rejects_foreign_answers_and_recomputes_score(): void
    {
        $data = $this->registrationData();
        $evaluator = User::factory()->create(['institution_id' => $data['institution_id']]);
        $evaluator->assignRole('Evaluador');
        $teacher = User::factory()->create(['institution_id' => $data['institution_id']]);
        $announcement = Announcement::create(['name' => 'Evaluación', 'status' => 'activa']);
        Calendar::create([
            'announcement_id' => $announcement->id, 'publication_start' => today()->subDays(10),
            'registration_start' => today()->subDays(9), 'registration_end' => today()->subDays(3),
            'evaluation_start' => today()->subDays(2), 'evaluation_end' => today()->addDays(2),
            'results_start' => today()->addDays(3), 'results_end' => today()->addDays(5),
        ]);
        $application = Application::create(['user_id' => $teacher->id, 'announcement_id' => $announcement->id]);
        $evaluation = Evaluation::create(['application_id' => $application->id, 'evaluator_id' => $evaluator->id, 'status' => 'pending']);
        $rubric = Rubric::create(['title' => 'Local', 'stage' => 'local', 'is_active' => true]);
        $question = $rubric->questions()->create(['text' => 'Criterio local']);
        $option = $question->options()->create(['text' => 'Cumple', 'score' => 2]);
        $url = route('evaluator.evaluation.update', $evaluation->id);
        $this->actingAs($evaluator)->put($url, [
            'status' => 'approved', 'score' => 999, 'answers' => [999999 => ['option_id' => $option->id]],
        ])->assertSessionHasErrors('answers');
        $this->assertSame('pending', $evaluation->fresh()->status);
        $calendar = $announcement->calendar;
        $calendar->update(['evaluation_end' => today()->subDay()]);
        $this->get(route('evaluator.evaluation.show', $evaluation))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('evaluationPeriodOpen', false));
        $this->put($url, [
            'status' => 'approved', 'answers' => [$question->id => ['option_id' => $option->id]],
        ])->assertSessionHasErrors('status');
        $calendar->update(['evaluation_end' => today()->addDays(2)]);
        $this->put($url, [
            'status' => 'approved', 'score' => 999, 'answers' => [$question->id => ['option_id' => $option->id, 'score' => 999]],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('2.00', $evaluation->fresh()->score);
        $this->assertEquals(2, $evaluation->fresh()->answers[$question->id]['score']);
    }

    public function test_superadmin_dashboard_works_with_sqlite_and_national_rubric_is_idempotent(): void
    {
        $this->registrationData();
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        $this->actingAs($admin)->get(route('superadmin.dashboard'))->assertOk();
        $this->seed(NationalRubricSeeder::class);
        $this->seed(NationalRubricSeeder::class);
        $rubric = Rubric::forStage('national')->active()->with('questions.options')->sole();
        $this->assertCount(10, $rubric->questions);
        foreach ($rubric->questions as $question) {
            $this->assertEquals([2, 1, 0], $question->options->pluck('score')->all());
        }
        $this->put(route('catalog.rubrics.update', $rubric), [
            'title' => $rubric->title,
            'stage' => 'national',
            'is_active' => true,
            'questions' => $rubric->questions->map(fn ($question) => [
                'id' => $question->id,
                'text' => $question->text,
                'options' => $question->options->map(fn ($option) => $option->only(['id', 'text', 'score']))->all(),
            ])->all(),
        ])->assertSessionHasNoErrors()->assertRedirect();
    }
}
