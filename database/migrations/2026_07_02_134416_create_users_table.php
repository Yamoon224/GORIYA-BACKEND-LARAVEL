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
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('USER');
            $table->string('status')->default('ACTIVE');
            $table->string('avatar')->nullable();
            $table->timestamp('registration_date')->useCurrent();
            // NestJS's nullable ManyToOne has no explicit onDelete (defaults to NO
            // ACTION); nullOnDelete() here is a deliberate, safer deviation that
            // avoids blocked/orphaned deletes.
            $table->foreignUuid('company_id')->nullable()
                ->constrained('companies')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
