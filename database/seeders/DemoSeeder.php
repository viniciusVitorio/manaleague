<?php

namespace Database\Seeders;

use App\Models\GameMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AchievementService;
use App\Services\FriendshipService;
use App\Services\PairingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Dados de demonstracao para desenvolvimento local e apresentacao.
 * NAO roda em producao: execute manualmente com
 *   php artisan db:seed --class=DemoSeeder
 *
 * Login do organizador: demo@manaleague.test / manaleague123
 * Jogadores (mesma senha): ajani@, chandra@, jace@, liliana@, nissa@, teferi@manaleague.test
 */
class DemoSeeder extends Seeder
{
    public function run(PairingService $pairings): void
    {
        if (app()->isProduction()) {
            $this->command?->error('DemoSeeder bloqueado em producao.');
            return;
        }

        $organizer = User::query()->firstOrCreate(
            ['email' => 'demo@manaleague.test'],
            ['name' => 'Organizador Demo', 'password' => 'manaleague123'],
        );
        $organizer->ensurePlayerProfile();

        $roster = [
            ['Ajani', 'Boros Aggro', 'RW'], ['Chandra', 'Mono Red Burn', 'R'],
            ['Jace', 'Dimir Control', 'UB'], ['Liliana', 'Golgari Midrange', 'BG'],
            ['Nissa', 'Elves', 'G'], ['Teferi', 'Azorius Flash', 'WU'],
        ];

        // Torneio 1: liga em andamento (metade dos resultados lancados).
        $league = $this->tournament($organizer, 'Friday Night Magic', 'Pauper');
        $this->enroll($league, $roster);
        $pairings->generateLeague($league);
        $this->score($league->matches()->where('status', GameMatch::STATUS_PENDING)->orderBy('id')->get()->take(8));

        // Torneio 2: encerrado, com podio e retrospectiva.
        $finished = $this->tournament($organizer, 'Liga de Commander', 'Commander');
        $this->enroll($finished, array_slice($roster, 0, 4));
        $pairings->generateLeague($finished);
        $this->score($finished->matches()->where('status', GameMatch::STATUS_PENDING)->get());
        $pairings->generateFinals($finished);
        $this->score($finished->matches()->where('status', GameMatch::STATUS_PENDING)->get());
        $finished->update(['status' => Tournament::STATUS_FINISHED]);

        // Torneio 3: inscricoes abertas.
        $this->tournament($organizer, 'Draft de Sabado', 'Draft');

        // Amizades: 3 aceitas e 1 pedido pendente esperando o organizador.
        $friendships = app(FriendshipService::class);
        foreach (['ajani', 'chandra', 'jace'] as $name) {
            $friend = User::query()->where('email', "{$name}@manaleague.test")->sole();
            $friendships->request($organizer, $friend->ensurePlayerProfile());
            $friendships->accept($friend, $organizer);
        }
        $friendships->request(User::query()->where('email', 'liliana@manaleague.test')->sole(), $organizer->ensurePlayerProfile());

        // Conquistas ja calculadas para todos os perfis.
        foreach (User::query()->where('email', 'like', '%@manaleague.test')->get() as $user) {
            app(AchievementService::class)->sync($user->ensurePlayerProfile());
        }

        $this->command?->info('Demo pronta: demo@manaleague.test / manaleague123');
    }

    private function tournament(User $organizer, string $name, string $format): Tournament
    {
        return $organizer->tournaments()->create([
            'name' => $name, 'format' => $format, 'tournament_date' => now()->addDays(rand(1, 20))->toDateString(),
            'public_token' => Str::random(40),
            'public_slug' => Str::slug($name).'-'.Str::lower(Str::random(16)),
            'invite_token' => Str::random(40),
        ]);
    }

    private function enroll(Tournament $tournament, array $roster): void
    {
        foreach ($roster as [$name, $deck, $colors]) {
            // Cada jogador tem conta propria, perfil e o deck cadastrado.
            $user = User::query()->firstOrCreate(
                ['email' => Str::lower($name).'@manaleague.test'],
                ['name' => $name, 'password' => 'manaleague123'],
            );
            $profile = $user->ensurePlayerProfile();
            $saved = $profile->decks()->firstOrCreate(['name' => $deck], ['colors' => $colors, 'is_primary' => true]);
            $profile->update(['preferred_deck_name' => $deck, 'preferred_deck_colors' => $colors]);

            $tournament->players()->create(['name' => $name, 'player_profile_id' => $profile->id, 'deck_id' => $saved->id, 'deck_name' => $deck, 'deck_colors' => $colors]);
        }
    }

    private function score($matches): void
    {
        $scores = [[2, 0], [2, 1], [1, 2], [0, 2], [2, 1], [1, 2]];
        foreach ($matches->values() as $i => $match) {
            [$one, $two] = $scores[$i % count($scores)];
            $match->update(['player_one_games' => $one, 'player_two_games' => $two, 'status' => GameMatch::STATUS_FINISHED, 'played_at' => now()->addMinutes($i)]);
        }
    }
}
