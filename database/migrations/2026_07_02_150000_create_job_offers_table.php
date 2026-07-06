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
        Schema::create('job_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('location');
            $table->string('type');
            $table->string('experience');
            $table->string('salary');
            $table->text('description');
            $table->text('benefits');
            $table->string('status')->default('ACTIVE');
            $table->date('publish_date');
            $table->date('end_date');
            $table->integer('applicants')->default(0);
            $table->foreignUuid('company_id')->nullable()
                ->constrained('companies')->nullOnDelete();
            // JSON (pas jsonb/text[] Postgres) pour rester portable MySQL <-> Postgres — voir cast 'array' sur le modèle.
            $table->json('requirements')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_offers');
    }
};
