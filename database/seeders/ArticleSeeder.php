<?php

namespace Database\Seeders;

use App\Enums\ArticleStatus;
use App\Models\Article;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Articles de lancement du blog Goriya. Idempotent (updateOrCreate par
 * slug) — safe à rejouer en prod sans dupliquer, y compris pour les 2
 * articles déjà créés manuellement lors du développement.
 */
class ArticleSeeder extends Seeder
{
    public function run(): void
    {
        $articles = [
            [
                'slug' => 'optimiser-cv-avec-ia',
                'title' => "Comment optimiser ton CV avec l'IA",
                'excerpt' => "Découvre comment l'intelligence artificielle de Goriya analyse ton CV et te donne des conseils concrets pour décrocher plus d'entretiens.",
                'content' => <<<'MD'
L'intelligence artificielle change la façon dont les recruteurs trient les candidatures. Sur Goriya, notre IA analyse ton CV en quelques secondes et t'indique précisément ce qui fonctionne et ce qui doit être amélioré.

Voici 3 conseils pour un CV qui se démarque :

1. Mets en avant des résultats chiffrés plutôt que des tâches génériques.
2. Adapte ton CV à chaque offre en reprenant les mots-clés de l'annonce.
3. Reste concis : une à deux pages maximum.

Dépose ton CV sur Goriya pour obtenir ton score d'efficacité gratuitement.
MD,
                'daysAgo' => 0,
            ],
            [
                'slug' => 'reussir-entretien-embauche',
                'title' => "Réussir son entretien d'embauche : nos conseils",
                'excerpt' => "Nos meilleurs conseils pour aborder sereinement ton prochain entretien, de la préparation aux questions les plus fréquentes.",
                'content' => <<<'MD'
Un entretien réussi se prépare. Avant le jour J, entraîne-toi avec le chatbot IA de Goriya ou réserve une simulation avec un professionnel RH.

Pendant l'entretien, reste toi-même, prépare des exemples concrets de tes réalisations, et n'hésite pas à poser des questions sur l'entreprise : cela montre ton intérêt réel pour le poste.
MD,
                'daysAgo' => 3,
            ],
            [
                'slug' => 'lettre-motivation-qui-marque',
                'title' => 'Écrire une lettre de motivation qui marque vraiment',
                'excerpt' => "Une bonne lettre de motivation ne résume pas ton CV : elle raconte pourquoi toi, précisément, pour ce poste-là.",
                'content' => <<<'MD'
La lettre de motivation reste un passage obligé pour beaucoup d'offres, mais elle est trop souvent bâclée.

Trois règles simples :

1. Personnalise-la pour chaque entreprise — cite un projet, une valeur ou une actualité qui te parle vraiment.
2. Explique ce que tu apportes, pas seulement ce que tu attends du poste.
3. Reste sur une page, avec un ton direct et naturel.

Sur Goriya, tu peux uploader ta lettre de motivation en même temps que ton CV pour une analyse IA complète.
MD,
                'daysAgo' => 7,
            ],
            [
                'slug' => 'choisir-entreprise-qui-te-correspond',
                'title' => 'Comment choisir une entreprise qui te correspond vraiment',
                'excerpt' => "Un poste qui coche toutes les cases techniques mais dans une entreprise qui ne te correspond pas, ça ne dure jamais longtemps. Voici comment enquêter avant de postuler.",
                'content' => <<<'MD'
Avant d'envoyer ta candidature, prends 10 minutes pour explorer le profil de l'entreprise sur Goriya : secteur, taille, valeurs, ambiance de travail.

Quelques signaux à vérifier :

- La cohérence entre les valeurs affichées et les offres publiées (missions claires, salaire renseigné).
- La taille de l'équipe par rapport au poste proposé.
- La présence ou non d'avis et de retours d'anciens candidats.

Postuler en connaissance de cause augmente tes chances de rester motivé une fois le poste décroché.
MD,
                'daysAgo' => 12,
            ],
            [
                'slug' => 'stage-vs-premier-emploi',
                'title' => 'Stage, alternance ou premier emploi : comment faire le bon choix',
                'excerpt' => "Chaque format a ses avantages selon ton année d'études, ta situation et tes objectifs de carrière. On fait le point.",
                'content' => <<<'MD'
Stage, alternance, CDD ou CDI direct : le bon choix dépend surtout de ton objectif à court terme.

Le stage reste idéal pour découvrir un secteur sans engagement long. L'alternance permet de financer ses études tout en construisant une vraie expérience professionnelle. Un premier emploi en CDI convient si tu es prêt(e) à t'investir pleinement dans une équipe.

Sur Goriya, filtre les offres par type de contrat directement depuis la page Explorer les offres pour cibler ce qui correspond à ta situation.
MD,
                'daysAgo' => 18,
            ],
            [
                'slug' => 'erreurs-recherche-emploi-a-eviter',
                'title' => "5 erreurs à éviter dans ta recherche d'emploi",
                'excerpt' => "Postuler en masse sans personnaliser, ignorer son réseau, négliger sa présence en ligne... voici les pièges les plus fréquents.",
                'content' => <<<'MD'
1. Postuler au même CV partout, sans l'adapter à l'offre.
2. Ignorer les offres qui demandent une lettre de motivation en pensant qu'elle ne sera pas lue.
3. Ne pas relancer après une candidature ou un entretien.
4. Négliger son profil en ligne alors que les recruteurs le consultent presque systématiquement.
5. Se limiter à une seule plateforme ou un seul canal de recherche.

Sur Goriya, centralise tes candidatures, suis leur statut en temps réel et laisse l'IA t'indiquer ce qui peut être amélioré à chaque étape.
MD,
                'daysAgo' => 25,
            ],
        ];

        foreach ($articles as $data) {
            Article::updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'title' => $data['title'],
                    'excerpt' => $data['excerpt'],
                    'content' => $data['content'],
                    'author_name' => 'Équipe Goriya',
                    'status' => ArticleStatus::PUBLISHED->value,
                    'published_at' => Carbon::now()->subDays($data['daysAgo']),
                ]
            );
        }

        $this->command?->info('✅ Articles seeded ('.count($articles).')');
    }
}
