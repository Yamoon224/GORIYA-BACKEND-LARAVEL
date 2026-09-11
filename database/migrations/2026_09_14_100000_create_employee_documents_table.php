<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Services RH — étape 6 : documents RH.
 *
 * Fichiers déposés (pièces d'identité, diplômes, visites médicales…) et
 * attestations rédigées par Goriya. Les fichiers vivent sur le disque privé
 * `local` et ne se téléchargent qu'au travers de l'API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            // Nul pour un document d'entreprise (règlement intérieur, charte…).
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->string('category');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->date('issued_at')->nullable();
            // Pièce d'identité, visite médicale… : alimente les échéances.
            $table->date('expires_at')->nullable();
            // UPLOAD : fichier déposé ; GENERATED : attestation rédigée par Goriya.
            $table->string('source')->default('UPLOAD');
            $table->string('template')->nullable();
            // Demande RH (attestation) à laquelle le document a répondu.
            $table->foreignUuid('hr_request_id')->nullable()->constrained('hr_requests')->nullOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['company_id', 'category']);
            $table->index(['company_id', 'expires_at']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
