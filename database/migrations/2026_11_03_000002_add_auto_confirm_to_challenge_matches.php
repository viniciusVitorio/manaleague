<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Placar de desafio sem resposta por 7 dias é confirmado sozinho (ChallengeService::autoConfirmOverdue).
// reported_at marca quando o placar casual foi enviado; auto_confirmed diz que ninguém confirmou.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('casual_matches', function (Blueprint $table) {
            $table->timestamp('reported_at')->nullable()->after('reported_by');
            $table->boolean('auto_confirmed')->default(false)->after('confirmed_at');
        });
        Schema::table('ranked_matches', function (Blueprint $table) {
            $table->boolean('auto_confirmed')->default(false)->after('confirmed_at');
        });

        // Placares já aguardando confirmação: o prazo conta da última mudança (o envio).
        DB::table('casual_matches')->where('status', 'reported')->whereNull('reported_at')->update(['reported_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('casual_matches', fn (Blueprint $table) => $table->dropColumn(['reported_at', 'auto_confirmed']));
        Schema::table('ranked_matches', fn (Blueprint $table) => $table->dropColumn('auto_confirmed'));
    }
};
