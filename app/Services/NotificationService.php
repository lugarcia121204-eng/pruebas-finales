<?php

namespace App\Services;

use App\Mail\EvaluatorAssigned;
use App\Mail\EvaluatorReminder;
use App\Mail\ApplicationVerdict;
use App\Mail\AnnouncementStageChange;
use App\Mail\AnnouncementDateChange;
use App\Mail\AnnouncementClosed;
use App\Mail\NewAnnouncement;
use App\Mail\RecognitionAvailable;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * Send notification when evaluations are assigned to an evaluator
     */
    public function notifyEvaluatorAssignment($evaluatorId, $evaluationsCount)
    {
        $evaluator = User::findOrFail($evaluatorId);

        // Create notification
        Notification::create([
            'title' => 'Nuevas Evaluaciones Asignadas',
            'data' => [
                'message' => 'Se te han asignado nuevas evaluaciones.',
                'count' => $evaluationsCount,
            ],
            'type' => 'evaluator_assignment',
            'user_id' => $evaluatorId,
        ]);

        // Send email (queued so users don't wait)
        Mail::to($evaluator->email)->queue(new EvaluatorAssigned(
            $evaluator->name,
            $evaluationsCount,
            7 // días hábiles límite
        ));
    }

    /**
     * Send reminder email to an evaluator when their deadline is approaching
     */
    public function notifyEvaluatorReminder(int $evaluatorId, int $pendingCount, int $daysLeft): void
    {
        $evaluator = User::findOrFail($evaluatorId);

        Mail::to($evaluator->email)->queue(new EvaluatorReminder(
            $evaluator->name,
            $pendingCount,
            $daysLeft
        ));
    }

    /**
     * Send notification when an application verdict is made
     */
    public function notifyLocalApplicationVerdict(\App\Models\Application $application): void
    {
        $approved = $application->status === 'approved';
        Notification::create([
            'user_id' => $application->user_id,
            'title' => $approved ? 'Pasaste a la etapa nacional' : 'Solicitud no aprobada en etapa local',
            'type' => 'local_application_verdict',
            'data' => [
                'message' => $approved
                    ? 'Tu solicitud fue aprobada por el administrador local. Has pasado a la etapa nacional y tu solicitud continuar? con la revisi?n nacional.'
                    : 'Tu solicitud no fue aprobada por el administrador local, por lo que no pasa a la etapa nacional. Motivo: '.$application->admin_comment,
                'application_id' => $application->id,
                'announcement' => $application->announcement->name,
                'status' => $application->status,
                'stage' => 'local',
            ],
        ]);
    }

    public function notifyApplicationVerdict($applicationId, $teacherId, $status, $announcementTitle)
    {
        $teacher = User::findOrFail($teacherId);
        $statusText = $status === 'approved' ? 'aprobada' : 'no aceptada';

        // Create notification
        Notification::create([
            'title' => $status === 'approved' ? 'Solicitud Aprobada' : 'Resultado de tu Solicitud',
            'data' => [
                'message' => "Tu solicitud ha sido {$statusText}.",
                'status' => $status,
                'announcement' => $announcementTitle,
                'application_id' => $applicationId,
            ],
            'type' => 'application_verdict',
            'user_id' => $teacherId,
        ]);

        // Send email (queued so users don't wait)
        Mail::to($teacher->email)->queue(new ApplicationVerdict(
            $teacher->name,
            $status,
            $announcementTitle
        ));
    }

    /**
     * Send notification when announcement stage changes
     */
    public function notifyAnnouncementStageChange($announcementId, $announcementTitle, $newStage, $stageDate = null)
    {
        // Get all users with roles: Admin, Evaluador, Docente
        $users = User::whereHas('roles', fn ($roles) => $roles->where('guard_name', 'web')
            ->whereIn('name', ['Admin', 'Admin Nacional', 'Evaluador', 'Docente']))->get();

        foreach ($users as $user) {
            // Create notification
            Notification::create([
                'title' => 'Cambio de Etapa en Convocatoria',
                'data' => [
                    'message' => "La convocatoria '{$announcementTitle}' ha cambiado a la etapa: {$newStage}",
                    'stage' => $newStage,
                    'date' => $stageDate,
                    'announcement_id' => $announcementId,
                ],
                'type' => 'announcement_stage_change',
                'user_id' => $user->id,
            ]);

            // Send email (queued so users don't wait)
            Mail::to($user->email)->queue(new AnnouncementStageChange(
                $user->name,
                $announcementTitle,
                $newStage,
                $stageDate
            ));
        }
    }

    /**
     * Send notification when announcement dates change
     */
    public function notifyAnnouncementDateChange($announcementId, $announcementTitle, $changes)
    {
        // Get all users with roles: Admin, Evaluador, Docente
        $users = User::whereHas('roles', fn ($roles) => $roles->where('guard_name', 'web')
            ->whereIn('name', ['Admin', 'Admin Nacional', 'Evaluador', 'Docente']))->get();

        $changesText = implode(', ', array_column($changes, 'label'));

        foreach ($users as $user) {
            // Create notification
            Notification::create([
                'title' => 'Cambio de Fechas en Convocatoria',
                'data' => [
                    'message' => "Se han actualizado las fechas de la convocatoria '{$announcementTitle}'",
                    'changes' => $changesText,
                ],
                'type' => 'announcement_date_change',
                'user_id' => $user->id,
            ]);

            // Send email (queued so users don't wait)
            Mail::to($user->email)->queue(new AnnouncementDateChange(
                $user->name,
                $announcementTitle,
                $changes
            ));
        }
    }

    /**
     * Send notification when an announcement is closed (results period ended)
     */
    public function notifyAnnouncementClosed($announcementId, $announcementTitle, $resultsEnd = null)
    {
        $users = User::whereHas('roles', fn ($roles) => $roles->where('guard_name', 'web')
            ->whereIn('name', ['Admin', 'Admin Nacional', 'Evaluador', 'Docente']))->get();

        foreach ($users as $user) {
            Notification::create([
                'title' => 'Convocatoria Finalizada',
                'data' => [
                    'message' => "La convocatoria '{$announcementTitle}' ha concluido y fue cerrada automáticamente.",
                    'announcement_id' => $announcementId,
                ],
                'type' => 'announcement_closed',
                'user_id' => $user->id,
            ]);

            try {
                Mail::to($user->email)->queue(new AnnouncementClosed(
                    $user->name,
                    $announcementTitle,
                    $resultsEnd
                ));
            } catch (\Exception $e) {
                \Log::error("❌ Error enviando correo de cierre de convocatoria a {$user->email}: " . $e->getMessage());
            }
        }
    }

    /**
     * Send notification when a new announcement is activated
     */
    public function notifyNewAnnouncement($announcementId)
    {
        $announcement = \App\Models\Announcement::with('calendar')->findOrFail($announcementId);

        // Get all users with roles: Admin, Evaluador, Docente
        $users = User::whereHas('roles', fn ($roles) => $roles->where('guard_name', 'web')
            ->whereIn('name', ['Admin', 'Admin Nacional', 'Evaluador', 'Docente']))->get();

        foreach ($users as $user) {
            // Create database notification
            Notification::create([
                'title' => '¡Nueva Convocatoria Disponible!',
                'data' => [
                    'message' => "Se ha publicado la convocatoria: '{$announcement->name}'",
                    'announcement_id' => $announcement->id,
                    'registration_start' => $announcement->calendar->registration_start ?? null,
                ],
                'type' => 'new_announcement',
                'user_id' => $user->id,
            ]);

            // Send email
            try {
                Mail::to($user->email)->queue(new NewAnnouncement($user->name, $announcement));
            } catch (\Exception $e) {
                \Log::error("❌ Error enviando correo de nueva convocatoria a {$user->email}: " . $e->getMessage());
            }
        }
    }

    /**
     * Send notification when a teacher's recognition becomes available for download.
     */
    public function notifyTeacherRecognition(\App\Models\Recognition $recognition): void
    {
        if ($recognition->type !== 'postulante' || !\App\Models\Recognition::whereKey($recognition->id)->publishable()->exists()) return;
        if (Notification::where('type', 'recognition_available')->where('user_id', $recognition->user_id)
            ->where('data->recognition_id', $recognition->id)->exists()) return;
        $recognition->loadMissing(['user', 'announcement']);
        $stageLabel = 'Etapa nacional';
        try {
            Mail::to($recognition->user->email)->queue(new RecognitionAvailable(
                $recognition->user->name, $recognition->announcement->name, $recognition->stage, $recognition->id
            ));
            Notification::create([
                'title' => 'Reconocimiento disponible - '.$stageLabel,
                'data' => ['message' => 'Tu reconocimiento de '.$stageLabel.' est? disponible para descargar.',
                    'announcement_id' => $recognition->announcement_id, 'recognition_id' => $recognition->id, 'stage' => $recognition->stage],
                'type' => 'recognition_available', 'user_id' => $recognition->user_id,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('No se pudo enviar el reconocimiento docente', ['recognition_id' => $recognition->id, 'exception' => get_class($e)]);
        }
    }

    public function notifyRecognitionAvailable($userId, $announcementId, $announcementTitle, ?int $recognitionId = null, ?string $stage = null): void
    {
        $teacher = User::findOrFail($userId);
        $stageLabel = $stage === 'national' ? 'Etapa nacional' : null;

        Notification::create([
            'title' => 'Reconocimiento Disponible'.($stageLabel ? ' - '.$stageLabel : ''),
            'data' => [
                'message' => "Tu reconocimiento de la convocatoria '{$announcementTitle}' ya está disponible para descargar.",
                'announcement_id' => $announcementId,
                'recognition_id' => $recognitionId,
                'stage' => $stage,
            ],
            'type' => 'recognition_available',
            'user_id' => $userId,
        ]);

        try {
            Mail::to($teacher->email)->queue(new RecognitionAvailable(
                $teacher->name,
                $announcementTitle,
                $stage
            ));
        } catch (\Exception $e) {
            \Log::error("❌ Error enviando correo de reconocimiento a {$teacher->email}: " . $e->getMessage());
        }
    }
}
