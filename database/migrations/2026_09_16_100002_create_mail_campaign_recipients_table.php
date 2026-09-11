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

            $table->unique(['mail_campaign_id', 'potential_partner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_campaign_recipients');
    }
};
