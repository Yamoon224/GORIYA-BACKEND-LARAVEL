<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Module Services RH — étape 3 : contrats des employés.
 *
 * Jusqu'ici le contrat se résumait à trois colonnes de `employees` (type, date
 * de fin, salaire). Il devient un historique : contrat initial, renouvellements,
 * avenants, avec sa signature et son document. Les colonnes de `employees`
 * restent, synchronisées sur le contrat en vigueur : le répertoire et la paie
 * continuent de les lire sans jointure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            // Contrat prolongé (renouvellement) ou modifié (avenant).
            $table->foreignUuid('parent_id')->nullable()->constrained('employee_contracts')->nullOnDelete();
            $table->string('reference');
            $table->string('kind')->default('INITIAL');
            $table->string('type');
            $table->string('job_title');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('trial_end_date')->nullable();
            // Brut mensuel en FCFA.
            $table->unsignedBigInteger('salary')->nullable();
            $table->unsignedTinyInteger('weekly_hours')->nullable();
            $table->string('status')->default('DRAFT');
            $table->date('signed_at')->nullable();
            $table->date('termination_date')->nullable();
            $table->text('termination_reason')->nullable();
            // Contrat signé, sur le disque privé : jamais d'URL publique.
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->timestamp('document_uploaded_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['company_id', 'reference']);
            $table->index(['employee_id', 'status']);
            $table->index(['company_id', 'status']);
        });

        $this->openInitialContracts();
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contracts');
    }

    /**
     * Les employés enregistrés avant ce module n'ont aucun contrat : on leur
     * ouvre leur contrat initial d'après leur fiche, pour que « un employé
     * présent a un contrat en vigueur » soit vrai dès le déploiement. Un employé
     * déjà parti reçoit un contrat terminé.
     */
    private function openInitialContracts(): void
    {
        $counters = [];
        $now = now();

        DB::table('employees')
            ->orderBy('company_id')
            ->orderBy('hire_date')
            ->orderBy('id')
            ->each(function (object $employee) use (&$counters, $now) {
                $counters[$employee->company_id] = ($counters[$employee->company_id] ?? 0) + 1;

                DB::table('employee_contracts')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $employee->company_id,
                    'employee_id' => $employee->id,
                    'reference' => sprintf('CTR-%04d', $counters[$employee->company_id]),
                    'kind' => 'INITIAL',
                    'type' => $employee->contract_type,
                    'job_title' => $employee->job_title,
                    'start_date' => $employee->hire_date,
                    'end_date' => $employee->contract_end_date,
                    'salary' => $employee->salary,
                    'status' => $employee->status === 'TERMINATED' ? 'ENDED' : 'ACTIVE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }
};
