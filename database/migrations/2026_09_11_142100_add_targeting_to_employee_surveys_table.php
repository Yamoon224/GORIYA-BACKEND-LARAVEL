<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_surveys', function (Blueprint $table) {
            // Échéance : passée cette date, une évaluation ACTIVE est
            // automatiquement clôturée (voir CloseExpiredSurveysCommand).
            $table->date('due_date')->nullable()->after('status');
            // Ciblage : `null` = toute l'entreprise, sinon seuls les employés
            // de ce département la voient dans leur espace employé.
            $table->string('department')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('employee_surveys', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'department']);
        });
    }
};
