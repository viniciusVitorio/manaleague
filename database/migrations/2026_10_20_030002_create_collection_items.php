<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('scryfall_id');
            $table->string('name');
            $table->string('set_code', 20)->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('foil')->default(false);
            $table->string('condition', 3)->default('NM');
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['user_id', 'scryfall_id', 'foil', 'condition'], 'collection_print_finish_condition');
        });
    }
    public function down(): void { Schema::dropIfExists('collection_items'); }
};
