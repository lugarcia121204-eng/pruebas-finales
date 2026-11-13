<?php

namespace App\Http\Controllers;

use App\Models\NationalReview;
use App\Models\User;
use App\Models\Rubric;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class NationalReviewController extends Controller
{
    private function assigned(Request $request)
    {
        return NationalReview::where('administrator_id', $request->user()->id)
            ->whereHas('application', fn ($query) => $query->approved());
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'rows' => ['nullable', 'integer', 'in:5,10,25,50'],
            'sort_field' => ['nullable', 'in:teacher,institution,status'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);
        $sortColumns = ['teacher' => 'users.name', 'institution' => 'institutions.name', 'status' => 'national_reviews.status'];
        return Inertia::render('NationalReview/Index', [
            'reviews' => $this->assigned($request)->with(['application.user.institution', 'application.announcement'])
                ->join('applications', 'applications.id', '=', 'national_reviews.application_id')
                ->join('users', 'users.id', '=', 'applications.user_id')
                ->leftJoin('institutions', 'institutions.id', '=', 'users.institution_id')
                ->select('national_reviews.*')->withCount('evaluations')
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('national_reviews.status', $status))
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereHas('application', fn ($app) => $app->where(fn ($match) => $match
                    ->buscarGlobal($search)->orWhereHas('announcement', fn ($announcement) => $announcement->where('name', 'like', "%{$search}%")))))
                ->orderBy($sortColumns[$filters['sort_field'] ?? ''] ?? 'national_reviews.created_at', $filters['sort_direction'] ?? 'desc')
                ->orderByDesc('national_reviews.id')->paginate($filters['rows'] ?? 10)->withQueryString()->through(fn ($review) => [
                    'id' => $review->id,
                    'application_id' => $review->application_id,
                    'teacher' => $review->application->user?->name,
                    'institution' => $review->application->user?->institution?->name,
                    'announcement' => $review->application->announcement?->name,
                    'status' => $review->status,
                    'evaluators_count' => $review->evaluations_count,
                ]),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, int $id)
    {
        $review = $this->assigned($request)->with(['application.user.institution.state', 'application.user.priorityArea', 'application.user.subArea', 'application.positionType', 'application.announcement.calendar', 'application.documents', 'evaluations.evaluator', 'evaluations.rubric.questions.options'])->findOrFail($id);
        return Inertia::render('NationalReview/Show', [
            'review' => [
                'id' => $review->id,
                'application_id' => $review->application_id,
                'status' => $review->status,
                'comment' => $review->comment,
                'teacher_email' => $review->application->user?->email,
                'teacher_curp' => $review->application->user?->curp,
                'priority_area' => $review->application->user?->priorityArea?->name,
                'sub_area' => $review->application->user?->subArea?->name,
                'state' => $review->application->user?->institution?->state?->name,
                'position_type' => $review->application->position_full_type,
                'via' => $review->application->via,
                'application_date' => $review->application->created_at?->toIso8601String(),
                'teacher' => $review->application->user?->name,
                'institution' => $review->application->user?->institution?->name,
                'announcement' => $review->application->announcement?->name,
                'documents' => $review->application->documents->map(fn ($document) => ['id' => $document->id, 'name' => $document->name]),
                'evaluations' => $review->evaluations->map(fn ($evaluation) => ['id' => $evaluation->id, 'evaluator_id' => $evaluation->evaluator_id, 'evaluator' => $evaluation->evaluator?->name, 'status' => $evaluation->status, 'score' => $evaluation->score, 'comment' => $evaluation->comment, 'responses' => $evaluation->rubric?->questions->map(function ($question) use ($evaluation) {
                    $option = $question->options->firstWhere('id', $evaluation->answers[$question->id] ?? null);
                    return ['id' => $question->id, 'question' => $question->text, 'answer' => $option?->text, 'score' => $option?->score];
                })->values() ?? []]),
            ],
            'evaluationPeriodOpen' => $review->application->announcement?->calendar?->nationalEvaluationIsOpen() ?? false,
            'evaluators' => User::whereHas('roles', fn ($query) => $query->where('name', 'Evaluador'))
                ->with('institution:id,name')
                ->when($review->application->user?->institution_id, fn ($query, $institutionId) => $query->where('institution_id', '!=', $institutionId))
                ->whereNotIn('id', [$review->application->user_id, $review->administrator_id])->orderBy('name')->get(['id', 'name', 'institution_id'])
                ->map(fn ($evaluator) => [
                    'id' => $evaluator->id,
                    'name' => $evaluator->name,
                    'label' => $evaluator->name . ' — ' . ($evaluator->institution?->name ?? 'Sin institución asignada'),
                ]),
        ]);
    }

    public function assignEvaluators(Request $request, int $id)
    {
        $data = $request->validate(['evaluator_ids' => ['required', 'array', 'min:1'], 'evaluator_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id']]);
        DB::transaction(function () use ($request, $id, $data) {
            $review = $this->assigned($request)->lockForUpdate()->findOrFail($id);
            abort_unless($review->status === 'pending', 409);
            if (!$review->application->announcement?->calendar?->nationalEvaluationIsOpen()) {
                throw ValidationException::withMessages(['evaluator_ids' => 'La convocatoria está fuera del periodo de evaluación nacional.']);
            }
            $rubric = Rubric::active()->with('questions.options')->latest('id')->first();
            if (!$rubric || !$rubric->questions->count() || $rubric->questions->contains(fn ($question) => !$question->options->count())) {
                throw ValidationException::withMessages(['evaluator_ids' => 'Configura una rúbrica activa con preguntas y opciones antes de asignar evaluadores.']);
            }
            foreach ($data['evaluator_ids'] as $evaluatorId) {
                $evaluator = User::whereHas('roles', fn ($query) => $query->where('name', 'Evaluador'))->find($evaluatorId);
                if (!$evaluator || in_array($evaluator->id, [$review->application->user_id, $review->administrator_id])) {
                    throw ValidationException::withMessages(['evaluator_ids' => 'Selecciona evaluadores distintos al solicitante y al administrador asignado.']);
                }
                $teacherInstitutionId = $review->application->user?->institution_id;
                if ($teacherInstitutionId && (!$evaluator->institution_id || (int) $evaluator->institution_id === (int) $teacherInstitutionId)) {
                    throw ValidationException::withMessages(['evaluator_ids' => 'Selecciona evaluadores de una sede distinta a la del docente.']);
                }
                $review->evaluations()->firstOrCreate(['evaluator_id' => $evaluator->id], ['rubric_id' => $rubric->id, 'status' => 'pending']);
            }
        });
        return back()->with('success', 'Evaluadores asignados para la etapa nacional.');
    }

    public function verdict(Request $request, int $id)
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected'], 'comment' => ['required_if:status,rejected', 'nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $id, $data) {
            $review = $this->assigned($request)->lockForUpdate()->findOrFail($id);
            abort_unless($review->status === 'pending', 409);
            if (!$review->application->announcement?->calendar?->nationalEvaluationIsOpen()) {
                throw ValidationException::withMessages(['status' => 'La convocatoria está fuera del periodo de evaluación nacional.']);
            }
            if (!$review->evaluations()->exists() || $review->evaluations()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['status' => 'Todas las evaluaciones nacionales deben estar completas antes de emitir el dictamen.']);
            }
            $review->update(['status' => $data['status'], 'comment' => $data['comment'] ?? null, 'decided_at' => now()]);
            app(\App\Services\FinalRecognitionService::class)->sync($review->application);
        });
        return back()->with('success', 'Dictamen nacional registrado.');
    }

    public function document(Request $request, int $id, int $document)
    {
        $review = $this->assigned($request)->findOrFail($id);
        $file = $review->application->documents()->findOrFail($document);
        abort_unless(Storage::disk('public')->exists($file->file_path), 404);
        return Storage::disk('public')->response($file->file_path, $file->name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
