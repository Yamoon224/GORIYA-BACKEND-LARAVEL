<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use App\Contracts\AiAnalysisServiceInterface;
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

    private ?Client $client = null;

    private readonly string $model;

    public function __construct()
    {
        $apiKey = config('services.anthropic.key');
        $this->model = config('services.anthropic.model');

        if ($apiKey) {
            $this->client = new Client(apiKey: $apiKey);
            Log::info("AnthropicService initialized with model {$this->model}");
        } else {
            Log::warning('ANTHROPIC_API_KEY not set — AI analysis will use intelligent fallback values');
        }
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

        if (! $this->client) {
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
{$this->truncate($cvText, 6000)}
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

Répondez en français. Minimum 3 éléments par tableau. Soyez spécifique et actionnable.
PROMPT;

            $text = $this->requestText($prompt, 1024);
            $parsed = $this->parseJson($text, $fallback);

            return [
                'score' => max(0, min(100, (int) round((float) ($parsed['score'] ?? 70)))),
                'strengths' => $this->ensureStringArray($parsed['strengths'] ?? null, $fallback['strengths']),
                'improvements' => $this->ensureStringArray($parsed['improvements'] ?? null, $fallback['improvements']),
                'recommendations' => $this->ensureStringArray($parsed['recommendations'] ?? null, $fallback['recommendations']),
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

        if (! $this->client) {
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
  "feedback": "<texte de feedback général en français, 1-2 phrases>"
}

Basez votre évaluation sur les informations disponibles. Répondez en français.
PROMPT;

            $text = $this->requestText($prompt, 512);
            $parsed = $this->parseJson($text, $fallback);
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

        if (! $this->client) {
            return $fallback;
        }

        try {
            $descriptionBlock = ! empty($job['description'])
                ? "Description du poste :\n".$this->truncate($job['description'], 1000)
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

Répondez en français. Minimum 3 raisons.
PROMPT;

            $text = $this->requestText($prompt, 512);
            $parsed = $this->parseJson($text, $fallback);

            return [
                'matchingScore' => max(0, min(100, (int) round((float) ($parsed['matchingScore'] ?? 70)))),
                'matchReasons' => $this->ensureStringArray($parsed['matchReasons'] ?? null, $fallback['matchReasons']),
            ];
        } catch (Throwable $e) {
            Log::error('Matching analysis failed: '.$e->getMessage());

            return $fallback;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */
    private function requestText(string $prompt, int $maxTokens): string
    {
        $response = $this->client->messages->create(
            maxTokens: $maxTokens,
            messages: [['role' => 'user', 'content' => $prompt]],
            model: $this->model,
        );

        foreach ($response->content as $block) {
            if ($block instanceof TextBlock) {
                return $block->text;
            }
        }

        return '';
    }

    private function truncate(string $text, int $length): string
    {
        return mb_substr($text, 0, $length);
    }

    private function parseJson(string $text, array $fallback): array
    {
        try {
            $cleaned = trim(preg_replace('/```(?:json)?\s*|```/', '', $text));

            if (! preg_match('/\{[\s\S]*\}/', $cleaned, $matches)) {
                return $fallback;
            }

            $decoded = json_decode($matches[0], true);

            return is_array($decoded) ? $decoded : $fallback;
        } catch (Throwable $e) {
            Log::error('JSON parse failed for Claude response: '.mb_substr($text, 0, 200));

            return $fallback;
        }
    }

    /**
     * @param  array<int, string>  $fallback
     * @return array<int, string>
     */
    private function ensureStringArray(mixed $value, array $fallback): array
    {
        if (! is_array($value) || count($value) === 0) {
            return $fallback;
        }

        return array_values(array_filter(array_map('strval', $value), fn ($item) => $item !== ''));
    }
}
