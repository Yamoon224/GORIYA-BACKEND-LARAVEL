<?php

namespace App\Services;

use App\Models\CandidateAssessment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Rapport d'évaluation détaillé téléchargeable — seul point de l'Évaluation
 * IA des Candidats où un rendu binaire est produit côté serveur (le reste
 * n'est que des scores/JSON consommés par le tableau de bord entreprise).
 * Réutilise phpoffice/phpword, déjà présent pour l'extraction de texte des
 * CV (voir AnthropicService::extractTextFromWord).
 *
 * Stocké sur le disque `local` (privé), pas `public` : ce rapport contient
 * des données RH sensibles (scores, analyse soft skills) — contrairement au
 * CV/logo/pitch vidéo, il ne doit pas être accessible via une URL devinable
 * sans passer par CandidateAssessmentsController::downloadReport(), qui
 * revérifie l'appartenance à l'entreprise à chaque téléchargement.
 */
class CandidateAssessmentReportService
{
    /**
     * @return string  chemin relatif sur le disque `local` (jamais une URL publique)
     */
    public function generate(CandidateAssessment $assessment): string
    {
        $assessment->loadMissing('candidature.jobOffer.company');
        $candidature = $assessment->candidature;

        $document = new PhpWord;
        $section = $document->addSection();

        $section->addTitle("Rapport d'évaluation — {$candidature->candidate_name}", 1);
        $section->addText('Poste : '.($candidature->jobOffer?->title ?? 'N/A'));
        $section->addText('Entreprise : '.($candidature->jobOffer?->company?->name ?? 'N/A'));
        $section->addTextBreak();

        $section->addTitle('Scores', 2);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);

        $this->addScoreRow($table, 'Score global', $assessment->overall_score);
        $this->addScoreRow($table, 'Compétences techniques', $assessment->technical_score);
        $this->addScoreRow($table, 'Adéquation culturelle', $assessment->cultural_fit_score);
        $this->addScoreRow($table, 'Soft skills', $assessment->soft_skills_score);

        $section->addTextBreak();
        $section->addTitle('Analyse des soft skills', 2);
        $section->addText($assessment->soft_skills_feedback ?: 'Non disponible.');

        $section->addTextBreak();
        $section->addTitle('Test de compétences proposé', 2);
        foreach ($assessment->skills_test ?? [] as $index => $item) {
            $section->addText(($index + 1).". [{$item['type']}] {$item['question']}");
        }

        $filename = Str::uuid().'.docx';
        $relativePath = "candidate-assessments/{$filename}";
        Storage::disk('local')->makeDirectory('candidate-assessments');
        $absolutePath = Storage::disk('local')->path($relativePath);

        IOFactory::createWriter($document, 'Word2007')->save($absolutePath);

        return $relativePath;
    }

    private function addScoreRow(Table $table, string $label, ?int $score): void
    {
        $table->addRow();
        $table->addCell(6000)->addText($label);
        $table->addCell(2000)->addText(
            $score !== null ? "{$score}/100" : 'N/A',
            null,
            ['alignment' => Jc::CENTER],
        );
    }
}
