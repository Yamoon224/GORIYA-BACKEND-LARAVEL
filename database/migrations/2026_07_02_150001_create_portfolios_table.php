<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('portfolios', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description');
            $table->integer('views')->default(0);
            $table->integer('downloads')->default(0);
            $table->integer('likes')->default(0);
            $table->timestamp('created_date');
            // Relation NestJS non-nullable (pas de {nullable:true}, pas de `?`) :
            // contrairement à company_id sur users, on ne peut pas mettre à NULL
            // ici, donc cascadeOnDelete() plutôt que blocage à la suppression.
            $table->foreignUuid('user_id')
                ->constrained('users')->cascadeOnDelete();
            // JSON (pas jsonb/text[] Postgres) pour rester portable MySQL <-> Postgres — voir cast 'array' sur le modèle.
            $table->json('skills')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portfolios');
    }
};
