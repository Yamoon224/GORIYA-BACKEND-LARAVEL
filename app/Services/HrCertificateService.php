<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Enums\HrDocumentTemplate;
use App\Enums\PayrollRunStatus;
use App\Models\Employee;
use App\Models\Payslip;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Rédaction des attestations RH (.docx) à partir de la fiche employé :
 * attestation de travail, attestation de salaire, certificat de travail.
 *
 * Le document sort prêt à signer : en-tête de l'entreprise, formule d'usage,
 * lieu, date et bloc de signature. Il reste à le signer et à y apposer le cachet.
 */
class HrCertificateService
{
    private const CONTRACT_WORDING = [
        'CDI' => 'd\'un contrat à durée indéterminée',
        'CDD' => 'd\'un contrat à durée déterminée',
        'STAGE' => 'd\'un stage',
        'ALTERNANCE' => 'd\'un contrat d\'alternance',
        'FREELANCE' => 'd\'un contrat de prestation de services',
        'TEMPS_PARTIEL' => 'd\'un contrat à temps partiel',
    ];

    /**
     * @param  array{signatoryName: string, signatoryTitle: string, city?: ?string, date: CarbonImmutable}  $options
     * @return string Chemin du fichier sur le disque privé
     */
    public function generate(Employee $employee, HrDocumentTemplate $template, array $options): string
    {
        $left = $this->hasLeft($employee, $options['date']);

        if ($template === HrDocumentTemplate::EMPLOYMENT_CERTIFICATE && ! $left) {
            abort(400, "Le certificat de travail se remet à la fin du contrat : passez d'abord l'employé en « Parti », ou renseignez la fin de son contrat.");
        }
        if ($template === HrDocumentTemplate::SALARY_CERTIFICATE && $employee->salary === null) {
            abort(400, "Renseignez le salaire de l'employé (contrat en vigueur) avant d'établir une attestation de salaire.");
        }

        // Sans échappement, un « & » dans un nom d'entreprise rend le .docx illisible.
        Settings::setOutputEscapingEnabled(true);
        $employee->loadMissing('company');
        $company = $employee->company;
        $companyName = $company?->name ?? "L'entreprise";

        $document = new PhpWord;
        $document->setDefaultFontName('Calibri');
        $document->setDefaultFontSize(11);
        $section = $document->addSection(['marginTop' => 1100, 'marginBottom' => 1100, 'marginLeft' => 1300, 'marginRight' => 1300]);
        $paragraph = ['alignment' => Jc::BOTH, 'spaceAfter' => 200, 'lineHeight' => 1.4];

        $section->addText($companyName, ['bold' => true, 'size' => 14, 'color' => '1D4ED8'], ['spaceAfter' => 0]);
        foreach (array_filter([$company?->headquarters ?: $company?->location, $company?->phone, $company?->email]) as $line) {
            $section->addText($line, ['size' => 9, 'color' => '666666'], ['spaceAfter' => 0]);
        }
        $section->addTextBreak(2);
        $section->addText(mb_strtoupper($template->title()), ['bold' => true, 'size' => 16], ['alignment' => Jc::CENTER, 'spaceAfter' => 120]);
        $section->addText("Réf. {$employee->matricule}", ['size' => 9, 'color' => '888888'], ['alignment' => Jc::CENTER, 'spaceAfter' => 480]);

        $e = $employee->gender === 'F' ? 'e' : ($employee->gender === 'M' ? '' : '(e)');
        $civility = $employee->gender === 'F' ? 'Madame' : ($employee->gender === 'M' ? 'Monsieur' : 'M./Mme');
        $person = "{$civility} {$employee->full_name}";
        $born = $employee->birth_date ? ", né{$e} le {$this->date($employee->birth_date)}" : '';
        $intro = "Je soussigné(e), {$options['signatoryName']}, {$options['signatoryTitle']} de {$companyName}, ";
        $hired = $this->date($employee->hire_date);
        $end = $this->date($employee->contract_end_date ?? $options['date']);
        $job = $employee->job_title;
        $contract = self::CONTRACT_WORDING[$employee->contract_type?->value] ?? "d'un contrat de travail";

        match ($template) {
            HrDocumentTemplate::WORK_CERTIFICATE => $section->addText(
                $left
                    ? "{$intro}atteste que {$person}{$born}, matricule {$employee->matricule}, a été employé{$e} au sein de notre entreprise du {$hired} au {$end}, en qualité de {$job}, dans le cadre {$contract}."
                    : "{$intro}atteste que {$person}{$born}, matricule {$employee->matricule}, est employé{$e} au sein de notre entreprise depuis le {$hired}, en qualité de {$job}, dans le cadre {$contract}.",
                [],
                $paragraph,
            ),
            HrDocumentTemplate::SALARY_CERTIFICATE => $this->salaryBody($section, $employee, $intro, $person, $e, $left, $paragraph),
            HrDocumentTemplate::EMPLOYMENT_CERTIFICATE => $this->employmentBody($section, $intro, $person, $born, $e, $hired, $end, $job, $employee, $paragraph),
        };

        $section->addText(
            $template === HrDocumentTemplate::EMPLOYMENT_CERTIFICATE
                ? 'Le présent certificat est délivré pour servir et valoir ce que de droit.'
                : "La présente attestation est délivrée à l'intéressé{$e}, à sa demande, pour servir et valoir ce que de droit.",
            [],
            $paragraph,
        );

        $city = $options['city'] ?? null ?: ($company?->headquarters ?: trim(explode(',', (string) $company?->location)[0] ?? ''));
        $section->addTextBreak(1);
        $section->addText(
            'Fait'.($city ? " à {$city}" : '').", le {$this->date($options['date'])}.",
            [],
            ['alignment' => Jc::END, 'spaceAfter' => 480],
        );
        $section->addText($options['signatoryName'], ['bold' => true], ['alignment' => Jc::END, 'spaceAfter' => 0]);
        $section->addText($options['signatoryTitle'], [], ['alignment' => Jc::END, 'spaceAfter' => 0]);
        $section->addText('(signature et cachet)', ['size' => 9, 'color' => '888888'], ['alignment' => Jc::END]);

        $section->addFooter()->addText(
            "Document établi avec Goriya à partir du dossier de l'employé — à signer et à revêtir du cachet de l'entreprise.",
            ['size' => 8, 'color' => '888888'],
            ['alignment' => Jc::CENTER],
        );

        $path = "hr-documents/{$employee->company_id}/".Str::uuid().'.docx';
        Storage::disk('local')->makeDirectory("hr-documents/{$employee->company_id}");
        IOFactory::createWriter($document, 'Word2007')->save(Storage::disk('local')->path($path));

        return $path;
    }

    /**
     * @param  \PhpOffice\PhpWord\Element\Section  $section
     * @param  array<string, mixed>  $paragraph
     */
    private function salaryBody($section, Employee $employee, string $intro, string $person, string $e, bool $left, array $paragraph): void
    {
        $salary = $this->amount($employee->salary);
        $section->addText(
            $left
                ? "{$intro}atteste que {$person}, matricule {$employee->matricule}, a été employé{$e} au sein de notre entreprise du {$this->date($employee->hire_date)} au {$this->date($employee->contract_end_date)}, en qualité de {$employee->job_title}, et percevait en dernier lieu un salaire brut mensuel de {$salary}."
                : "{$intro}atteste que {$person}, matricule {$employee->matricule}, employé{$e} au sein de notre entreprise depuis le {$this->date($employee->hire_date)} en qualité de {$employee->job_title}, perçoit un salaire brut mensuel de {$salary}.",
            [],
            $paragraph,
        );

        // Dernières rémunérations versées : bulletins des paies validées ou payées.
        $payslips = Payslip::query()
            ->where('employee_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', [PayrollRunStatus::VALIDATED->value, PayrollRunStatus::PAID->value]))
            ->with('run')
            ->get()
            ->sortByDesc(fn (Payslip $payslip) => $payslip->run->year * 100 + $payslip->run->month)
            ->take(3);

        if ($payslips->isEmpty()) {
            return;
        }

        $section->addText('Rémunérations des derniers mois :', [], ['spaceAfter' => 120]);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'D9D9D9', 'cellMargin' => 80]);
        $table->addRow();
        foreach (['Période', 'Salaire brut', 'Net versé'] as $heading) {
            $table->addCell(3000, ['bgColor' => 'F2F4F7'])->addText($heading, ['bold' => true, 'size' => 10]);
        }
        foreach ($payslips as $payslip) {
            $table->addRow();
            $table->addCell(3000)->addText(CarbonImmutable::create($payslip->run->year, $payslip->run->month, 1)->locale('fr')->isoFormat('MMMM YYYY'), ['size' => 10]);
            $table->addCell(3000)->addText($this->amount($payslip->gross), ['size' => 10], ['alignment' => Jc::END]);
            $table->addCell(3000)->addText($this->amount($payslip->net), ['size' => 10], ['alignment' => Jc::END]);
        }
        $section->addTextBreak(1);
    }

    /**
     * @param  \PhpOffice\PhpWord\Element\Section  $section
     * @param  array<string, mixed>  $paragraph
     */
    private function employmentBody($section, string $intro, string $person, string $born, string $e, string $hired, string $end, string $job, Employee $employee, array $paragraph): void
    {
        $section->addText(
            "{$intro}certifie que {$person}{$born}, matricule {$employee->matricule}, a été employé{$e} au sein de notre entreprise du {$hired} au {$end}, en qualité de {$job}.",
            [],
            $paragraph,
        );
        $pronoun = $employee->gender === 'F' ? 'Elle' : ($employee->gender === 'M' ? 'Il' : 'Il/Elle');
        $section->addText("{$pronoun} quitte l'entreprise libre de tout engagement.", [], $paragraph);
    }

    /** Employé parti, ou contrat arrivé à son terme. */
    private function hasLeft(Employee $employee, CarbonImmutable $today): bool
    {
        return $employee->status === EmployeeStatus::TERMINATED
            || ($employee->contract_end_date !== null && $employee->contract_end_date->toDateString() < $today->toDateString());
    }

    private function amount(?int $value): string
    {
        return number_format((int) $value, 0, ',', ' ').' FCFA';
    }

    private function date(DateTimeInterface|string|null $value): string
    {
        return $value ? CarbonImmutable::parse($value)->locale('fr')->isoFormat('D MMMM YYYY') : '';
    }
}
