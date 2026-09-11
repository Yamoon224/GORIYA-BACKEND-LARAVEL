<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_campaign_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mail_campaign_id')->constrained('mail_campaigns')->cascadeOnDelete();
            $table->foreignUuid('potential_partner_id')->constrained('potential_partners')->cascadeOnDelete();

            // pending -> sent|failed|skipped (skipped = désabonné/email invalide
            // au moment de l'envoi, filtré sans consommer de tentative SMTP).
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // Nom explicite et court : le nom auto-généré par Laravel pour ces
            // deux colonnes dépasse la limite de 64 caractères d'un identifiant
            // MySQL (erreur 1059).
            $table->unique(['mail_campaign_id', 'potential_partner_id'], 'campaign_recipients_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaign_recipients');
    }
};
