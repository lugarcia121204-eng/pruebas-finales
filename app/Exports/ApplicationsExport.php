<?php

namespace App\Exports;

use App\Models\Application;
use App\Services\ApplicationReportFilters;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class ApplicationsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    protected $search;

    protected $institutionId;

    protected $stateId;

    protected $status;

    protected array $filters = [];

    public static function fromFilters(array $filters): self
    {
        $export = new self($filters['search'] ?? null, $filters['institution_id'] ?? null, $filters['state_id'] ?? null, $filters['status'] ?? null);
        $export->filters = $filters;

        return $export;
    }

    public function __construct($search = null, $institutionId = null, $stateId = null, $status = null)
    {
        $this->search = $search;
        $this->institutionId = $institutionId;
        $this->stateId = $stateId;
        $this->status = $status;
    }

    public function collection()
    {
        $query = Application::query()
            ->join('users', 'applications.user_id', '=', 'users.id')
            ->join('institutions', 'users.institution_id', '=', 'institutions.id')
            ->join('states', 'institutions.state_id', '=', 'states.id')
            ->leftJoin('announcements', 'applications.announcement_id', '=', 'announcements.id')
            ->leftJoin('position_types', 'applications.position_type_id', '=', 'position_types.id')
            ->leftJoin('priority_areas', 'users.priority_area_id', '=', 'priority_areas.id')
            ->leftJoin('sub_areas', 'users.sub_area_id', '=', 'sub_areas.id')
            ->select(
                'applications.id',
                'users.name as teacher_name',
                'users.email as teacher_email',
                'institutions.name as institution_name',
                'states.name as state_name',
                'announcements.name as announcement_name',
                'position_types.name as position_type',
                'applications.status',
                'applications.created_at',
                'priority_areas.name as area_name',
                'sub_areas.name as sub_area_name'
            );

        $filters = $this->filters + ['search' => $this->search, 'institution_id' => $this->institutionId, 'state_id' => $this->stateId, 'status' => $this->status];
        $query->whereHas('user.institution', fn ($institution) => ApplicationReportFilters::institutions($institution, $filters));
        ApplicationReportFilters::apply($query, $filters);

        return $query->orderBy('applications.created_at', 'desc')->get();
    }

    public function map($row): array
    {
        $statusMap = [
            'approved' => 'Aprobada',
            'rejected' => 'Rechazada',
            'expired' => 'Expirada',
            'pending' => 'Pendiente',
            'in_review' => 'En revisión',
        ];

        return [
            $row->id,
            $row->teacher_name,
            $row->teacher_email,
            $row->institution_name,
            $row->state_name,
            $row->announcement_name ?? 'N/A',
            $row->position_type ?? 'N/A',
            $statusMap[$row->status] ?? $row->status,
            \Carbon\Carbon::parse($row->created_at)->format('d/m/Y'),
            $row->area_name ?? 'No asignada',
            $row->sub_area_name ?? 'No asignada',
        ];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Docente',
            'Correo',
            'Institución',
            'Estado',
            'Convocatoria',
            'Tipo de Plaza',
            'Estatus',
            'Fecha de Solicitud',
            'Área',
            'Subárea',
        ];
    }

    public function title(): string
    {
        return 'Solicitudes';
    }
}
