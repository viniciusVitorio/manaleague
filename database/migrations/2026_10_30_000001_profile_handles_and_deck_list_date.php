<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Endereço do perfil escolhido pelo jogador: guarda a data da última troca (limite de trocas).
        Schema::table('player_profiles', function (Blueprint $table): void {
            $table->timestamp('handle_changed_at')->nullable()->after('handle');
        });

        // Endereço antigo fica reservado por um tempo e redireciona para o novo.
        Schema::create('profile_handle_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_profile_id')->constrained()->cascadeOnDelete();
            $table->string('handle', 40)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        // Data em que a lista de cartas mudou (o updated_at do deck também muda com capa, principal etc.).
        Schema::table('decks', function (Blueprint $table): void {
            $table->timestamp('cards_updated_at')->nullable();
        });
        DB::table('decks')->update([
            'cards_updated_at' => DB::raw('(SELECT MAX(deck_cards.updated_at) FROM deck_cards WHERE deck_cards.deck_id = decks.id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('decks', fn (Blueprint $table) => $table->dropColumn('cards_updated_at'));
        Schema::dropIfExists('profile_handle_holds');
        Schema::table('player_profiles', fn (Blueprint $table) => $table->dropColumn('handle_changed_at'));
    }
};
