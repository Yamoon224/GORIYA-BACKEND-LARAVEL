<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('potential_partners', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Numéro de Compte Contribuable (identifiant registre CI) —
            // nullable/nullable-unique car les fiches saisies manuellement
            // n'en ont pas toujours un sous la main.
            $table->string('ncc')->nullable()->unique();
            $table->string('company_name');
            $table->string('email')->nullable();
            $table->boolean('email_valid')->default(false);
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('sector')->nullable();
            $table->string('activity_label')->nullable();
            $table->string('city')->nullable();
            $table->string('commune')->nullable();
            $table->text('address')->nullable();
            $table->string('company_size')->nullable();
            $table->string('ca_tranche')->nullable();
            $table->string('workforce_tranche')->nullable();
            $table->string('first_exercise_year')->nullable();

            // Pipeline de prospection : new -> contacted -> responded ->
            // interested/not_interested -> converted, avec do_not_contact en
            // sortie de secours (opt-out manuel ou plainte).
            $table->string('status')->default('new');
            $table->string('source')->default('manual');
            $table->text('notes')->nullable();

            // Colonnes de la source (ex. import CCI-CI) non promues en
            // colonnes dédiées, conservées telles quelles pour référence.
            $table->json('raw_data')->nullable();

            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('sector');
            $table->index('city');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('potential_partners');
    }
};
