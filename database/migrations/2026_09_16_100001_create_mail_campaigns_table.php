<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('subject');

            // Contenu HTML de la campagne, rédigé/édité par l'admin depuis le
            // module — supporte les placeholders {{entreprise}}, {{contact}},
            // {{secteur}}, {{ville}} remplacés à l'envoi (voir PartnerCampaignMail).
            $table->longText('body_html');

            // draft -> sending -> sent (ou failed si l'envoi plante avant la fin).
            $table->string('status')->default('draft');

            // Filtres utilisés pour résoudre les destinataires au moment de
            // l'envoi (secteur/ville/taille/statut...), conservés pour audit.
            $table->json('target_filters')->nullable();

            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaigns');
    }
};
