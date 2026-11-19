<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Application;
use App\Models\Evaluation;
use App\Models\Rubric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RubricCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_rubric_unlocks_when_evaluation_ends_before_results_begin(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $announcement = Announcement::create(['name' => 'Prueba de calendario', 'status' => 'activa']);
        $calendar = $announcement->calendar()->create([
            'publication_start' => today()->subDays(10),
            'registration_start' => today()->subDays(9),
            'registration_end' => today()->subDays(3),
            'evaluation_start' => today()->subDays(2),
            'evaluation_end' => today()->subDay(),
            'results_start' => today()->addDays(3),
            'results_end' => today()->addDays(4),
        ]);
        $active = Rubric::create(['title' => 'Actual', 'stage' => Rubric::LOCAL, 'is_active' => true]);
        $other = Rubric::create(['title' => 'Alternativa', 'stage' => Rubric::LOCAL, 'is_active' => false]);
        $index = route('catalog.rubrics.index', ['stage' => Rubric::LOCAL]);
        $this->actingAs($operator)->get($index)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('evaluacionEnCurso', false));
        $this->post(route('catalog.rubrics.toggle-active', $active))->assertSessionHasNoErrors();
        $this->post(route('catalog.rubrics.toggle-active', $other))->assertSessionHasNoErrors();
        $this->assertFalse($active->fresh()->is_active);
        $this->assertTrue($other->fresh()->is_active);
        $calendar->update(['evaluation_end' => today()]);
        $this->get($index)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('evaluacionEnCurso', true));
        $this->post(route('catalog.rubrics.toggle-active', $other))->assertSessionHasErrors('error');
        $this->assertTrue($other->fresh()->is_active);
        $announcement->update(['status' => 'cerrada']);
        $this->get($index)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('evaluacionEnCurso', false));
    }

    public function test_local_and_national_rubrics_can_be_saved_and_listed_independently(): void
    {
        $this->withoutVite();

        $operator = User::factory()->create();
        foreach (['rubrics.index', 'rubrics.create', 'rubrics.edit'] as $name) {
            $operator->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $this->actingAs($operator);

        foreach ([Rubric::LOCAL => 'Rúbrica local', Rubric::NATIONAL => 'Rúbrica nacional'] as $stage => $title) {
            $index = route('catalog.rubrics.index', ['stage' => $stage]);

            $this->get(route('catalog.rubrics.create', ['stage' => $stage]))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('SuperAdmin/Catalog/Rubrics/Create')
                    ->where('stage', $stage));

            $this->post(route('catalog.rubrics.store'), [
                'stage' => $stage,
                'title' => $title,
                'is_active' => false,
                'questions' => [[
                    'text' => $title,
                    'options' => [
                        ['text' => 'Cumple', 'score' => 5],
                        ['text' => 'En proceso', 'score' => 1],
                    ],
                ]],
            ])->assertSessionHasNoErrors()->assertRedirect($index);

            $rubric = Rubric::forStage($stage)->sole();
            $question = $rubric->questions()->sole();
            $this->assertSame($title, $question->text);
            $this->assertEqualsCanonicalizing([1, 5], $question->options()->pluck('score')->all());

            $this->from($index)->post(route('catalog.rubrics.toggle-active', $rubric))
                ->assertSessionHasNoErrors()->assertRedirect($index);
        }

        foreach (Rubric::STAGES as $stage) {
            $this->get(route('catalog.rubrics.index', ['stage' => $stage]))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('SuperAdmin/Catalog/Rubrics/Index')
                    ->has('rubrics.data', 1)
                    ->where('rubrics.data.0.stage', $stage)
                    ->where('rubrics.data.0.is_active', true));
        }

        $evaluator = User::factory()->create();
        $evaluator->assignRole(Role::findOrCreate('Evaluador', 'web'));
        $application = Application::create([
            'user_id' => User::factory()->create()->id,
            'announcement_id' => Announcement::create(['name' => 'Convocatoria'])->id,
            'status' => 'pending',
        ]);
        $evaluation = Evaluation::create([
            'application_id' => $application->id,
            'evaluator_id' => $evaluator->id,
            'status' => 'pending',
        ]);

        $this->actingAs($evaluator)->get(route('evaluator.evaluation.show', $evaluation))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Evaluator/Evaluation/Show')
                ->where('rubric.stage', Rubric::LOCAL)
                ->where('rubric.questions.0.text', 'Rúbrica local'));
    }
}

