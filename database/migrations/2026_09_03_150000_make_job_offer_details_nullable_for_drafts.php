<?php

use App\Enums\JobStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rend facultatives les colonnes de détail d'une offre pour permettre
 * l'enregistrement d'un brouillon.
 *
 * Une offre en statut DRAFT n'est pas encore rédigée : l'entreprise doit
 * pouvoir la reprendre plus tard sans avoir dû inventer un salaire ou une date
 * de clôture. Les contrôles de complétude sont déplacés au moment de la
 * publication (JobOfferService::assertPublishable), là où ils ont un sens.
 *
 * `title` reste obligatoire : c'est ce qui identifie le brouillon dans la liste
 * des annonces.
 */
return new class extends Migration
{
    /** Colonnes concernées, avec leur type d'origine. */
    private const CHAINES = ['location', 'type', 'experience', 'salary'];

    private const TEXTES = ['description', 'benefits'];

    private const DATES = ['publish_date', 'end_date'];

    public function up(): void
    {
        Schema::table('job_offers', function (Blueprint $table) {
            foreach (self::CHAINES as $colonne) {
                $table->string($colonne)->nullable()->change();
            }
            foreach (self::TEXTES as $colonne) {
                $table->text($colonne)->nullable()->change();
            }
            foreach (self::DATES as $colonne) {
                $table->date($colonne)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Les brouillons existants ont des colonnes vides : les rendre à nouveau
        // NOT NULL échouerait. On les supprime d'abord — ils n'ont jamais été
        // publiés, donc jamais candidatés.
        DB::table('job_offers')->where('status', JobStatus::DRAFT->value)->delete();

        Schema::table('job_offers', function (Blueprint $table) {
            foreach (self::CHAINES as $colonne) {
                $table->string($colonne)->nullable(false)->change();
            }
            foreach (self::TEXTES as $colonne) {
                $table->text($colonne)->nullable(false)->change();
            }
            foreach (self::DATES as $colonne) {
                $table->date($colonne)->nullable(false)->change();
            }
        });
    }
};
