<?php

namespace App\Http\Controllers;

use App\Models\NationalEvaluation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class NationalEvaluationController extends Controller
{
    private function assigned(Request $request)
    {
        return NationalEvaluation::where('evaluator_id', $request->user()->id)
            ->whereHas('review', fn ($query) => $query->whereHas('application', fn ($app) => $app->approved()));
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:pending,completed'],
            'rows' => ['nullable', 'integer', 'in:5,10,25,50'],
        ]);
        $base = $this->assigned($request);
        return Inertia::render('NationalEvaluation/Index', [
            'stats' => [
                'completed' => (clone $base)->where('status', 'completed')->count(),
                'pending' => (clone $base)->where('status', 'pending')->count(),
                'total' => (clone $base)->count(),
            ],
            'filters' => $filters,
            'applications' => $base->with(['review.application.user', 'review.application.announcement', 'review.application' => fn ($query) => $query->withCount('documents')])
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereHas('review.application', fn ($app) => $app->where(fn ($match) => $match
                    ->whereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('announcement', fn ($announcement) => $announcement->where('name', 'like', "%{$search}%")))))
                ->latest()->paginate($filters['rows'] ?? 10)->withQueryString()->through(fn ($evaluation) => [
                    'evaluation_id' => $evaluation->id, 'status' => $evaluation->status,
                    'teacher_name' => $evaluation->review->application->user?->name,
                    'announcement_name' => $evaluation->review->application->announcement?->name,
                    'documents_count' => $evaluation->review->application->documents_count,
                    'evaluation_date' => ($evaluation->status === 'pending' ? $evaluation->created_at : $evaluation->updated_at)->translatedFormat('d \d\e F \d\e Y'),
                    'score' => $evaluation->score,
                ]),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $evaluation = $this->assigned($request)->with(['rubric.questions.options', 'review.application.user.institution', 'review.application.user.priorityArea', 'review.application.user.subArea', 'review.application.positionType', 'review.application.announcement.calendar', 'review.application.documents'])->findOrFail($id);
        return Inertia::render('NationalEvaluation/Show', [
            'evaluation' => $evaluation->only(['id', 'status', 'answers', 'score', 'comment', 'created_at', 'updated_at']),
            'applicant' => [
                'institution' => $evaluation->review->application->user?->institution?->name,
                'priority_area' => $evaluation->review->application->user?->priorityArea?->name,
                'sub_area' => $evaluation->review->application->user?->subArea?->name,
                'announcement' => $evaluation->review->application->announcement?->name,
                'position_type' => $evaluation->review->application->position_full_type,
                'via' => $evaluation->review->application->via,
                'application_date' => $evaluation->review->application->created_at?->toIso8601String(),
            ],
            'evaluationPeriodOpen' => $evaluation->review->application->announcement?->calendar?->nationalEvaluationIsOpen() ?? false,
            'reviewStatus' => $evaluation->review->status,
            'teacher' => $evaluation->review->application->user?->name,
            'rubric' => $evaluation->rubric,
            'documents' => $evaluation->review->application->documents->map(fn ($document) => $document->only(['id', 'name'])),
        ]);
    }

    public function submit(Request $request, int $id)
    {
        $data = $request->validate(['answers' => ['required', 'array'], 'comment' => ['nullable', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $id, $data) {
            $evaluation = $this->assigned($request)->findOrFail($id);
            $review = $evaluation->review()->lockForUpdate()->firstOrFail();
            $evaluation = $this->assigned($request)->lockForUpdate()->findOrFail($id);
            abort_unless($review->status === 'pending' && $evaluation->status === 'pending', 409);
            if (!$review->application->announcement?->calendar?->nationalEvaluationIsOpen()) {
                throw ValidationException::withMessages(['answers' => 'La convocatoria está fuera del periodo de evaluación nacional.']);
            }
            $rubric = $evaluation->rubric()->with('questions.options')->first();
            if (!$rubric || !$rubric->questions->count() || count($data['answers']) !== $rubric->questions->count()) {
                throw ValidationException::withMessages(['answers' => 'Responde todas las preguntas de la rúbrica asignada.']);
            }
            $score = 0;
            $answers = [];
            foreach ($rubric->questions as $question) {
                $optionId = $data['answers'][$question->id] ?? null;
                $option = is_scalar($optionId) ? $question->options->firstWhere('id', $optionId) : null;
                if (!$option) {
                    throw ValidationException::withMessages(['answers' => 'Selecciona una opción válida para cada pregunta.']);
                }
                $score += (float) $option->score;
                $answers[$question->id] = $option->id;
            }
            $evaluation->update(['status' => 'completed', 'answers' => $answers, 'score' => $score, 'comment' => $data['comment'] ?? null]);
        });
        return back()->with('success', 'Evaluación nacional registrada.');
    }

    public function document(Request $request, int $id, int $document)
    {
        $evaluation = $this->assigned($request)->findOrFail($id);
        $file = $evaluation->review->application->documents()->findOrFail($document);
        abort_unless(Storage::disk('public')->exists($file->file_path), 404);
        return Storage::disk('public')->response($file->file_path, $file->name, ['X-Content-Type-Options' => 'nosniff']);
    }
}
