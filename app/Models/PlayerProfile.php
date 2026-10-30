<?php

namespace App\Models;

use App\Services\AvatarService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlayerProfile extends Model
{
    /** Sem 0/O/1/I/L para o codigo ser facil de ditar na mesa. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected $fillable = ['user_id', 'account_user_id', 'nickname', 'avatar', 'preferred_deck_name', 'preferred_deck_colors', 'is_friend', 'discoverable', 'ranked_public'];

    protected function casts(): array { return ['is_friend' => 'boolean', 'discoverable' => 'boolean', 'ranked_public' => 'boolean', 'ranked_suspended_until' => 'datetime', 'handle_changed_at' => 'datetime']; }

    /** Intervalo mínimo entre trocas de endereço e por quanto tempo o endereço antigo fica reservado (e redireciona). */
    public const HANDLE_COOLDOWN_DAYS = 30;

    public const HANDLE_HOLD_DAYS = 90;

    /** Endereços que ninguém pode escolher (rotas, equipe, termos que confundem). */
    public const RESERVED_HANDLES = ['admin', 'administrador', 'adm', 'root', 'staff', 'suporte', 'support', 'ajuda', 'help', 'moderador', 'mod',
        'manaleague', 'mana-league', 'oficial', 'official', 'sistema', 'system', 'api', 'me', 'eu', 'p', 'perfil', 'profile', 'login', 'logout',
        'register', 'cadastro', 'settings', 'config', 'configuracoes', 'edit', 'editar', 'new', 'novo', 'null', 'undefined', 'jogador'];

    protected static function booted(): void
    {
        static::creating(function (PlayerProfile $profile): void {
            $profile->friend_code ??= static::generateFriendCode();
        });
        // Endereço do perfil (/p/{handle}): gerado do apelido uma vez só; depois só muda quando o jogador escolhe outro.
        static::saving(function (PlayerProfile $profile): void {
            if ($profile->handle === null) {
                $profile->handle = static::uniqueHandle((string) $profile->nickname, fn (string $h) => static::handleTaken($h, $profile));
            }
        });
    }

    /** O endereço está com outro perfil ou reservado (endereço antigo de outro jogador, ainda redirecionando). */
    public static function handleTaken(string $handle, ?PlayerProfile $except = null): bool
    {
        $mine = fn ($query) => $except?->exists ? $query->where('player_profile_id', '!=', $except->id) : $query;

        return static::query()->where('handle', $handle)->when($except?->exists, fn ($q) => $q->whereKeyNot($except->id))->exists()
            || ProfileHandleHold::query()->active()->where('handle', $handle)->tap($mine)->exists();
    }

    /** Quando o jogador pode trocar o endereço de novo (null = já pode). */
    public function nextHandleChangeAt(): ?\Illuminate\Support\Carbon
    {
        $next = $this->handle_changed_at?->copy()->addDays(self::HANDLE_COOLDOWN_DAYS);

        return $next && $next->isFuture() ? $next : null;
    }

    /** "Ana Lúcia" → "ana-lucia"; sem letras/números vira "jogador". $taken diz se um handle já está em uso. */
    public static function uniqueHandle(string $nickname, callable $taken): string
    {
        $base = \Illuminate\Support\Str::limit(\Illuminate\Support\Str::slug($nickname), 30, '') ?: 'jogador';
        $base = rtrim($base, '-') ?: 'jogador';
        $handle = $base;
        for ($n = 2; $taken($handle); $n++) {
            $handle = $base.'-'.$n;
        }

        return $handle;
    }

    /** Emoji escolhido no perfil ou, sem ele, a inicial do apelido (para os circulos de avatar). */
    public function mark(): string
    {
        return $this->avatar ?: mb_strtoupper(mb_substr($this->nickname ?: '?', 0, 1));
    }

    /** URL da foto de perfil (assinada no S3) ou null quando nao ha foto. */
    public function avatarUrl(): ?string
    {
        return app(AvatarService::class)->url($this->avatar_path);
    }

    /** Apelido com o emoji do perfil na frente, quando houver. */
    public function displayName(): string
    {
        return ($this->avatar ? $this->avatar.' ' : '').$this->nickname;
    }

    public function rankedSuspended(): bool
    {
        return $this->ranked_suspended_until !== null && $this->ranked_suspended_until->isFuture();
    }

    public static function generateFriendCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (static::query()->where('friend_code', $code)->exists());

        return $code;
    }

    /** Codigo exibido como XXXX-XXXX. */
    public function formattedFriendCode(): string
    {
        return substr((string) $this->friend_code, 0, 4).'-'.substr((string) $this->friend_code, 4);
    }

    public static function normalizeFriendCode(string $input): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function account(): BelongsTo { return $this->belongsTo(User::class, 'account_user_id'); }
    public function entries(): HasMany { return $this->hasMany(Player::class); }
    public function decks(): HasMany { return $this->hasMany(Deck::class); }
    public function achievements(): HasMany { return $this->hasMany(ProfileAchievement::class); }
    public function handleHolds(): HasMany { return $this->hasMany(ProfileHandleHold::class); }
    public function friendedBy(): BelongsToMany { return $this->belongsToMany(User::class)->withPivot('status')->withTimestamps(); }
}
