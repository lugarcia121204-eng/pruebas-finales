<?php

namespace App\Http\Controllers;

use App\Models\{NationalEvaluation, Recognition, User};
use App\Services\{NotificationService, PdfGenerationService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class NationalEvaluatorRecognitionController extends Controller
{
    private function assigned(Request $request)
    {
        return NationalEvaluation::whereHas('review', fn ($review) => $review
            ->where('administrator_id', $request->user()->id)
            ->whereHas('application', fn ($application) => $application->approved()->whereHas('announcement')));
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'rows' => ['nullable', 'integer', 'in:5,10,25,50'],
            'sort_field' => ['nullable', 'in:evaluator_name,announcement_name'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
        ]);
        $recognitions = $this->assigned($request)
            ->join('national_reviews', 'national_reviews.id', '=', 'national_evaluations.national_review_id')
            ->join('applications', 'applications.id', '=', 'national_reviews.application_id')
            ->join('announcements', 'announcements.id', '=', 'applications.announcement_id')
            ->join('users', 'users.id', '=', 'national_evaluations.evaluator_id')
            ->leftJoin('recognitions', function ($join) {
                $join->on('recognitions.user_id', '=', 'users.id')
                    ->on('recognitions.announcement_id', '=', 'announcements.id')
                    ->where('recognitions.type', 'evaluator')->where('recognitions.stage', 'national');
            })
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($match) => $match
                ->where('users.name', 'like', "%{$search}%")->orWhere('announcements.name', 'like', "%{$search}%")))
            ->select(['users.id as evaluator_id', 'users.name as evaluator_name', 'announcements.id as announcement_id',
                'announcements.name as announcement_name', 'recognitions.id as recognition_id',
                'recognitions.identifier', 'recognitions.sent_at'])
            ->selectRaw("SUM(CASE WHEN national_evaluations.status = 'completed' THEN 1 ELSE 0 END) as applications_reviewed")
            ->selectRaw('COUNT(national_evaluations.id) as assigned_count, COALESCE(recognitions.active, 0) as active')
            ->groupBy('users.id', 'users.name', 'announcements.id', 'announcements.name',
                'recognitions.id', 'recognitions.identifier', 'recognitions.sent_at', 'recognitions.active')
            ->orderBy(($filters['sort_field'] ?? '') === 'announcement_name' ? 'announcements.name' : 'users.name', $filters['sort_direction'] ?? 'asc')
            ->orderBy(($filters['sort_field'] ?? '') === 'announcement_name' ? 'users.name' : 'announcements.name')
            ->orderBy('users.id')->orderBy('announcements.id')
            ->paginate($filters['rows'] ?? 10)->withQueryString()
            ->through(fn ($row) => [
                'evaluator_id' => $row->evaluator_id, 'evaluator_name' => $row->evaluator_name,
                'announcement_id' => $row->announcement_id, 'announcement_name' => $row->announcement_name,
                'applications_reviewed' => (int) $row->applications_reviewed, 'assigned_count' => (int) $row->assigned_count,
                'active' => (bool) $row->active, 'identifier' => $row->identifier,
            ]);
        return Inertia::render('NationalReview/EvaluatorRecognitions', compact('recognitions', 'filters'));
    }

    public function toggle(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'announcement_id' => ['required', 'integer', 'exists:announcements,id'],
            'active' => ['required', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $data) {
            $evaluations = $this->assigned($request)->where('evaluator_id', $data['user_id'])
                ->whereHas('review.application', fn ($application) => $application->where('announcement_id', $data['announcement_id']))
                ->lockForUpdate()->get();
            abort_if($evaluations->isEmpty(), 404);
            if (!$evaluations->contains('status', 'completed')) {
                throw ValidationException::withMessages(['active' => 'El evaluador debe completar al menos una evaluación nacional de tus solicitudes.']);
            }
            $evaluator = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            // The existing evaluator certificate covers participation in both local and national review.
            $recognition = Recognition::firstOrNew([
                'user_id' => $evaluator->id, 'announcement_id' => $data['announcement_id'],
                'type' => 'evaluator', 'stage' => 'national',
            ]);
            if ((bool) $recognition->active === (bool) $data['active']) return;
            $recognition->active = $data['active'];
            if ($recognition->active) $recognition->sent_at ??= now();
            $recognition->save();
            if (!$recognition->active) return;
            app(PdfGenerationService::class)->freezeEvaluatorRecognition($recognition, $evaluator);
            DB::afterCommit(function () use ($recognition) {
                if (\App\Models\Notification::where('type', 'recognition_available')->where('user_id', $recognition->user_id)
                    ->where('data->recognition_id', $recognition->id)->exists()) return;
                app(NotificationService::class)->notifyRecognitionAvailable($recognition->user_id, $recognition->announcement_id,
                    $recognition->announcement->name, $recognition->id, 'national');
            });
        });
        return back()->with('success', $data['active'] ? 'Reconocimiento de evaluador activado.' : 'Reconocimiento de evaluador desactivado.');
    }
}
