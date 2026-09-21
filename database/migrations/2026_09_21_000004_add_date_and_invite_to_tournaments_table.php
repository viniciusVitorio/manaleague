<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table("tournaments", function (Blueprint $table): void {
            $table->date("tournament_date")->nullable()->after("format");
            $table->string("public_slug", 180)->nullable()->unique()->after("public_token");
            $table->string("invite_token", 64)->nullable()->unique()->after("public_slug");
        });

        DB::table("tournaments")->orderBy("id")->eachById(function (object $tournament): void {
            DB::table("tournaments")->where("id", $tournament->id)->update([
                "public_slug" => Str::slug($tournament->name)."-".Str::lower(Str::random(8)),
                "invite_token" => Str::random(40),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table("tournaments", function (Blueprint $table): void {
            $table->dropUnique(["invite_token"]);
            $table->dropUnique(["public_slug"]);
            $table->dropColumn(["tournament_date", "public_slug", "invite_token"]);
        });
    }
};
