<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class NotificationPreference extends Model {
 /** Categorias da tela de configuração: rótulo e descrição. Silenciar uma silencia todos os tipos dela (match.submitted...). */
 public const CATEGORIES = [
  'tournament' => ['Eventos', 'Rodadas prontas, resultados finais, pódio e estatísticas de campeonatos e torneios'],
  'match' => ['Partidas', 'Placares enviados, confirmados e contestados'],
  'challenge' => ['Desafios', 'Convites, aceites e placares de desafios'],
  'ranked' => ['Ranked', 'Partidas ranqueadas e variação de pontos'],
  'club' => ['Clubes', 'Convites e mudanças nos seus clubes'],
  'achievement' => ['Conquistas', 'Conquistas desbloqueadas'],
  'patch_note' => ['Patch notes', 'Novas versões do ManaLeague'],
 ];
 /** Categorias que já têm e-mail (hoje, o de pódio com estatísticas). As outras ficam "Só no app". */
 public const EMAIL_CATEGORIES = ['tournament'];
 protected $guarded = ['id'];
 protected function casts():array {return ['enabled'=>'boolean','muted_types'=>'array','email_enabled'=>'boolean','email_muted_types'=>'array'];}
 /** Preferência gravada ou o padrão (tudo ligado), para a tela. */
 public static function for(User $user):self {
  return self::firstOrNew(['user_id'=>$user->id], ['enabled'=>true,'muted_types'=>[],'email_enabled'=>true,'email_muted_types'=>[]]);
 }
}
