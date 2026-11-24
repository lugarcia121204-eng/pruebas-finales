<?php

namespace App\Http\Controllers\Evaluator;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use App\Models\Evaluacion;
use App\Models\Application;

class EvaluatorController extends Controller
{
    public function inicio(Request $request)
    {
        $user = $request->user();
        $search = $request->input('search');

        $rows = $request->validate(['rows' => ['nullable', 'integer', 'in:5,10,25,50']])['rows'] ?? 10;
        $local = \App\Models\Evaluation::withAvailableApplication()->where('evaluator_id', $user->id);
        $latestLocalId = (clone $local)->latest()->orderByDesc('id')->value('id');
        $national = \App\Models\NationalEvaluation::where('evaluator_id', $user->id)
            ->whereHas('review.application', fn ($query) => $query->approved());
        $latestNationalId = (clone $national)->latest()->orderByDesc('id')->value('id');
        $localPending = (clone $local)->pending()->whereHas('application', fn ($query) => $query->where('status', 'pending'));
        $nationalPending = (clone $national)->where('status', 'pending')
            ->whereHas('review', fn ($query) => $query->where('status', 'pending'));
        $totalAsignadas = (clone $local)->count() + (clone $national)->count();
        $pendientes = (clone $localPending)->count() + (clone $nationalPending)->count();
        $evaluadas = $totalAsignadas - $pendientes;

        $localPending->when($search, fn ($query) => $query->searchByTeacherOrAnnouncement($search));
        $nationalPending->when($search, fn ($query) => $query->whereHas('review.application', fn ($app) => $app
            ->where(fn ($match) => $match->whereHas('user', fn ($teacher) => $teacher->where('name', 'like', "%{$search}%"))
                ->orWhereHas('announcement', fn ($announcement) => $announcement->where('name', 'like', "%{$search}%")))));

        $pending = $localPending->select('id', 'created_at')->selectRaw("'local' as stage")
            ->unionAll($nationalPending->select('id', 'created_at')->selectRaw("'national' as stage"));
        $applications = \Illuminate\Support\Facades\DB::query()->fromSub($pending, 'pending_evaluations')
            ->orderByDesc('created_at')->orderBy('stage')->orderByDesc('id')->paginate($rows)->withQueryString();
        $items = $applications->getCollection();
        $localEvaluations = \App\Models\Evaluation::with(['application.announcement', 'application.user.subArea',
            'application' => fn ($query) => $query->withCount('documents')])
            ->whereIn('id', $items->where('stage', 'local')->pluck('id'))->get()->keyBy('id');
        $nationalEvaluations = \App\Models\NationalEvaluation::with(['review.application.announcement', 'review.application.user.subArea',
            'review.application' => fn ($query) => $query->withCount('documents')])
            ->whereIn('id', $items->where('stage', 'national')->pluck('id'))->get()->keyBy('id');
        $applications->setCollection($items->map(function ($item) use ($localEvaluations, $nationalEvaluations, $latestNationalId, $latestLocalId) {
            $evaluation = $item->stage === 'national' ? $nationalEvaluations[$item->id] : $localEvaluations[$item->id];
            $application = $item->stage === 'national' ? $evaluation->review->application : $evaluation->application;
            return [
                'id' => $application->id,
                'evaluation_id' => $evaluation->id,
                'stage' => $item->stage,
                'is_latest' => $evaluation->id === ($item->stage === 'national' ? $latestNationalId : $latestLocalId),
                'announcement_name' => $application->announcement->name,
                'teacher_name' => $application->user->name,
                'teacher_degree' => $application->user->subArea?->name ?? 'N/A',
                'application_date' => $application->created_at->isoFormat('D [de] MMMM [de] YYYY'),
                'documents_count' => $application->documents_count ?? 0,
                'status' => 'Pendiente',
            ];
        }));

        return Inertia::render('Evaluator/Dashboard', [
            'stats' => [
                'total' => $totalAsignadas,
                'pendientes' => $pendientes,
                'evaluadas' => $evaluadas,
            ],
            'filters' => $request->all(['search', 'rows']),
            'applications' => $applications,
        ]);
    }

    public function show($id)
    {
        $evaluation = \App\Models\Evaluation::withAvailableApplication()->where('id', $id)
            ->where('evaluator_id', Auth::id())
            ->where('status', 'pending')
            ->whereHas('application', function ($q) {
                $q->where('status', 'pending');
            })
            ->with([
                'application.announcement',
                'application.user.subArea',
                'application.user.priorityArea',
                'application.user.institution',
                'application.documents',
                'application.positionType'
            ])
            ->firstOrFail();

        // Get Active Rubric — fallback to most recent if none is marked active
        $rubric = \App\Models\Rubric::forStage(\App\Models\Rubric::LOCAL)->where('is_active', true)
            ->with(['questions.options'])
            ->first()
            ?? \App\Models\Rubric::forStage(\App\Models\Rubric::LOCAL)->with(['questions.options'])
                ->latest()
                ->first();

        // Ensure all relationships are loaded
        $application = $evaluation->application;
        $application->load(['announcement', 'documents', 'user.institution', 'user.priorityArea', 'user.subArea', 'positionType']);

        return Inertia::render('Evaluator/Evaluation/Show', [
            'evaluation' => $evaluation,
            'application' => $application,
            'rubric' => $rubric,
            'teacher' => $application->user,
            'evaluationPeriodOpen' => $application->announcement?->calendar?->localEvaluationIsOpen() ?? false,
        ]);
    }

    public function evaluar(\App\Http\Requests\StoreEvaluationVerdictRequest $request, $id)
    {

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $id) {
            $evaluation = \App\Models\Evaluation::withAvailableApplication()->where('id', $id)
                ->where('evaluator_id', Auth::id())
                ->where('status', 'pending')
                ->whereHas('application', function ($q) {
                    $q->where('status', 'pending');
                })
                ->lockForUpdate()->firstOrFail();

            $rubric = \App\Models\Rubric::forStage(\App\Models\Rubric::LOCAL)->active()->with('questions.options')->first();
            $submittedAnswers = $request->validated('answers');
            if (!$rubric || $rubric->questions->isEmpty() || count($submittedAnswers) !== $rubric->questions->count()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['answers' => 'Responde todas las preguntas de la rúbrica local activa.']);
            }
            $score = 0;
            $answers = [];
            foreach ($rubric->questions as $question) {
                $optionId = $submittedAnswers[$question->id]['option_id'] ?? null;
                $option = $question->options->firstWhere('id', $optionId);
                if (!$option) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['answers' => 'Selecciona una opción válida para cada pregunta de la rúbrica.']);
                }
                $score += (float) $option->score;
                $answers[$question->id] = ['option_id' => $option->id, 'score' => (float) $option->score];
            }

            $evaluation->update([
                'status' => $request->status,
                'score' => $score,
                'answers' => $answers,
                'comment' => $request->comment,
            ]);
        });

        return redirect()->route('evaluator.dashboard')->with('success', 'Solicitud evaluada exitosamente.');
    }

    public function streamDocument($id)
    {
        $document = \App\Models\Document::findOrFail($id);

        // Authorization: Check if the evaluator is assigned to the application this document belongs to
        $hasAccess = \App\Models\Evaluation::withAvailableApplication()->where('evaluator_id', Auth::id())
            ->where('application_id', $document->application_id)
            ->exists();

        if (!$hasAccess) {
            abort(403, 'No tienes permiso para ver este documento.');
        }

        $path = $document->file_path;
        return app(\App\Services\FileService::class)->responseFile($path);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $search = $request->input('search');
        $statusFilter = $request->input('status');

        // Statistics using loadCount (1 Query instead of 4)
        $user->loadCount([
            'evaluations as total' => fn ($query) => $query->withAvailableApplication(),
            'evaluations as pendientes' => fn($q) => $q->withAvailableApplication()->pending()->whereHas('application', fn($q2) => $q2->where('status', 'pending')),
            'evaluations as aprobadas' => fn($q) => $q->withAvailableApplication()->approved(),
            'evaluations as rechazadas' => fn($q) => $q->withAvailableApplication()->rejected(),
        ]);

        $totalAsignadas = $user->total;
        $pendientes = $user->pendientes;
        $evaluadas = $totalAsignadas - $pendientes;
        $aprobadas = $user->aprobadas;
        $rechazadas = $user->rechazadas;

        // Completed Applications (History)
        $query = \App\Models\Evaluation::withAvailableApplication()->where('evaluator_id', $user->id)
            ->where(function ($q) {
                $q->where('status', '!=', 'pending')
                  ->orWhereHas('application', function ($q2) {
                      $q2->where('status', '!=', 'pending');
                  });
            });
        $latestLocalId = (clone $query)->latest()->orderByDesc('id')->value('id');
        $query->with([
                'application.announcement',
                'application.user.subArea',
                'application' => function ($query) {
                    $query->withCount('documents');
                }
            ])
            ->when($search, function ($query, $search) {
                $query->searchByTeacherOrAnnouncement($search);
            });

        if ($statusFilter && in_array($statusFilter, ['approved', 'rejected'])) {
            if ($statusFilter === 'approved') {
                $query->approved();
            } else {
                $query->rejected();
            }
        } elseif ($statusFilter === 'expired') {
            $query->where('status', 'expired');
        }

        $applications = $query
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->input('rows', 10))
            ->withQueryString();

        // Transform data
        $applications->getCollection()->transform(function ($evaluation) use ($latestLocalId) {
            $finalStatus = $evaluation->status;
            if ($finalStatus === 'pending' && $evaluation->application->status !== 'pending') {
                $finalStatus = 'evaluated_by_admin';
            }

            return [
                'id' => $evaluation->application->id,
                'evaluation_id' => $evaluation->id,
                'is_latest' => $evaluation->id === $latestLocalId,
                'announcement_name' => $evaluation->application->announcement->name,
                'teacher_name' => $evaluation->application->user->name,
                'teacher_degree' => $evaluation->application->user->subArea?->name ?? 'N/A',
                'application_date' => $evaluation->application->created_at->isoFormat('D [de] MMMM [de] YYYY'),
                'evaluation_date' => $evaluation->updated_at->isoFormat('D [de] MMMM [de] YYYY'),
                'documents_count' => $evaluation->application->documents_count ?? 0,
                'status' => $finalStatus,
                'score' => $evaluation->score,
            ];
        });

        return Inertia::render('Evaluator/Evaluations/Index', [
            'stats' => [
                'total' => $totalAsignadas,
                'pendientes' => $pendientes,
                'evaluadas' => $evaluadas,
                'aprobadas' => $aprobadas,
                'rechazadas' => $rechazadas,
            ],
            'filters' => $request->all(['search', 'rows', 'status']),
            'applications' => $applications,
        ]);
    }

    public function showHistory($id)
    {
        $evaluation = \App\Models\Evaluation::withAvailableApplication()->where('id', $id)
            ->where('evaluator_id', Auth::id())
            ->with([
                'application.announcement',
                'application.user.subArea',
                'application.user.priorityArea',
                'application.user.institution',
                'application.documents',
                'application.positionType'
            ])
            ->firstOrFail();

        // Get Active Rubric — fallback to most recent if none is marked active
        $rubric = \App\Models\Rubric::forStage(\App\Models\Rubric::LOCAL)->where('is_active', true)
            ->with(['questions.options'])
            ->first()
            ?? \App\Models\Rubric::forStage(\App\Models\Rubric::LOCAL)->with(['questions.options'])
                ->latest()
                ->first();

        $application = $evaluation->application;
        $application->load(['announcement', 'documents', 'user.institution', 'user.priorityArea', 'user.subArea', 'positionType']);

        return Inertia::render('Evaluator/Evaluations/Show', [
            'evaluation' => $evaluation,
            'application' => $application,
            'rubric' => $rubric,
            'teacher' => $application->user,
        ]);
    }
}
