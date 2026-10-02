<?php

namespace App\Services;

use App\Contracts\PitchAiServiceInterface;
use App\Services\Concerns\InteractsWithClaude;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Génère et score les pitchs Goriya (texte ou script de pitch vidéo). Comme
 * les autres services Anthropic*, sans ANTHROPIC_API_KEY configurée chaque
 * appel retourne une valeur de repli statique (comportement attendu en dev).
 */
class AnthropicPitchService implements PitchAiServiceInterface
{
    use InteractsWithClaude;

    private const GENERATE_FALLBACK = <<<'TEXT'
Bonjour, je suis un professionnel motivé et déterminé à mettre mes compétences au service de votre organisation. Mon parcours m'a permis de développer une solide capacité d'adaptation et un réel goût pour les défis. Je suis convaincu que mon profil correspond à vos attentes et je serais ravi d'en discuter plus en détail avec vous.
TEXT;

    private const SCORE_FALLBACK = [
        'clarte' => 70,
        'impact' => 65,
        'persuasion' => 65,
        'feedback' => "Pitch correct mais générique — ajoutez des exemples concrets et des résultats chiffrés pour renforcer l'impact.",
    ];

    private const TYPE_LABELS = [
        'EMPLOI' => 'une candidature à un poste',
        'CONCOURS' => 'un concours ou une bourse',
        'APPEL_PROJET' => 'un appel à projets',
        'STARTUP' => 'un pitch de startup devant des investisseurs',
    ];

    /** Déroulé du pitch attendu selon son type. */
    private const TYPE_STRUCTURES = [
        'EMPLOI' => "accroche personnelle, parcours et compétences les plus pertinents pour le poste, une réalisation concrète tirée du profil, ce que le candidat apportera, appel à l'action.",
        'CONCOURS' => "accroche, parcours et motivation, ce qui distingue la candidature, projet porté grâce au concours ou à la bourse, appel à l'action.",
        'APPEL_PROJET' => "accroche, besoin auquel répond le projet, solution proposée, public bénéficiaire, capacité du porteur à le mener, appel à l'action.",
        'STARTUP' => "accroche, problème précis et pour qui, solution et ce que le produit fait concrètement, cible et marché, ce qui le différencie, légitimité du fondateur, demande adressée aux investisseurs.",
    ];

    /**
     * Fiche produit de Goriya, injectée quand le pitch porte sur la plateforme
     * elle-même. À tenir à jour avec l'offre réelle.
     */
    private const GORIYA_FACTS = <<<'TEXT'
- Goriya (goriya.net) est une plateforme d'emploi et de carrière propulsée par l'intelligence artificielle, conçue en Côte d'Ivoire pour les talents et les entreprises d'Afrique. Signature : « Boost ta carrière ».
- Pour les candidats : recherche d'offres d'emploi et candidature en ligne ; création de CV ; analyse de CV par IA avec un score sur 100, points forts, points faibles et recommandations ; génération de lettres de motivation et de documents professionnels ; simulation d'entretien ; préparation de pitch ; formations en vidéo ; mise en réseau (Goriya Connect), visioconférence (Goriya Meet) et messagerie (Goriya Chat).
- Pour les entreprises : un espace en ligne (SaaS) pour publier des offres, recevoir et trier les candidatures, et gérer des services RH.
- Problème adressé : des candidats qui postulent avec des dossiers mal préparés et sans accompagnement, et des recruteurs qui peinent à identifier les bons profils.
- Modèle : accès gratuit limité, puis abonnements donnant accès aux fonctionnalités avancées.
TEXT;

    public function __construct()
    {
        $this->initClaudeClient();
    }

    /**
     * Ce que l'on sait du candidat (compte + profil extrait de son CV). Les
     * lignes vides sont omises pour ne pas inviter le modèle à les combler.
     *
     * @param  array<string, mixed>  $profile
     */
    private function profileBlock(array $profile): string
    {
        $experiences = collect($profile['experiences'] ?? [])
            ->take(4)
            ->map(function (array $e) {
                $head = implode(' chez ', array_filter([$e['position'] ?? '', $e['company'] ?? '']));
                $missions = implode(' ; ', array_slice($e['missions'] ?? [], 0, 3));

                return trim($head.(! empty($e['date']) ? " ({$e['date']})" : '').($missions !== '' ? " : {$missions}" : ''));
            })
            ->filter()
            ->implode("\n  - ");

        $lines = array_filter([
            'Nom : '.($profile['name'] ?? ''),
            ! empty($profile['title']) ? "Titre : {$profile['title']}" : '',
            ! empty($profile['location']) ? "Localisation : {$profile['location']}" : '',
            ! empty($profile['bio']) ? 'Présentation : '.$this->truncateForClaude($profile['bio'], 600) : '',
            ! empty($profile['skills']) ? 'Compétences : '.implode(', ', array_slice($profile['skills'], 0, 12)) : '',
            $experiences !== '' ? "Expériences :\n  - {$experiences}" : '',
            ! empty($profile['education']) ? "Formation : {$profile['education']}" : '',
        ]);

        return "Auteur du pitch :\n".$this->truncateForClaude(implode("\n", $lines), 2500);
    }

    public function generate(array $profile, ?array $job, string $type, ?string $idea = null): string
    {
        if (! $this->hasClaudeClient()) {
            return self::GENERATE_FALLBACK;
        }

        try {
            $context = self::TYPE_LABELS[$type] ?? 'une candidature professionnelle';
            $jobBlock = $job
                ? "Poste visé : {$job['title']} chez {$job['company']}".(! empty($job['description']) ? "\nDescription : ".$this->truncateForClaude($job['description'], 800) : '')
                : '';
            $ideaBlock = ! empty(trim((string) $idea))
                ? "Idée / sujet du pitch (à respecter fidèlement) : ".$this->truncateForClaude($idea, 1200)
                : '';
            $profileBlock = $this->profileBlock($profile);
            // Sans cette fiche, le modèle ne connaît pas Goriya et lui invente
            // une activité (« gestion des opérations des entreprises »…).
            $goriyaBlock = stripos($idea.' '.($job['company'] ?? '').' '.($job['description'] ?? ''), 'goriya') !== false
                ? "Faits établis sur Goriya (seule source autorisée pour décrire le projet) :\n".self::GORIYA_FACTS
                : '';
            $structure = self::TYPE_STRUCTURES[$type] ?? self::TYPE_STRUCTURES['EMPLOI'];

            $prompt = <<<PROMPT
Vous êtes un coach en communication professionnelle. Rédigez un pitch oral percutant d'environ 60 secondes (150 à 190 mots) pour {$context}, à la première personne, prêt à être lu à voix haute (donc sans markdown, sans puces, juste un texte fluide).

{$profileBlock}
{$jobBlock}
{$ideaBlock}
{$goriyaBlock}

Structure attendue : {$structure}

Règles impératives :
- Appuyez-vous uniquement sur les informations fournies ci-dessus. N'inventez aucun chiffre, pourcentage, client, résultat, récompense ni fonctionnalité.
- Dites concrètement ce que fait le projet ou la personne, pour qui, et en quoi c'est différent : pas de formules creuses (« révolutionner », « solution innovante », « façonner l'avenir ») sans contenu précis derrière.
- Si une information utile manque (chiffres de traction, montant recherché...), n'en fabriquez pas : restez factuel sur ce qui est connu.
- Terminez par un appel à l'action clair et direct, adapté au contexte.

Répondez uniquement avec le texte du pitch, sans guillemets ni commentaire. {$this->localizedInstruction()}
PROMPT;

            $text = trim($this->requestClaudeText($prompt, 800));

            return $text !== '' ? $text : self::GENERATE_FALLBACK;
        } catch (Throwable $e) {
            Log::error('Pitch generation failed: '.$e->getMessage());

            return self::GENERATE_FALLBACK;
        }
    }

    public function score(string $content): array
    {
        $fallback = self::SCORE_FALLBACK;

        if (! $this->hasClaudeClient()) {
            return $fallback;
        }

        try {
            $prompt = <<<PROMPT
Vous êtes un coach en prise de parole. Évaluez ce pitch professionnel :
---
{$this->truncateForClaude($content, 2000)}
---

Retournez UNIQUEMENT un objet JSON valide (sans markdown) :
{
  "clarte": <entier entre 0 et 100>,
  "impact": <entier entre 0 et 100>,
  "persuasion": <entier entre 0 et 100>,
  "feedback": "<conseil concret d'amélioration, 1-2 phrases>"
}

{$this->localizedInstruction()}
PROMPT;

            $text = $this->requestClaudeText($prompt, 512);
            $parsed = $this->parseClaudeJson($text, $fallback);

            return [
                'clarte' => max(0, min(100, (int) round((float) ($parsed['clarte'] ?? 70)))),
                'impact' => max(0, min(100, (int) round((float) ($parsed['impact'] ?? 65)))),
                'persuasion' => max(0, min(100, (int) round((float) ($parsed['persuasion'] ?? 65)))),
                'feedback' => (string) ($parsed['feedback'] ?? $fallback['feedback']),
            ];
        } catch (Throwable $e) {
            Log::error('Pitch scoring failed: '.$e->getMessage());

            return $fallback;
        }
    }
}
