<?php

namespace App\Services;

use App\Models\Announcement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class ApprovedReportPdf extends \setasign\Fpdi\Fpdi
{
    private array $widths = [17, 48, 70, 55];
    private string $letterhead;
    private array $letterheadSize;
    private string $reportTitle;
    private string $announcementName;
    private string $scopeName;

    public function __construct(Announcement $announcement, string $stage, string $scope)
    {
        parent::__construct('P', 'mm', 'Letter');
        $path = 'templates/template_reporte_membrete.pdf';
        abort_unless(Storage::disk('public')->exists($path), 404, 'No se encuentra el membrete del reporte.');
        $this->setSourceFile(Storage::disk('public')->path($path));
        $this->letterhead = $this->importPage(1);
        $this->letterheadSize = $this->getTemplateSize($this->letterhead);
        $this->SetMargins(13, 46, 13);
        $this->SetAutoPageBreak(false);
        $this->AliasNbPages();
        $this->reportTitle = $stage === 'local' ? 'Aprobados en etapa local' : 'Aprobados en etapa nacional';
        $this->announcementName = $announcement->name;
        $this->scopeName = $scope;
        $this->SetTitle($this->encoded($this->reportTitle));
        $this->SetAuthor('Sistema EDD - TecNM');
    }

    private function encoded(string $text): string
    {
        return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text) ?: '';
    }

    public function Header()
    {
        $this->useTemplate($this->letterhead, 0, 0, $this->letterheadSize['width'], $this->letterheadSize['height']);
        $this->SetXY(13, 46);
        $this->SetTextColor(105, 111, 120);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(0, 5, 'REPORTE DE RESULTADOS', 0, 1);
        $this->SetTextColor(27, 57, 106);
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, $this->encoded($this->reportTitle), 0, 1);
        $this->SetTextColor(90, 96, 105);
        $this->SetFont('Arial', '', 9);
        $this->Cell(0, 6, $this->encoded('Programa de Estímulos al Desempeño del Personal Docente'), 0, 1);
        $this->SetDrawColor(180, 150, 88);
        $this->SetLineWidth(0.5);
        $this->Line(13, 69, 203, 69);

        $this->SetFont('Arial', 'B', 10);
        $nameLines = $this->lines($this->announcementName, 180);
        $this->SetFont('Arial', '', 9);
        $scopeLines = $this->lines($this->scopeName, 120);
        $boxHeight = 22 + count($nameLines) * 5 + count($scopeLines) * 4.5;
        $this->SetFillColor(245, 247, 250);
        $this->Rect(13, 74, 190, $boxHeight, 'F');
        $this->SetFillColor(27, 57, 106);
        $this->Rect(13, 74, 1, $boxHeight, 'F');
        $this->SetXY(17, 78);
        $this->SetTextColor(105, 111, 120);
        $this->SetFont('Arial', 'B', 7);
        $this->Cell(0, 4, 'CONVOCATORIA', 0, 1);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(35, 45, 60);
        foreach ($nameLines as $line) {
            $this->SetX(17); $this->Cell(180, 5, $line, 0, 1);
        }
        $rowY = $this->GetY() + 3;
        $this->SetXY(17, $rowY);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(105, 111, 120);
        $this->Cell(125, 4, $this->encoded('ÁMBITO'), 0, 0);
        $this->Cell(55, 4, $this->encoded('FECHA DE EMISIÓN'), 0, 1);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(65, 72, 82);
        $this->SetXY(142, $rowY + 4);
        $this->Cell(55, 4.5, now('America/Mexico_City')->format('d/m/Y H:i'), 0, 1);
        $this->SetXY(17, $rowY + 4);
        foreach ($scopeLines as $line) {
            $this->SetX(17); $this->Cell(120, 4.5, $line, 0, 1);
        }
        $this->SetXY(13, 74 + $boxHeight + 7);
        $this->SetFillColor(27, 57, 106);
        $this->SetTextColor(255);
        $this->SetFont('Arial', 'B', 8.5);
        foreach (['Solicitud', 'Docente', 'Institución', 'Correo electrónico'] as $i => $label) {
            $this->Cell($this->widths[$i], 9, $this->encoded($label), 0, 0, 'L', true);
        }
        $this->Ln();
    }

    public function Footer()
    {
        $this->SetXY(13, 233);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100);
        $this->Cell(0, 5, $this->encoded('Sistema EDD | Página '.$this->PageNo().' de {nb}'), 0, 0, 'R');
    }

    private function lines(string $text, float $width): array
    {
        $lines = []; $line = '';
        foreach (str_split($this->encoded(preg_replace('/\s+/u', ' ', $text))) as $character) {
            if ($this->GetStringWidth($line.$character) > $width && $line !== '') {
                $space = strrpos($line, ' ');
                if ($space !== false) {
                    $lines[] = substr($line, 0, $space);
                    $line = substr($line, $space + 1);
                } else {
                    $lines[] = $line;
                    $line = '';
                }
            }
            $line .= $character;
        }
        $lines[] = $line;
        return $lines;
    }

    public function render(Collection $applications): string
    {
        $this->AddPage('P', [$this->letterheadSize['width'], $this->letterheadSize['height']]);
        foreach ($applications as $index => $application) {
            $this->SetFont('Arial', '', 9);
            $values = [(string) $application->id, $application->user?->name ?? '',
                $application->user?->institution?->name ?? 'Sin institución asignada', $application->user?->email ?? ''];
            $lines = array_map(fn ($value, $width) => $this->lines($value, $width - 4), $values, $this->widths);
            $height = max(12, max(array_map('count', $lines)) * 5 + 4);
            $reserved = $index === $applications->count() - 1 ? 15 : 0;
            if ($this->GetY() + $height + $reserved > 230) $this->AddPage('P', [$this->letterheadSize['width'], $this->letterheadSize['height']]);
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(35);
            $this->SetFillColor(...($index % 2 ? [245, 247, 250] : [255, 255, 255]));
            $this->SetDrawColor(225, 229, 235);
            $this->SetLineWidth(0.15);
            $x = 13; $y = $this->GetY();
            foreach ($lines as $column => $textLines) {
                $this->Rect($x, $y, $this->widths[$column], $height, 'F');
                $this->Line($x, $y + $height, $x + $this->widths[$column], $y + $height);
                $this->SetXY($x + 2, $y + 2);
                foreach ($textLines as $line) {
                    $this->SetX($x + 2);
                    $this->Cell($this->widths[$column] - 4, 5, $line, 0, 2);
                }
                $x += $this->widths[$column];
            }
            $this->SetXY(13, $y + $height);
        }
        if ($this->GetY() + 15 > 230) $this->AddPage('P', [$this->letterheadSize['width'], $this->letterheadSize['height']]);
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(27, 57, 106);
        $this->SetFillColor(238, 242, 248);
        $this->Cell(190, 10, $this->encoded('Total de docentes aprobados: '.$applications->count().'  '), 0, 1, 'R', true);
        if ($applications->isEmpty()) {
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 7, $this->encoded('No hay docentes aprobados en esta etapa.'), 0, 1);
        }
        return $this->Output('S');
    }
}
