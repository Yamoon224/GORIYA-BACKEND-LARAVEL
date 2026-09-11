<?php

namespace App\Services;

use App\Enums\ContractKind;
use App\Enums\JobType;
use App\Models\EmployeeContract;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Projet de contrat (.docx) rédigé à partir des informations saisies :
 * parties, poste, durée, période d'essai, rémunération, durée du travail,
 * congés. C'est un point de départ à faire relire, pas un acte juridique —
 * le document le dit en pied de page.
 *
 * Fichier temporaire sur le disque privé, supprimé après envoi par le contrôleur.
 */
class EmployeeContractDocumentService
{
    private const TITLES = [
        'CDI' => 'Contrat de travail à durée indéterminée',
        'CDD' => 'Contrat de travail à durée déterminée',
        'STAGE' => 'Convention de stage',
        'ALTERNANCE' => "Contrat d'alternance",
        'FREELANCE' => 'Contrat de prestation de services',
        'TEMPS_PARTIEL' => 'Contrat de travail à temps partiel',
    ];

    /** @return string Chemin absolu du fichier généré */
    public function generate(EmployeeContract $contract): string
    {
        // Sans échappement, un « & » dans un nom d'entreprise rend le .docx illisible.
        Settings::setOutputEscapingEnabled(true);

        $contract->loadMissing(['employee.company', 'parent']);
        $employee = $contract->employee;
        $company = $employee->company;

        $document = new PhpWord;
        $document->setDefaultFontName('Calibri');
        $document->setDefaultFontSize(11);
        $section = $document->addSection();
        $paragraph = ['alignment' => Jc::BOTH, 'spaceAfter' => 120];

        $isAmendment = $contract->kind === ContractKind::AMENDMENT;
        $title = $isAmendment
            ? 'Avenant au contrat '.($contract->parent?->reference ?? '')
            : (self::TITLES[$contract->type?->value] ?? 'Contrat de travail');

        $section->addText(mb_strtoupper($title), ['bold' => true, 'size' => 16, 'color' => '1D4ED8'], ['alignment' => Jc::CENTER]);
        $section->addText("Référence {$contract->reference}", ['size' => 9, 'color' => '666666'], ['alignment' => Jc::CENTER, 'spaceAfter' => 360]);

        $section->addText('Entre les soussignés :', ['bold' => true], $paragraph);
        $address = $company?->headquarters ?: $company?->location;
        $section->addText(
            ($company?->name ?? "L'entreprise").($address ? ", dont le siège est situé à {$address}" : '').", ci-après « l'employeur »,",
            [],
            $paragraph,
        );
        $section->addText('et', [], $paragraph);
        $born = $employee->gender === 'F' ? 'née' : ($employee->gender === 'M' ? 'né' : 'né(e)');
        $section->addText(
            $employee->full_name
                .($employee->birth_date ? ", {$born} le {$this->date($employee->birth_date)}" : '')
                .($employee->address ? ", demeurant à {$employee->address}" : '')
                .', ci-après « le salarié ».',
            [],
            $paragraph,
        );
        $section->addText('Il a été convenu ce qui suit.', [], array_merge($paragraph, ['spaceAfter' => 240]));

        $number = 0;
        $article = function (string $heading, string $body) use ($section, $paragraph, &$number) {
            $number++;
            $section->addText("Article {$number} — {$heading}", ['bold' => true], ['spaceBefore' => 120, 'spaceAfter' => 60]);
            $section->addText($body, [], $paragraph);
        };

        if ($isAmendment) {
            $article('Objet', "Le présent avenant modifie le contrat {$contract->parent?->reference} à compter du {$this->date($contract->start_date)}. Les clauses qui ne sont pas modifiées ci-dessous demeurent inchangées.");
        }

        $article('Engagement', "Le salarié est engagé en qualité de {$contract->job_title} à compter du {$this->date($contract->start_date)}.");

        $fixedTerm = in_array($contract->type, EmployeeContractService::FIXED_TERM_TYPES, true);
        $article('Durée', $contract->end_date
            ? "Le présent contrat est conclu pour une durée déterminée, du {$this->date($contract->start_date)} au {$this->date($contract->end_date)} inclus."
            : ($fixedTerm ? 'La date de fin du contrat reste à préciser.' : 'Le présent contrat est conclu pour une durée indéterminée.'));

        if ($contract->trial_end_date) {
            $article("Période d'essai", "Le contrat est assorti d'une période d'essai prenant fin le {$this->date($contract->trial_end_date)}, durant laquelle chacune des parties peut y mettre fin dans les conditions prévues par la législation en vigueur.");
        }

        if ($contract->salary !== null) {
            $salary = number_format($contract->salary, 0, ',', ' ');
            $article('Rémunération', "En contrepartie de son travail, le salarié percevra une rémunération brute mensuelle de {$salary} FCFA.");
        }

        if ($contract->weekly_hours) {
            $article('Durée du travail', "La durée hebdomadaire de travail est fixée à {$contract->weekly_hours} heures.");
        }

        if ($contract->type !== JobType::FREELANCE) {
            $article('Congés payés', "Le salarié bénéficie de {$employee->annual_leave_days} jours ouvrables de congés payés par an, conformément à la législation en vigueur.");
        }

        $article('Dispositions générales', 'Les parties se conforment au Code du travail et, le cas échéant, à la convention collective applicable à l’entreprise.');

        $city = $company?->headquarters ?: trim(explode(',', (string) $company?->location)[0] ?? '');
        $section->addText(
            'Fait en deux exemplaires originaux'.($city ? ", à {$city}" : '').', le ____________________.',
            [],
            ['spaceBefore' => 360, 'spaceAfter' => 240],
        );

        $table = $section->addTable();
        $table->addRow();
        $table->addCell(4800)->addText("Pour l'employeur", ['bold' => true]);
        $table->addCell(4800)->addText('Le salarié', ['bold' => true]);
        $table->addRow(1200);
        $table->addCell(4800)->addText('(signature et cachet)', ['size' => 9, 'color' => '666666']);
        $table->addCell(4800)->addText('(précédée de la mention « lu et approuvé »)', ['size' => 9, 'color' => '666666']);

        $section->addFooter()->addText(
            'Projet généré par Goriya à partir des informations saisies — à faire relire avant signature.',
            ['size' => 8, 'color' => '888888'],
            ['alignment' => Jc::CENTER],
        );

        $relativePath = 'employee-contracts/drafts/'.Str::uuid().'.docx';
        Storage::disk('local')->makeDirectory('employee-contracts/drafts');
        $absolutePath = Storage::disk('local')->path($relativePath);
        IOFactory::createWriter($document, 'Word2007')->save($absolutePath);

        return $absolutePath;
    }

    private function date(DateTimeInterface|string|null $value): string
    {
        return $value ? CarbonImmutable::parse($value)->locale('fr')->isoFormat('D MMMM YYYY') : '';
    }
}
