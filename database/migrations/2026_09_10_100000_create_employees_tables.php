<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Services RH — étape 1 : répertoire des employés, leurs congés et leurs
 * demandes RH (attestations, avances, formations…).
 *
 * Tout est rattaché à `company_id` et supprimé avec l'entreprise. Les congés et
 * demandes disparaissent avec l'employé ; un responsable supprimé laisse
 * simplement ses collaborateurs sans responsable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            // Responsable hiérarchique : un autre employé de la même entreprise
            // (vérifié par EmployeeService). Référence à la table elle-même,
            // déclarée dans le CREATE pour rester portable MySQL / SQLite.
            $table->foreignUuid('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            // Embauche issue d'une candidature Goriya : la candidature d'origine
            // (unique — on n'embauche pas deux fois la même) et le compte du
            // candidat. Tous deux nuls pour une fiche saisie à la main.
            $table->foreignUuid('candidature_id')->nullable()->unique()->constrained('candidatures')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('matricule');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender', 1)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('job_title');
            $table->string('department')->nullable();
            $table->string('contract_type');
            $table->date('hire_date');
            $table->date('contract_end_date')->nullable();
            // Salaire brut mensuel en FCFA : entier, pas de centimes.
            $table->unsignedBigInteger('salary')->nullable();
            // 26 jours ouvrables : 2,2 jours par mois travaillé (Code du travail ivoirien).
            $table->unsignedSmallInteger('annual_leave_days')->default(26);
            $table->string('status')->default('ACTIVE');
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['company_id', 'matricule']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('employee_leaves', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type');
            $table->date('start_date');
            $table->date('end_date');
            // Jours ouvrés (lundi-vendredi) calculés côté serveur à la création.
            $table->unsignedSmallInteger('days');
            $table->text('reason')->nullable();
            $table->string('status')->default('PENDING');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['employee_id', 'status']);
            $table->index(['company_id', 'start_date']);
        });

        Schema::create('hr_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type');
            $table->string('subject');
            $table->text('description')->nullable();
            // Montant en FCFA, renseigné pour une avance sur salaire.
            $table->unsignedBigInteger('amount')->nullable();
            $table->string('status')->default('PENDING');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_requests');
        Schema::dropIfExists('employee_leaves');
        Schema::dropIfExists('employees');
    }
};
