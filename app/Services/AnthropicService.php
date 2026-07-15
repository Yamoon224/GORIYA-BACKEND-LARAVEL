<?php

namespace App\Services;

use App\Contracts\AiAnalysisServiceInterface;
use App\Services\Concerns\InteractsWithClaude;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Text as WordText;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Mirroir de backend/src/anthropic/anthropic.service.ts. Sans ANTHROPIC_API_KEY
 * configurée, chaque méthode retourne une valeur de repli statique (pas une
 * erreur) — c'est le comportement attendu en dev tant qu'aucune clé n'est
 * fournie, pas un chemin d'échec à corriger.
 */
class AnthropicService implements AiAnalysisServiceInterface
{
    use InteractsWithClaude;

    private const ANALYZE_CV_FALLBACK = [
        'score' => 70,
        'strengths' => [
            'Profil bien structuré et lisible',
            'Compétences techniques clairement listées',
            "Formation cohérente avec l'expérience",
        ],
        'improvements' => [
            'Ajouter des résultats chiffrés et mesurables',
            "Détailler l'impact des projets réalisés",
            'Optimiser le résumé professionnel en haut de CV',
        ],
        'recommendations' => [
            'Quantifiez vos accomplissements (ex: "augmentation de 30% des conversions")',
            'Ajoutez des mots-clés sectoriels pour les ATS',
            'Incluez un lien LinkedIn et GitHub si disponibles',
        ],
    ];

    private const SCORE_CANDIDATE_FALLBACK = [
        'overallScore' => 75,
        'criteria' => ['Competences' => 78, 'Experience' => 72, 'Communication' => 80],
        'feedback' => "Profil intéressant pour ce poste. Des améliorations sont possibles sur l'expérience pratique.",
    ];

    private const MATCH_CANDIDATE_FALLBACK = [
        'matchingScore' => 70,
        'matchReasons' => [
            'Profil cohérent avec les exigences du poste',
            "Compétences partiellement alignées avec l'offre",
            'Potentiel de développement identifié',
        ],
    ];

    private const SKILLS_TEST_FALLBACK = [
        'questions' => [
            ['question' => 'Décrivez une réalisation professionnelle dont vous êtes fier(ère) et son impact concret.', 'type' => 'COMPORTEMENTAL'],
            ['question' => 'Comment abordez-vous un problème technique que vous ne maîtrisez pas encore ?', 'type' => 'TECHNIQUE'],
            ['question' => 'Racontez une situation de désaccord en équipe et comment vous l\'avez résolue.', 'type' => 'COMPORTEMENTAL'],
        ],
    ];

    private const SOFT_SKILLS_FALLBACK = [
        'score' => 70,
        'strengths' => ['Communication claire', 'Capacité d\'adaptation'],
        'concerns' => [],
        'feedback' => 'Analyse indisponible actuellement — fournissez des notes d\'échange pour une évaluation détaillée.',
    ];

    public function __construct()
    {
        $this->initClaudeClient();
    }

    /*
    |--------------------------------------------------------------------------
    | TEXT EXTRACTION
    |--------------------------------------------------------------------------
    */
    public function extractTextFromBuffer(string $binary, string $mimeType, string $fileName): string
    {
        try {
            $name = strtolower($fileName);
            $isPdf = $mimeType === 'application/pdf' || str_ends_with($name, '.pdf');
            $isDocx = $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                || str_ends_with($name, '.docx');
            $isDoc = $mimeType === 'application/msword' || str_ends_with($name, '.doc');

            if ($isPdf) {
                return (new PdfParser())->parseContent($binary)->getText() ?? '';
            }

            if ($isDocx || $isDoc) {
                return $this->extractTextFromWord($binary);
            }

            return $binary;
        } catch (Throwable $e) {
            Log::error('Text extraction failed: '.$e->getMessage());

            return '';
        }
    }

    private function extractTextFromWord(string $binary): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'cv_').'.docx';
        file_put_contents($tmpPath, $binary);

        try {
            $phpWord = IOFactory::load($tmpPath);
            $text = '';

            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if ($element instanceof TextRun || $element instanceof WordText
                        || $element instanceof Title || $element instanceof ListItem) {
                        $text .= $element->getText()."\n";
                    }
                }
            }

            return $text;
        } finally {
            @unlink($tmpPath);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CV ANALYSIS
    |--------------------------------------------------------------------------
    */
    public function analyzeCV(string $binary, string $mimeType, string $fileName): array
    {
        $fallback = self::ANALYZE_CV_FALLBACK;

        if (! $this->hasClaudeClient()) {
            return $fallback;
        }

        try {
            $cvText = $this->extractTextFromBuffer($binary, $mimeType, $fileName);
            if (trim($cvText) === '') {
                Log::warning('Could not extract text from CV file, using fallback');

                return $fallback;
            }

            $prompt = <<<PROMPT
Vous êtes un expert RH spécialisé dans l'analyse de CV. Analysez ce CV et retournez une évaluation détaillée.

Contenu du CV :
---
{$this->truncateForClaude($cvText, 6000)}
---

Retournez UNIQUEMENT un objet JSON valide (sans markdown, sans texte avant ou après) avec exactement cette structure :
{
  "score": <entier entre 0 et 100>,
  "strengths": ["<point fort spécifique 1>", "<point fort spécifique 2>", "<point fort spécifique 3>"],
  "improvements": ["<amélioration concrète 1>", "<amélioration concrète 2>", "<amélioration concrète 3>"],
  "recommendations": ["<recommandation actionnable 1>", "<recommandation actionnable 2>", "<recommandation actionnable 3>"]
}

Critères d'évaluation du score :
- Informations de contact et présentation (10%)
- Expérience professionnelle : pertinence, détail et résultats chiffrés (35%)
- Compétences techniques et soft skills (30%)
- Formation et certifications (15%)
- Structure et lisibilité générale (10%)

{$this->localizedInstruction()} Minimum 3 éléments par tableau. Soyez spécifique et actionnable.
PROMPT;

            $text = $this->requestClaudeText($prompt, 1024);
            $parsed = $this->parseClaudeJson($text, $fallback);

            return [
                'score' => max(0, min(100, (int) round((float) ($parsed['score'] ?? 70)))),
                'strengths' => $this->ensureClaudeStringArray($parsed['strengths'] ?? null, $fallback['strengths']),
                'improvements' => $this->ensureClaudeStringArray($parsed['improvements'] ?? null, $fallback['improvements']),
                'recommendations' => $this->ensureClaudeStringArray($parsed['recommendations'] ?? null, $fallback['recommendations']),
            ];
        } catch (Throwable $e) {
            Log::error('CV analysis failed: '.$e->getMessage());

            return $fallback;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CANDIDATE SCORING
    |--------------------------------------------------------------------------
    */
    public function scoreCandidate(string $candidateName, string $candidateEmail, string $position): array
    {
        $fallback = self::SCORE_CANDIDATE_FALLBACK;

        if (! $this->hasClaudeClient()) {
            return $fallback;
        }

        try {
            $prompt = <<<PROMPT
Vous êtes un expert RH. Évaluez ce candidat pour le poste mentionné et retournez une analyse structurée.

Candidat : {$candidateName}
Email : {$candidateEmail}
Poste visé : {$position}

Retournez UNIQUEMENT un objet JSON valide (sans markdown) :
{
  "overallScore": <entier entre 0 et 100>,
  "criteria": {
    "Competences": <entier entre 0 et 100>,
    "Experience": <entier entre 0 et 100>,
    "Communication": <entier entre 0 et 100>
  },
  "feedback": "<texte de feedback général, 1-2 phrases>"
}

Basez votre évaluation sur les informations disponibles. {$this->localizedInstruction()}
PROMPT;

            $text = $this->requestClaudeText($prompt, 512);
            $parsed = $this->parseClaudeJson($text, $fallback);
            $criteria = is_array($parsed['criteria'] ?? null) ? $parsed['criteria'] : [];

            return [
                'overallScore' => max(0, min(100, (int) round((float) ($parsed['overallScore'] ?? 75)))),
                'criteria' => [
                    'Competences' => max(0, min(100, (int) round((float) ($criteria['Competences'] ?? 75)))),
                    'Experience' => max(0, min(100, (int) round((float) ($criteria['Experience'] ?? 75)))),
                    'Communication' => max(0, min(100, (int) round((float) ($criteria['Communication'] ?? 75)))),
                ],
                'feedback' => (string) ($parsed['feedback'] ?? $fallback['feedback']),
            ];
        } catch (Throwable $e) {
            Log::error('Scoring analysis failed: '.$e->getMessage());

            return $fallback;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | JOB MATCHING
    |--------------------------------------------------------------------------
    */
    public function matchCandidateToJob(array $candidate, array $job): array
    {
        $fallback = self::MATCH_CANDIDATE_FALLBACK;

        if (! $this->hasClaudeClient()) {
            return $fallback;
        }

        try {
            $descriptionBlock = ! empty($job['description'])
                ? "Description du poste :\n".$this->truncateForClaude($job['description'], 1000)
                : '';

            $prompt = <<<PROMPT
Évaluez la compatibilité entre ce candidat et cette offre d'emploi.

Candidat : {$candidate['name']} ({$candidate['email']})
Poste : {$job['title']}
Entreprise : {$job['company']}
{$descriptionBlock}

Retournez UNIQUEMENT un objet JSON valide (sans markdown) :
{
  "matchingScore": <entier entre 0 et 100>,
  "matchReasons": ["<raison spécifique 1>", "<raison spécifique 2>", "<raison spécifique 3>"]
}

{$this->localizedInstruction()} Minimum 3 raisons.
PROMPT;

            $text = $this->requestClaudeText($prompt, 512);
            $parsed = $this->parseClaudeJson($text, $fallback);

            return [
                'matchingScore' => max(0, min(100, (int) round((float) ($parsed['matchingScore'] ?? 70)))),
                'matchReasons' => $this->ensureClaudeStringArray($parsed['matchReasons'] ?? null, $fallback['matchReasons']),
            ];
        } catch (Throwable $e) {
            Log::error('Matching analysis failed: '.$e->getMessage());

            return $fallback;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SKILLS TEST GENERATION (Évaluation IA des Candidats — V2B)
    |--------------------------------------------------------------------------
    */
    public function generateSkillsTest(string $position): array
    {
        $fallback = self::SKILLS_TEST_FALLBACK;

        if (! $this->hasClaudeClient()) {
            return $fallback;
        }

        try {
            $prompt = <<<PROMPT
Vous êtes un expert RH. Générez un test d'évaluation pour le poste suivant : {$position}.

Retournez UNIQUEMENT un objet JSON valide (sans markdown) :
{
  "questions": [
    {"question": "<question technique ou comportementale>", "type": "TECHNIQUE ou COMPORTEMENTAL"}
  ]
}

5 à 8 questions, mélange de technique et comportemental pertinent pour ce poste. {$this->localizedInstruction()}
PROMPT;

            $text = $this->requestClaudeText($prompt, 1024);
            $parsed = $this->parseClaudeJson($text, $fallback);

            return ['questions' => $this->sanitizeSkillsTestQuestions($parsed['questions'] ?? null, $fallback['questions'])];
        } catch (Throwable $e) {
            Log::error('Skills test generation failed: '.$e->getMessage());

            return $fallback;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SOFT SKILLS ANALYSIS (Évaluation IA des Candidats — V2B)
    |--------------------------------------------------------------------------
    */
    public function analyzeSoftSkills(string $candidateName, string $exchangeNotes): array
    {
        $fallback = self::SOFT_SKILLS_FALLBACK;

        if (! $this->hasClaudeClient() || trim($exchangeNotes) === '') {
            return $fallback;
        }

        try {
            $prompt = <<<PROMPT
Vous êtes un psychologue du travail. Analysez les soft skills de ce candidat à partir des notes d'échange fournies.

Candidat : {$candidateName}
Notes d'échange :
---
{$this->truncateForClaude($exchangeNotes, 3000)}
---

Retournez UNIQUEMENT un objet JSON valide (sans markdown) :
{
  "score": <entier entre 0 et 100>,
  "strengths": ["<point fort 1>", "<point fort 2>"],
  "concerns": ["<point de vigilance 1>"],
  "feedback": "<synthèse, 1-2 phrases>"
}

Minimum 2 points forts. {$this->localizedInstruction()}
PROMPT;

            $text = $this->requestClaudeText($prompt, 768);
            $parsed = $this->parseClaudeJson($text, $fallback);

            return [
                'score' => max(0, min(100, (int) round((float) ($parsed['score'] ?? 70)))),
                'strengths' => $this->ensureClaudeStringArray($parsed['strengths'] ?? null, $fallback['strengths']),
                'concerns' => $this->ensureClaudeStringArray($parsed['concerns'] ?? null, $fallback['concerns']),
                'feedback' => (string) ($parsed['feedback'] ?? $fallback['feedback']),
            ];
        } catch (Throwable $e) {
            Log::error('Soft skills analysis failed: '.$e->getMessage());

            return $fallback;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CANDIDATE COMPARISON (Évaluation IA des Candidats — V2B)
    |--------------------------------------------------------------------------
    */
    public function compareCandidates(array $candidates): array
    {
        if (count($candidates) < 2) {
            return ['ranking' => []];
        }

        $fallback = [
            'ranking' => collect($candidates)
                ->sortByDesc('overallScore')
                ->values()
                ->map(fn (array $c, int $i) => ['name' => $c['name'], 'rank' => $i + 1, 'reason' => 'Classé par score global'])
                ->all(),
        ];

        if (! $this->hasClaudeClient()) {
            return $fallback;
        }

        try {
            $list = collect($candidates)
                ->map(fn (array $c) => "- {$c['name']} : score global {$c['overallScore']}/100")
                ->implode("\n");

            $prompt = <<<PROMPT
Vous êtes un expert RH. Classez ces candidats du meilleur au moins bon pour un même poste, à partir de leurs scores globaux.

{$list}

Retournez UNIQUEMENT un objet JSON valide (sans markdown) :
{
  "ranking": [
    {"name": "<nom exact fourni>", "rank": <entier, 1 = meilleur>, "reason": "<justification courte>"}
  ]
}

Classez tous les candidats fournis, sans en omettre. {$this->localizedInstruction()}
PROMPT;

            $text = $this->requestClaudeText($prompt, 1024);
            $parsed = $this->parseClaudeJson($text, $fallback);

            $ranking = $this->sanitizeRanking($parsed['ranking'] ?? null, $candidates);

            return ['ranking' => $ranking !== [] ? $ranking : $fallback['ranking']];
        } catch (Throwable $e) {
            Log::error('Candidate comparison failed: '.$e->getMessage());

            return $fallback;
        }
    }

    /**
     * @param  array<int, array{question: string, type: string}>  $fallback
     * @return array<int, array{question: string, type: string}>
     */
    private function sanitizeSkillsTestQuestions(mixed $value, array $fallback): array
    {
        if (! is_array($value) || count($value) === 0) {
            return $fallback;
        }

        $questions = [];
        foreach ($value as $item) {
            if (! is_array($item) || empty($item['question'])) {
                continue;
            }

            $type = strtoupper((string) ($item['type'] ?? 'TECHNIQUE'));
            $questions[] = [
                'question' => (string) $item['question'],
                'type' => in_array($type, ['TECHNIQUE', 'COMPORTEMENTAL'], true) ? $type : 'TECHNIQUE',
            ];
        }

        return $questions !== [] ? $questions : $fallback;
    }

    /**
     * @param  array<int, array{name: string, overallScore: int}>  $candidates
     * @return array<int, array{name: string, rank: int, reason: string}>
     */
    private function sanitizeRanking(mixed $value, array $candidates): array
    {
        if (! is_array($value)) {
            return [];
        }

        $validNames = array_column($candidates, 'name');
        $ranking = [];

        foreach ($value as $item) {
            if (! is_array($item) || empty($item['name']) || ! in_array($item['name'], $validNames, true)) {
                continue;
            }

            $ranking[] = [
                'name' => (string) $item['name'],
                'rank' => max(1, (int) ($item['rank'] ?? count($ranking) + 1)),
                'reason' => (string) ($item['reason'] ?? 'Classé par score global'),
            ];
        }

        return $ranking;
    }
}
