<?php

namespace App\Console\Commands;

use App\Models\{Application, Recognition};
use App\Services\PdfGenerationService;
use Illuminate\Console\Command;

class ArchiveRecognitionPdfs extends Command
{
    protected $signature = 'recognitions:archive-pdfs';
    protected $description = 'Almacena los PDF docentes aprobados en el disco local privado, sin enviar correos';

    public function handle(PdfGenerationService $pdf): int
    {
        $saved = 0; $failed = 0;
        foreach (Recognition::where('type', 'postulante')->where('stage', 'national')->with('user')->get() as $recognition) {
            $eligible = Application::where('user_id', $recognition->user_id)->where('announcement_id', $recognition->announcement_id)
                ->finallyApproved()->exists();
            if (!$eligible) continue;
            try {
                $pdf->storeTeacherRecognition($recognition, $recognition->user);
                $saved++;
            } catch (\Throwable $error) {
                $failed++;
                $this->error('No se pudo archivar el reconocimiento '.$recognition->id.': '.get_class($error));
            }
        }
        $this->info('PDF disponibles en almacenamiento privado: '.$saved.'. Errores: '.$failed);
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
