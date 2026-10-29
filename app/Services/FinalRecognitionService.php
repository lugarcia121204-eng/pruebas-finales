<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Recognition;

class FinalRecognitionService
{
    public function sync(Application $application): void
    {
        $pdf = app(PdfGenerationService::class);
        Recognition::where('user_id', $application->user_id)
            ->where('announcement_id', $application->announcement_id)
            ->where('type', 'postulante')->where('stage', 'local')
            ->update(['active' => false]);

        $stage = 'national';
        $approved = Application::whereKey($application->id)
            ->finallyApproved()->exists();
        $recognition = Recognition::firstOrNew([
            'user_id' => $application->user_id,
            'announcement_id' => $application->announcement_id,
            'type' => 'postulante',
            'stage' => $stage,
        ]);
        if (!$approved) {
            if ($recognition->exists && $recognition->active) $recognition->update(['active' => false]);
            return;
        }
        $recognition->active = $recognition->activation_override ?? true;
        $recognition->sent_at ??= now();
        $recognition->save();
        $pdf->freezeAcceptanceLetter($application);
        $pdf->freezeTeacherRecognition($recognition, $application->user);
        \Illuminate\Support\Facades\DB::afterCommit(function () use ($recognition) {
            if ($recognition->template_id && $recognition->identifier) {
                try {
                    app(PdfGenerationService::class)->storeTeacherRecognition($recognition, $recognition->user);
                } catch (\Throwable $error) {
                    \Illuminate\Support\Facades\Log::error('No se pudo archivar el reconocimiento', ['recognition_id' => $recognition->id, 'exception' => get_class($error)]);
                }
            }
            app(NotificationService::class)->notifyTeacherRecognition($recognition);
        });
    }
}
