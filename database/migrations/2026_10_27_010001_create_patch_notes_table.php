<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Patch notes copiados das GitHub Releases no deploy (patch-notes:sync). O GitHub e a fonte; esta tabela e so uma copia. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patch_notes', function (Blueprint $table) {
            $table->id();
            $table->string('version', 50)->unique();
            $table->string('title', 120);
            $table->text('body')->nullable();
            $table->timestamp('published_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patch_notes');
    }
};
