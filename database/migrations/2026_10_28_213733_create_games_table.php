<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('platform', 80);
            $table->string('format', 16)->default('physical');
            $table->string('status', 16)->default('planned');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->text('summary')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->string('developer')->nullable();
            $table->string('publisher')->nullable();
            $table->json('genres')->nullable();
            $table->unsignedBigInteger('igdb_id')->nullable();
            $table->string('igdb_url')->nullable();
            $table->string('identity_key', 64)->unique();
            $table->timestamp('metadata_fetched_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'title', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
