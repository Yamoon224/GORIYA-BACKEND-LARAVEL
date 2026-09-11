<?php

namespace App\Services;

use App\Models\Payslip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Bulletin de paie (.docx) : identité, période, rubriques (gains, cotisations,
 * impôt, retenues) et totaux. Fichier temporaire sur le disque privé, supprimé
 * après envoi par le contrôleur.
 */
class PayslipDocumentService
{
    /** @return string Chemin absolu du fichier généré */
    public function generate(Payslip $payslip): string
    {
        Settings::setOutputEscapingEnabled(true);

        $payslip->loadMissing('run');
        $run = $payslip->run;
        $company = \App\Models\Company::find($payslip->company_id);
        $employee = $payslip->employee_snapshot;
        $period = CarbonImmutable::create($run->year, $run->month, 1)->locale('fr');

        $document = new PhpWord;
        $document->setDefaultFontName('Calibri');
        $document->setDefaultFontSize(10);
        $section = $document->addSection(['marginLeft' => 900, 'marginRight' => 900, 'marginTop' => 900, 'marginBottom' => 900]);

        $section->addText(mb_strtoupper('Bulletin de paie'), ['bold' => true, 'size' => 16, 'color' => '1D4ED8']);
        $section->addText('Période : '.$period->isoFormat('MMMM YYYY').' — du '.$period->isoFormat('D MMMM').' au '.$period->endOfMonth()->isoFormat('D MMMM YYYY'), ['size' => 10, 'color' => '444444'], ['spaceAfter' => 200]);

        $header = $section->addTable(['borderSize' => 6, 'borderColor' => 'DDDDDD', 'cellMargin' => 80]);
        $header->addRow();
        $left = $header->addCell(5000);
        $left->addText('Employeur', ['bold' => true, 'color' => '666666', 'size' => 8]);
        $left->addText($company?->name ?? '', ['bold' => true]);
        $left->addText((string) ($company?->headquarters ?: $company?->location));
        $right = $header->addCell(5000);
        $right->addText('Salarié', ['bold' => true, 'color' => '666666', 'size' => 8]);
        $right->addText($employee['fullName'] ?? '', ['bold' => true]);
        $right->addText('Matricule '.($employee['matricule'] ?? '').' · '.($employee['jobTitle'] ?? ''));
        $right->addText(trim(($employee['department'] ?? '').' · Contrat '.($employee['contractType'] ?? '').($employee['contractReference'] ? ' ('.$employee['contractReference'].')' : ''), ' ·'));
        if (! empty($employee['hireDate'])) {
            $right->addText('Entrée le '.CarbonImmutable::parse($employee['hireDate'])->locale('fr')->isoFormat('D MMMM YYYY'));
        }

        $section->addText(
            "Jours ouvrés du mois : {$payslip->business_days} · jours payés : {$payslip->worked_days}".($payslip->unpaid_leave_days ? " · absences non rémunérées : {$payslip->unpaid_leave_days} j" : ''),
            ['size' => 9, 'color' => '444444'],
            ['spaceBefore' => 160, 'spaceAfter' => 160],
        );

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'DDDDDD', 'cellMargin' => 60]);
        $widths = [3600, 1400, 1100, 1300, 1300, 1300];
        $table->addRow();
        foreach (['Rubrique', 'Base', 'Taux', 'Gains', 'Retenues', 'Part patronale'] as $i => $title) {
            $table->addCell($widths[$i], ['bgColor' => 'EEF3FF'])->addText($title, ['bold' => true, 'size' => 9], ['alignment' => $i === 0 ? Jc::START : Jc::END]);
        }

        foreach ($payslip->lines as $line) {
            $isEarning = $line['section'] === 'EARNING';
            $rate = trim(implode(' / ', array_filter([
                $line['employeeRate'] !== null ? $this->percent($line['employeeRate']) : null,
                $line['employerRate'] !== null ? $this->percent($line['employerRate']) : null,
            ])));
            $cells = [
                $line['label'],
                $line['base'] !== null ? $this->money($line['base']) : '',
                $rate,
                $isEarning ? $this->money($line['employeeAmount']) : '',
                ! $isEarning && $line['employeeAmount'] ? $this->money($line['employeeAmount']) : '',
                $line['employerAmount'] ? $this->money($line['employerAmount']) : '',
            ];
            $table->addRow();
            foreach ($cells as $i => $value) {
                $table->addCell($widths[$i])->addText($value, ['size' => 9], ['alignment' => $i === 0 ? Jc::START : Jc::END]);
            }
        }

        $section->addTextBreak();
        $totals = $section->addTable(['borderSize' => 6, 'borderColor' => 'DDDDDD', 'cellMargin' => 80]);
        $rows = [
            ['Salaire brut', $payslip->gross, false],
            ['Cotisations salariales', $payslip->employee_contributions, false],
            ['Impôt sur salaire', $payslip->tax, false],
            ['Avances et autres retenues', $payslip->advances + $payslip->other_deductions, false],
            ['NET À PAYER', $payslip->net, true],
            ['Cotisations patronales', $payslip->employer_contributions, false],
            ['Coût total employeur', $payslip->employer_cost, false],
        ];
        foreach ($rows as [$label, $amount, $strong]) {
            $totals->addRow();
            $style = $strong ? ['bold' => true, 'size' => 12, 'color' => '1D4ED8'] : ['size' => 10];
            $totals->addCell(6000, $strong ? ['bgColor' => 'EEF3FF'] : [])->addText($label, $style);
            $totals->addCell(4000, $strong ? ['bgColor' => 'EEF3FF'] : [])->addText($this->money($amount).' FCFA', $style, ['alignment' => Jc::END]);
        }

        if ($run->payment_date) {
            $section->addText('Payé le '.CarbonImmutable::parse($run->payment_date)->locale('fr')->isoFormat('D MMMM YYYY').($run->payment_reference ? " — réf. {$run->payment_reference}" : ''), ['size' => 9], ['spaceBefore' => 160]);
        }

        $section->addFooter()->addText(
            "Bulletin généré par Goriya. Cotisations et impôt calculés d'après les paramètres de paie de l'employeur. À conserver sans limitation de durée.",
            ['size' => 7, 'color' => '888888'],
            ['alignment' => Jc::CENTER],
        );

        $relativePath = 'payslips/tmp/'.Str::uuid().'.docx';
        Storage::disk('local')->makeDirectory('payslips/tmp');
        $absolutePath = Storage::disk('local')->path($relativePath);
        IOFactory::createWriter($document, 'Word2007')->save($absolutePath);

        return $absolutePath;
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, ',', ' ');
    }

    private function percent(float|int $rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, ',', ''), '0'), ',').' %';
    }
}
