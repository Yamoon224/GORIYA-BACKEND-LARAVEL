<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index des requêtes les plus fréquentes, relevés par l'audit de performance
 * (`php artisan perf:audit`) : filtres, tris et périmètres « par utilisateur »
 * ou « par entreprise » qui parcouraient jusque-là toute la table.
 *
 * Chaque index porte les colonnes du WHERE puis celle du ORDER BY : la base
 * lit directement les lignes voulues, déjà triées. Création ignorée si l'index
 * existe déjà — la migration se relance sans erreur.
 */
return new class extends Migration
{
    /**
     * table => liste d'index (colonnes dans l'ordre).
     *
     * @var array<string, list<list<string>>>
     */
    private const INDEXES = [
        // Candidatures : liste du candidat, liste de l'entreprise (par offre), tableaux de bord.
        'candidatures' => [
            ['job_offer_id', 'applied_date'],
            ['user_id', 'applied_date'],
            ['status', 'applied_date'],
            ['applied_date'],
        ],
        // Offres : catalogue public (statut + date), offres d'une entreprise, tendances.
        'job_offers' => [
            ['company_id', 'status'],
            ['status', 'publish_date'],
            ['status', 'created_at'],
            ['publish_date'],
        ],
        // Utilisateurs : listes et compteurs du back-office par rôle.
        'users' => [
            ['role', 'status'],
            ['role', 'registration_date'],
            ['role', 'created_at'],
            ['registration_date'],
        ],
        // Abonnement actif : lu à chaque requête protégée par un forfait.
        'user_subscriptions' => [
            ['user_id', 'status', 'created_at'],
            ['status', 'start_date'],
        ],
        // Notifications : centre de notifications et compteur de non-lus.
        'notifications' => [
            ['user_id', 'created_at'],
            ['user_id', 'is_read'],
        ],
        // Messagerie : fil d'une conversation, dernier message, non-lus, boîte de réception.
        'messages' => [
            ['conversation_id', 'created_at'],
            ['conversation_id', 'read_at', 'sender_id'],
        ],
        'conversations' => [
            ['participant_one_id', 'last_message_at'],
            ['participant_two_id', 'last_message_at'],
        ],
        // GORIYA Meet : appels d'un hôte, sessions à clôturer.
        'call_sessions' => [
            ['host_id', 'created_at'],
            ['status', 'scheduled_at'],
        ],
        // Réseau : fil d'actualité, publications d'un membre ou d'une communauté.
        'posts' => [
            ['created_at'],
            ['user_id', 'created_at'],
            ['community_id', 'created_at'],
        ],
        // Contenus IA du candidat, toujours listés du plus récent au plus ancien.
        'pitches' => [['user_id', 'created_at']],
        'presentations' => [['user_id', 'created_at']],
        'research_queries' => [['user_id', 'created_at']],
        'chat_threads' => [['user_id', 'updated_at']],
        'chat_messages' => [['thread_id', 'created_at']],
        'portfolios' => [
            ['status', 'created_date'],
            ['created_date'],
            ['likes'],
        ],
        // Services RH.
        'hr_requests' => [['company_id', 'created_at']],
        'payslips' => [['company_id', 'employee_id']],
        'employee_surveys' => [['company_id', 'status']],
        // Paiements et codes promo.
        'transactions' => [
            ['user_id', 'created_at'],
            ['status', 'created_at'],
        ],
        'promo_code_redemptions' => [['influencer_id', 'status']],
        // Back-office : prospection, campagnes, journal, tableaux historiques.
        'potential_partners' => [
            ['created_at'],
            ['email_valid', 'unsubscribed_at', 'status'],
        ],
        'mail_campaign_recipients' => [
            ['mail_campaign_id', 'status'],
            ['mail_campaign_id', 'created_at'],
        ],
        'audit_logs' => [['user_id', 'created_at']],
        'articles' => [['status', 'published_at']],
        'companies' => [
            ['status'],
            ['sector'],
        ],
        'calendar_events' => [
            ['start_time'],
            ['status', 'start_time'],
        ],
        'interview_sessions' => [
            ['status', 'start_time'],
            ['start_time'],
            ['created_at'],
        ],
        'matching_results' => [
            ['match_date'],
            ['status'],
        ],
        'scoring_results' => [
            ['analysis_date'],
            ['status'],
        ],
        'cv_analysis' => [
            ['upload_date'],
            ['status', 'upload_date'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $columns) {
                if (! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $columns)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $this->name($table, $columns)));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $columns) {
                $name = $this->name($table, $columns);
                if (! Schema::hasIndex($table, $name)) {
                    continue;
                }

                // MySQL supprime l'index implicite d'une clé étrangère dès qu'un
                // autre index commence par la même colonne : le nôtre peut donc
                // être devenu le support de la contrainte. On lui rend alors un
                // index simple avant de retirer le composite.
                if (count($columns) > 1 && ! Schema::hasIndex($table, [$columns[0]])) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index([$columns[0]]));
                }

                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }

    /**
     * Nom explicite et court (MySQL limite les identifiants à 64 caractères).
     *
     * @param  list<string>  $columns
     */
    private function name(string $table, array $columns): string
    {
        return 'perf_'.substr($table.'_'.implode('_', $columns), 0, 55);
    }
};
