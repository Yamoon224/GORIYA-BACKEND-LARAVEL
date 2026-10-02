<?php

namespace App\Services;

use App\Contracts\ChatAiServiceInterface;
use App\Services\Concerns\InteractsWithClaude;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Répond aux messages de GORIYA Chat en tenant compte de l'historique du
 * fil et d'un contexte utilisateur léger. Comme les autres services
 * Anthropic*, sans ANTHROPIC_API_KEY configurée chaque appel retourne une
 * valeur de repli statique (comportement attendu en dev).
 */
class AnthropicChatService implements ChatAiServiceInterface
{
    use InteractsWithClaude;

    /** Nombre de pièces jointes (les plus récentes) relues par le modèle à chaque tour. */
    private const MAX_FILES_IN_CONTEXT = 4;

    private const REPLY_FALLBACK = "Je suis actuellement en mode limité (aucune clé IA configurée), mais je reste à votre écoute. Pouvez-vous préciser votre question sur votre carrière, votre CV ou votre recherche d'emploi ?";

    public function __construct()
    {
        $this->initClaudeClient();
    }

    public function reply(array $history, array $context): string
    {
        if (! $this->hasClaudeClient()) {
            return self::REPLY_FALLBACK;
        }

        try {
            $system = $this->buildSystemPrompt($context);
            // Seules les pièces jointes les plus récentes sont renvoyées au
            // modèle à chaque tour : au-delà, le coût et la taille de la
            // requête grimpent pour des fichiers dont il a déjà parlé.
            $budget = self::MAX_FILES_IN_CONTEXT;
            $messages = [];
            foreach (array_reverse($history) as $message) {
                $blocks = [];
                foreach ($message['attachments'] ?? [] as $attachment) {
                    $block = $budget > 0 ? $this->attachmentBlock($attachment) : null;
                    if ($block) {
                        $budget--;
                        $blocks[] = $block;
                    } else {
                        $blocks[] = ['type' => 'text', 'text' => '[Fichier joint : '.($attachment['name'] ?? 'fichier').']'];
                    }
                }

                $content = trim((string) $message['content']);
                if ($blocks !== []) {
                    $blocks[] = ['type' => 'text', 'text' => $content !== '' ? $content : 'Voici mon fichier.'];
                }

                array_unshift($messages, [
                    'role' => $message['role'] === 'ASSISTANT' ? 'assistant' : 'user',
                    'content' => $blocks !== [] ? $blocks : $content,
                ]);
            }

            $text = trim($this->requestClaudeChat($messages, 1500, $system));

            return $text !== '' ? $text : self::REPLY_FALLBACK;
        } catch (Throwable $e) {
            Log::error('Chat reply failed: '.$e->getMessage());

            return self::REPLY_FALLBACK;
        }
    }

    /**
     * Bloc de contenu Claude pour une pièce jointe : PDF et images sont lus
     * nativement, les fichiers texte et Word sont transmis en texte.
     *
     * @param  array{name?: string, path?: string, mime?: string}  $attachment
     * @return array<string, mixed>|null
     */
    private function attachmentBlock(array $attachment): ?array
    {
        $disk = Storage::disk('local');
        $path = $attachment['path'] ?? '';
        if ($path === '' || ! $disk->exists($path)) {
            return null;
        }

        $name = $attachment['name'] ?? 'fichier';
        $mime = $attachment['mime'] ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return [
                'type' => 'document',
                'title' => $name,
                'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($disk->get($path))],
            ];
        }

        if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return [
                'type' => 'image',
                'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($disk->get($path))],
            ];
        }

        $text = $extension === 'docx' ? $this->docxText($disk->path($path)) : (string) $disk->get($path);
        $text = trim(mb_convert_encoding($text, 'UTF-8', 'UTF-8'));

        return $text === '' ? null : [
            'type' => 'text',
            'text' => "Contenu du fichier joint « {$name} » :\n---\n".$this->truncateForClaude($text, 20000)."\n---",
        ];
    }

    /** Texte brut d'un .docx : un paragraphe par ligne, balises retirées. */
    private function docxText(string $absolutePath): string
    {
        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return '';
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? '';
        $xml = preg_replace('/<w:tab\/>/', ' ', $xml) ?? '';

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * @param  array{name: string, lastJobOfferTitle?: string, lastPitchType?: string, skills?: array<int, string>}  $context
     */
    private function buildSystemPrompt(array $context): string
    {
        $facts = ["Prénom de l'utilisateur : {$context['name']}"];

        if (! empty($context['lastJobOfferTitle'])) {
            $facts[] = "Dernier poste auquel il/elle a postulé : {$context['lastJobOfferTitle']}";
        }
        if (! empty($context['lastPitchType'])) {
            $facts[] = "Type de pitch le plus récent créé : {$context['lastPitchType']}";
        }
        if (! empty($context['skills'])) {
            $facts[] = 'Compétences déclarées : '.implode(', ', $context['skills']);
        }
        if (! empty($context['cv'])) {
            $facts[] = "CV de l'utilisateur, déjà analysé sur la plateforme (vous y avez donc accès) :\n".$context['cv'];
        }

        $factsBlock = implode("\n- ", $facts);

        return <<<SYSTEM
Vous êtes GORIYA Chat, un coach carrière IA bienveillant, performant et inclusif, intégré à la plateforme GORIYA (emploi et développement professionnel en Afrique francophone).

Contexte connu sur l'utilisateur :
- {$factsBlock}

Répondez de façon claire, actionnable et encourageante, sans jargon. Restez concis (quelques phrases, pas d'essai). Quand l'utilisateur joint un fichier (CV, offre d'emploi, lettre, capture d'écran), lisez-le et appuyez votre réponse sur son contenu précis. Ne redemandez pas une information qui figure déjà dans le contexte ou dans un fichier joint.

Mise en forme : texte simple. Vous pouvez mettre en gras les éléments essentiels avec **deux astérisques**, avec parcimonie, et utiliser des listes à tirets ou numérotées. Pas de titres (#), pas de tableaux, pas d'italique. {$this->localizedInstruction()} Si la question sort du champ carrière/emploi/CV/formation, recentrez poliment la conversation sur ces sujets.
SYSTEM;
    }
}
