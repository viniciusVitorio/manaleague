<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rating inicial passa de 1000 para 600 (Ferro II).
 * So altera formatos de temporadas que ainda nao tem nenhum rating criado;
 * temporadas com partidas mantem a regra com que comecaram (historico intacto).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ranked_season_formats')
            ->where('starting_rating', 1000)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('player_ratings')
                ->whereColumn('player_ratings.season_id', 'ranked_season_formats.season_id')
                ->whereColumn('player_ratings.format', 'ranked_season_formats.format'))
            ->update(['starting_rating' => 600]);
    }

    public function down(): void
    {
        DB::table('ranked_season_formats')
            ->where('starting_rating', 600)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('player_ratings')
                ->whereColumn('player_ratings.season_id', 'ranked_season_formats.season_id')
                ->whereColumn('player_ratings.format', 'ranked_season_formats.format'))
            ->update(['starting_rating' => 1000]);
    }
};
