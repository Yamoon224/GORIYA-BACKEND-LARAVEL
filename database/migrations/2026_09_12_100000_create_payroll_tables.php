<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Services RH — étape 4 : paie.
 *
 * - payroll_settings : cotisations et barème d'impôt de l'entreprise (une ligne
 *   par entreprise, créée à la première modification ; valeurs par défaut sinon) ;
 * - payroll_runs : une période de paie par mois ;
 * - payslips : un bulletin par employé et par période. L'identité de l'employé
 *   y est recopiée : un bulletin reste lisible si la fiche est supprimée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            // CI : valeurs par défaut Côte d'Ivoire ; CUSTOM : modifiées par l'entreprise.
            $table->string('preset')->default('CI');
            // [{code, label, employeeRate, employerRate, ceiling, employeeFixed, employerFixed}]
            $table->json('contributions');
            // [{upTo, rate}] — tranches mensuelles, la dernière sans plafond.
            $table->json('tax_brackets');
            $table->string('tax_label')->default('ITS');
            $table->decimal('taxable_abatement_percent', 5, 2)->default(0);
            $table->boolean('deduct_employee_contributions')->default(false);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status')->default('DRAFT');
            $table->unsignedInteger('employees_count')->default(0);
            // Montants en FCFA, entiers.
            $table->bigInteger('total_gross')->default(0);
            $table->bigInteger('total_employee_contributions')->default(0);
            $table->bigInteger('total_employer_contributions')->default(0);
            $table->bigInteger('total_tax')->default(0);
            $table->bigInteger('total_net')->default(0);
            $table->bigInteger('total_employer_cost')->default(0);
            $table->date('payment_date')->nullable();
            $table->string('payment_reference')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignUuid('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['company_id', 'year', 'month']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            // Conservé si l'employé est supprimé : le bulletin est un document de paie.
            $table->foreignUuid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->json('employee_snapshot');
            $table->bigInteger('base_salary')->default(0);
            $table->unsignedTinyInteger('business_days')->default(0);
            $table->unsignedTinyInteger('worked_days')->default(0);
            $table->unsignedTinyInteger('unpaid_leave_days')->default(0);
            // Saisies manuelles des RH, conservées d'un recalcul à l'autre.
            $table->json('bonuses');
            $table->json('deductions');
            // Avances sur salaire retenues sur ce bulletin (identifiants de hr_requests).
            $table->json('advance_ids');
            // Détail calculé : [{code, label, section, base, employeeRate, employerRate, employeeAmount, employerAmount}]
            $table->json('lines');
            $table->json('warnings');
            $table->bigInteger('gross')->default(0);
            $table->bigInteger('employee_contributions')->default(0);
            $table->bigInteger('employer_contributions')->default(0);
            $table->bigInteger('taxable')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('advances')->default(0);
            $table->bigInteger('other_deductions')->default(0);
            $table->bigInteger('net')->default(0);
            $table->bigInteger('employer_cost')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['payroll_run_id', 'employee_id']);
        });

        // Bulletin sur lequel une avance a été retenue, posé à la validation de la
        // paie. Colonne simple (sans contrainte) : l'ajout d'une clé étrangère sur
        // une table existante n'est pas portable vers SQLite ; la remise à zéro
        // est faite par PayrollService à la réouverture.
        Schema::table('hr_requests', function (Blueprint $table) {
            $table->uuid('payslip_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('hr_requests', function (Blueprint $table) {
            $table->dropIndex(['payslip_id']);
            $table->dropColumn('payslip_id');
        });
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_settings');
    }
};
