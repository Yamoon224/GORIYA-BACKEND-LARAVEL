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
        Schema::create('connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Suivi à sens unique (type "follow"), pas de poignée de main
            // mutuelle — plus simple qu'un flux de demande/acceptation non
            // demandé explicitement par le document produit.
            $table->foreignUuid('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('following_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['follower_id', 'following_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
