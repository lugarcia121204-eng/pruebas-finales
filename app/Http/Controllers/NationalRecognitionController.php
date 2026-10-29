<?php

namespace App\Http\Controllers;

use App\Models\Recognition;
use App\Services\PdfGenerationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NationalRecognitionController extends Controller
{
    private function assigned(Request $request)
    {
        return Recognition::where('type', 'postulante')->where('stage', 'national')
            ->whereExists(function ($query) use ($request) {
                $query->selectRaw('1')->from('applications')
                    ->join('national_reviews', 'national_reviews.application_id', '=', 'applications.id')
                    ->whereColumn('applications.user_id', 'recognitions.user_id')
                    ->whereColumn('applications.announcement_id', 'recognitions.announcement_id')
                    ->whereNull('applications.deleted_at')->where('applications.status', 'approved')
                    ->where('national_reviews.status', 'approved')
                    ->where('national_reviews.administrator_id', $request->user()->id);
            });
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'rows' => ['nullable', 'integer', 'in:5,10,25,50']]);
        return Inertia::render('NationalReview/Recognitions', [
            'recognitions' => $this->assigned($request)->with(['user.institution', 'announcement.calendar'])
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($match) => $match
                    ->whereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('announcement', fn ($announcement) => $announcement->where('name', 'like', "%{$search}%"))))
                ->latest('sent_at')->paginate($filters['rows'] ?? 10)->withQueryString()->through(fn ($recognition) => [
                    'id' => $recognition->id, 'teacher' => $recognition->user?->name,
                    'institution' => $recognition->user?->institution?->name,
                    'announcement' => $recognition->announcement?->name,
                    'identifier' => $recognition->identifier,
                    'date' => $recognition->sent_at?->format('d/m/Y'),
                    'active' => $recognition->active,
                    'available' => Recognition::whereKey($recognition->id)->publishable()->exists(),
                ]),
            'filters' => $filters,
        ]);
    }

    public function toggle(Request $request, int $id)
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $id, $data) {
            $recognition = $this->assigned($request)->lockForUpdate()->findOrFail($id);
            $recognition->active = $data['active'];
            $recognition->activation_override = $data['active'];
            $recognition->save();
        });
        return back()->with('success', $data['active'] ? 'Reconocimiento activado.' : 'Reconocimiento desactivado.');
    }

    public function download(Request $request, int $id)
    {
        $recognition = $this->assigned($request)->publishable()->findOrFail($id);
        try {
            return app(PdfGenerationService::class)->generateTeacherRecognitionPdf($recognition, $recognition->user);
        } catch (\Throwable $e) {
            return response()->view('errors.pdf_missing', ['message' => 'El reconocimiento no se encuentra disponible. Verifica la plantilla configurada.'], 404);
        }
    }
}
