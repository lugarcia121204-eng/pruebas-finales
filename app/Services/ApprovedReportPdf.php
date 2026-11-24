<?php

namespace App\Services;

use App\Models\Announcement;
use Illuminate\Support\Collection;

class ApprovedReportPdf extends \setasign\Fpdi\Fpdi
{
    private array $widths = [150, 150, 100, 40, 40, 40, 40];
    private string $background;
    private string $signatures;
    private string $stage;
    private \Carbon\CarbonInterface $exportedAt;
    private bool $signaturePage = false;

    public function __construct(Announcement $announcement, string $stage, string $scope)
    {
        parent::__construct('P', 'pt', 'Letter');
        $this->stage = $stage;
        $this->exportedAt = now('America/Mexico_City');
        $this->SetMargins(26, 40, 26);
        $this->SetAutoPageBreak(false);
        $this->AliasNbPages();
        $this->setSourceFile(resource_path('pdf/acta-resultados-'.($stage === 'local' ? 'local' : 'national').'.pdf'));
        $this->background = $this->importPage(1);
        $this->setSourceFile(resource_path('pdf/acta-resultados-firmas.pdf'));
        $this->signatures = $this->importPage(1);
        $this->SetTitle($this->encoded('Acta de resultados de evaluación '.($stage === 'local' ? 'local' : 'nacional').' 2026'));
        $this->SetAuthor('Sistema EDD - TecNM');
    }

    private function encoded(string $text): string
    {
        return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text) ?: '';
    }

    public function Header()
    {
        if ($this->PageNo() === 1) {
            $this->useTemplate($this->background, 0, 0, 612, 792);
            $this->exportDateParagraph();
        }
        if (!$this->signaturePage) $this->tableHeader($this->PageNo() === 1 ? 281 : 40);
    }

    private function exportDateParagraph(): void
    {
        $date = $this->exportedAt->copy()->locale('es')->translatedFormat('j \d\e F \d\e Y');
        $text = ' a las '.$this->exportedAt->format('H:i').' horas del día '.$date
            .', en el domicilio: Interior Internado Palmira S/N, Col. Palmira, Cuernavaca, Morelos.';
        $this->SetLeftMargin(30);
        $this->SetRightMargin(30);
        $this->SetXY(30, 196.02);
        $this->SetTextColor(0);
        $this->SetFont('Arial', 'B', 10);
        $this->Write(11.56, 'PERSONAL DOCENTE 2026');
        $this->SetFont('Arial', '', 10);
        $this->Write(11.56, $this->encoded($text));
        $this->SetLeftMargin(26);
        $this->SetRightMargin(26);
    }

    private function tableHeader(float $y): void
    {
        $this->SetFillColor(27, 57, 106);
        $this->Rect(26, $y, 560, 35, 'F');
        $this->SetTextColor(255);
        $this->SetFont('Arial', 'B', 8);
        $this->SetXY(426, $y);
        $this->MultiCell(80, 9, "Por Persona\nParticipante", 0, 'C');
        $this->SetXY(506, $y);
        $this->MultiCell(80, 9, "Por Persona\nEvaluadora", 0, 'C');
        $this->SetXY(26, $y + 20);
        foreach (['Nombre de la Persona Evaluadora', 'Nombre de la Persona Participante', 'Institución', 'Puntos', 'Nivel', 'Puntos', 'Nivel'] as $i => $label) {
            $this->Cell($this->widths[$i], 15, $this->encoded($label), 0, 0, $i < 2 ? 'L' : 'C');
        }
        $this->SetDrawColor(180, 150, 88);
        $this->SetLineWidth(1.2);
        $this->Line(26, $y + 35, 586, $y + 35);
        $this->SetXY(26, $y + 35);
        $this->SetTextColor(35, 45, 60);
    }

    public function Footer()
    {
        $this->SetXY(26, 760);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0);
        $this->Cell(536, 12, $this->encoded('Página. '.$this->PageNo().' de {nb}'), 0, 0, 'R');
    }

    private function lines(string $text, float $width): array
    {
        $lines = []; $line = '';
        foreach (str_split($this->encoded(preg_replace('/\\s+/u', ' ', $text))) as $character) {
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

    private function reportRows(Collection $applications): Collection
    {
        return $applications->flatMap(function ($application) {
            $evaluations = $this->stage === 'local' ? $application->evaluations : $application->nationalReview?->evaluations;
            // The supplied acta is grouped by evaluator. Never duplicate the
            // participant's self-assessment using the evaluator's rubric score.
            $evaluations = $evaluations?->isNotEmpty() ? $evaluations : collect([null]);
            return $evaluations->map(fn ($evaluation) => [
                'evaluator_id' => $evaluation?->evaluator_id,
                'evaluator' => $evaluation?->evaluator?->name ?? '—',
                'participant' => $application->user?->name ?? '',
                'institution' => $application->user?->institution?->name ?? '—',
                'score' => $evaluation && $evaluation->status !== 'pending' && $evaluation->score !== null
                    ? rtrim(rtrim(number_format((float) $evaluation->score, 2, '.', ''), '0'), '.') : '—',
            ]);
        })->sortBy([['evaluator', 'asc'], ['participant', 'asc']])->values();
    }

    public function render(Collection $applications): string
    {
        $this->AddPage();
        $lastEvaluator = null;
        $rows = $this->reportRows($applications);
        foreach ($rows as $index => $row) {
            $this->SetFont('Arial', '', 8);
            $values = [$row['evaluator_id'] !== null && $lastEvaluator === $row['evaluator_id'] ? '' : $row['evaluator'],
                $row['participant'], $row['institution'], '—', '—', $row['score'], '—'];
            $lines = array_map(fn ($value, $width) => $this->lines($value, $width - 8), $values, $this->widths);
            $height = max(13.25, max(array_map('count', $lines)) * 9.25 + 4);
            if ($this->GetY() + $height > 735) {
                $this->AddPage();
                $this->SetFont('Arial', '', 8);
                $lines[0] = $this->lines($row['evaluator'], $this->widths[0] - 8);
                $height = max($height, count($lines[0]) * 9.25 + 4);
            }
            $x = 26; $y = $this->GetY();
            $this->SetTextColor(35, 45, 60);
            $this->SetDrawColor(215, 224, 237);
            $this->SetLineWidth(.4);
            $this->SetFillColor(...($index % 2 ? [238, 243, 250] : [255, 255, 255]));
            $this->Rect(26, $y, 560, $height, 'F');
            foreach ($lines as $column => $textLines) {
                $this->SetXY($x + 4, $y + 1.5);
                foreach ($textLines as $line) {
                    $this->SetX($x + 4);
                    $this->Cell($this->widths[$column] - 8, 9.25, $line, 0, 2, $column < 2 ? 'L' : 'C');
                }
                $x += $this->widths[$column];
            }
            $this->Line(26, $y + $height, 586, $y + $height);
            $this->SetXY(26, $y + $height);
            $lastEvaluator = $row['evaluator_id'];
        }
        if ($rows->isEmpty()) {
            $this->SetFont('Arial', '', 9);
            $this->Cell(560, 18, $this->encoded('No hay docentes aprobados en esta etapa.'), 0, 1, 'C');
        }
        $signatureY = $this->GetY() + 22;
        if ($signatureY + 360 > 735) {
            $this->signaturePage = true;
            $this->AddPage();
            $signatureY = 245;
        }
        $this->useTemplate($this->signatures, 0, $signatureY, 612, 360);
        return $this->Output('S');
    }
}
