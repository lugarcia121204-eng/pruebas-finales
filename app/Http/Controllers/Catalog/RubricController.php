<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalogos\StoreRubricRequest;
use App\Http\Requests\Catalogos\UpdateRubricRequest;
use App\Http\Resources\Catalog\RubricResource;
use App\Models\Announcement;
use App\Models\Rubric;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class RubricController extends Controller
{
    private string $permissionPrefix;
    private string $routeName;

    public function __construct()
    {
        $this->permissionPrefix = 'rubrics.';
        $this->routeName = 'catalog.rubrics.';

        $this->middleware("permission:{$this->permissionPrefix}index")->only(['index', 'show']);
        $this->middleware("permission:{$this->permissionPrefix}create")->only(['store', 'create']);
        $this->middleware("permission:{$this->permissionPrefix}edit")->only(['update', 'edit', 'toggleActive']);
        $this->middleware("permission:{$this->permissionPrefix}delete")->only(['destroy']);
    }

    public function index(Request $request)
    {
        $stage = $this->catalogStage($request);
        $search = $request->input('search');
        $rows = $request->input('rows', 10);
        $order = $request->input('order', 'id');
        $direction = $request->input('direction', 'desc');

        $rubrics = Rubric::forStage($stage)->withCount('questions')
            ->when($search, function ($query, $search) {
                $query->where('title', 'like', "%{$search}%");
            })
            ->orderBy($order, $direction)
            ->paginate($rows)
            ->withQueryString();

        return Inertia::render('SuperAdmin/Catalog/Rubrics/Index', [
            'rubrics' => $rubrics,
            'filters' => $request->all(['search', 'rows', 'order', 'direction']),
            'stage' => $stage,
            'hasActiveRubric' => Rubric::forStage($stage)->active()->exists(),
            'evaluacionEnCurso' => $stage === Rubric::LOCAL && $this->localEvaluationInProgress(),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('SuperAdmin/Catalog/Rubrics/Create', [
            'title' => 'Agregar Rúbrica',
            'stage' => $this->catalogStage($request),
            'routeName' => 'catalog.rubrics.',
        ]);
    }

    public function store(StoreRubricRequest $request)
    {
        $stage = $request->validated('stage', Rubric::LOCAL);

        if ($request->boolean('is_active') && Rubric::forStage($stage)->active()->exists()) {
            return back()->withErrors(['is_active' => 'Ya existe una rúbrica activa en esta etapa. Desactívala primero.']);
        }

        $this->saveRubric(function () use ($request, $stage) {
            $rubric = Rubric::create([
                'title' => $request->title,
                'stage' => $stage,
                'is_active' => $request->is_active ?? false,
            ]);

            if ($request->questions) {
                foreach ($request->questions as $qData) {
                    $question = $rubric->questions()->create(['text' => $qData['text']]);
                    if (isset($qData['options'])) {
                        foreach ($qData['options'] as $oData) {
                            $question->options()->create([
                                'text' => $oData['text'],
                                'score' => $oData['score'],
                            ]);
                        }
                    }
                }
            }
        });

        return redirect()->route('catalog.rubrics.index', ['stage' => $stage])->with('success', 'Rúbrica creada correctamente.');
    }

    public function edit(Rubric $rubric)
    {
        $rubric->load('questions.options');

        return Inertia::render('SuperAdmin/Catalog/Rubrics/Edit', [
            'title' => 'Editar Rúbrica',
            'routeName' => 'catalog.rubrics.',
            'rubric' => new RubricResource($rubric),
        ]);
    }

    public function update(UpdateRubricRequest $request, Rubric $rubric)
    {
        $stage = $request->validated('stage', $rubric->stage);
        $isActive = $request->boolean('is_active', $rubric->is_active);

        if ($rubric->stage === Rubric::LOCAL && $rubric->is_active
            && ($stage !== $rubric->stage || !$isActive) && $this->localEvaluationInProgress()) {
            return back()->withErrors(['is_active' => 'No se puede cambiar la rúbrica activa durante la evaluación local.']);
        }

        if ($isActive && Rubric::forStage($stage)->active()->whereKeyNot($rubric->id)->exists()) {
            return back()->withErrors(['is_active' => 'Ya existe una rúbrica activa en esta etapa. Desactívala primero.']);
        }

        $this->saveRubric(function () use ($request, $rubric, $stage, $isActive) {
            $rubric->update([
                'title' => $request->title,
                'stage' => $stage,
                'is_active' => $isActive,
            ]);

            // Sync Questions (Simple approach: Delete all and recreate. 
            // Better approach: Update existing, Create new, Delete missing.
            // Let's go with the better approach for ID stability if needed, 
            // but for simplicity and robustness given nested structure, delete/recreate 
            // is safer for ensuring consistency unless ID stability is critical.)
            // User requested "actions... edit... delete... add question... add answer".
            // Since we receive the full structure, sync is best.

            // Get IDs of current questions to track deletions
            $currentQuestionIds = $rubric->questions()->pluck('id')->toArray();
            $incomingQuestionIds = [];

            if ($request->questions) {
                foreach ($request->questions as $qData) {
                    if (isset($qData['id'])) {
                        $incomingQuestionIds[] = $qData['id'];
                        $question = $rubric->questions()->find($qData['id']);
                        if ($question) {
                            $question->update(['text' => $qData['text']]);
                            
                            // Sync Options
                            $currentOptionIds = $question->options()->pluck('id')->toArray();
                            $incomingOptionIds = [];

                            if (isset($qData['options'])) {
                                foreach ($qData['options'] as $oData) {
                                    if (isset($oData['id'])) {
                                        $incomingOptionIds[] = $oData['id'];
                                        $question->options()->where('id', $oData['id'])->update([
                                            'text' => $oData['text'],
                                            'score' => $oData['score'],
                                        ]);
                                    } else {
                                        $newOption = $question->options()->create([
                                            'text' => $oData['text'],
                                            'score' => $oData['score'],
                                        ]);
                                        $incomingOptionIds[] = $newOption->id;
                                    }
                                }
                            }
                            // Delete removed options
                            $question->options()->whereNotIn('id', $incomingOptionIds)->delete();
                        }
                    } else {
                        // New Question
                        $question = $rubric->questions()->create(['text' => $qData['text']]);
                        $incomingQuestionIds[] = $question->id;

                        if (isset($qData['options'])) {
                            foreach ($qData['options'] as $oData) {
                                $question->options()->create([
                                    'text' => $oData['text'],
                                    'score' => $oData['score'],
                                ]);
                            }
                        }
                    }
                }
            }
            // Delete removed questions
            $rubric->questions()->whereNotIn('id', $incomingQuestionIds)->delete();
        });

        return redirect()->route('catalog.rubrics.index', ['stage' => $stage])->with('success', 'Rúbrica actualizada correctamente.');
    }

    public function destroy(Rubric $rubric)
    {
        if ($rubric->stage === Rubric::LOCAL && $rubric->is_active && $this->localEvaluationInProgress()) {
            return back()->withErrors(['error' => 'No se puede eliminar la rúbrica activa durante la evaluación local.']);
        }

        $rubric->delete();
        return redirect()->route('catalog.rubrics.index', ['stage' => $rubric->stage])->with('success', 'Rúbrica eliminada correctamente.');
    }

    public function toggleActive(Rubric $rubric)
    {
        if ($rubric->is_active && $rubric->stage === Rubric::LOCAL && $this->localEvaluationInProgress()) {
            return back()->withErrors(['error' => 'No se puede cambiar la rúbrica activa durante la evaluación local.']);
        }

        if (!$rubric->is_active && Rubric::forStage($rubric->stage)->active()->exists()) {
            return back()->withErrors(['error' => 'Ya existe una rúbrica activa en esta etapa. Desactívala primero.']);
        }

        $this->saveRubric(fn () => $rubric->update(['is_active' => !$rubric->is_active]), 'error');

        return back();
    }

    private function saveRubric(callable $save, string $errorField = 'is_active'): void
    {
        try {
            DB::transaction($save);
        } catch (UniqueConstraintViolationException $exception) {
            if (!str_contains($exception->getMessage(), 'active_stage')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                $errorField => 'Ya existe una rúbrica activa en esta etapa. Desactívala primero.',
            ]);
        }
    }

    private function catalogStage(Request $request): string
    {
        $stage = $request->query('stage');

        return in_array($stage, Rubric::STAGES, true) ? $stage : Rubric::LOCAL;
    }

    private function localEvaluationInProgress(): bool
    {
        return Announcement::activa()
            ->whereHas('calendar', fn ($calendar) => $calendar->withOpenLocalEvaluation())
            ->exists();
    }
}
