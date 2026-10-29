<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Recognition;
use Illuminate\Console\Command;

class ReconcileFinalRecognitions extends Command
{
    protected $signature = 'recognitions:reconcile-final {--apply : Desactivar reconocimientos sin aprobación nacional}';
    protected $description = 'Detecta reconocimientos docentes activos que no tienen aprobación final';

    public function handle(): int
    {
        $query = Recognition::where('type', 'postulante')->where('stage', 'national')->where('active', true)
            ->whereNotExists(Application::finallyApproved()->selectRaw('1')
                ->whereColumn('applications.user_id', 'recognitions.user_id')
                ->whereColumn('applications.announcement_id', 'recognitions.announcement_id')->toBase());
        $this->info('Reconocimientos sin aprobación final: ' . (clone $query)->count());
        if ($this->option('apply')) $this->info('Desactivados: ' . $query->update(['active' => false]));
        return self::SUCCESS;
    }
}
