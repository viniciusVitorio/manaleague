<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

Artisan::command('inspire', function (): void {
    $this->comment('Shuffle up, play fair, deploy securely.');
})->purpose('Display a secure-development message');

Artisan::command('mail:test {to : E-mail de destino}', function (string $to): int {
    if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $this->error('E-mail invalido.');
        return 1;
    }

    $mailer = config('mail.default');
    $this->info("Enviando e-mail de teste via [{$mailer}] para {$to}...");

    try {
        Mail::raw(
            "Este e-mail confirma que o ManaLeague consegue enviar mensagens via {$mailer}.\n\nSe chegou na caixa de entrada (e nao no spam), SPF/DKIM/DMARC estao corretos.",
            fn ($message) => $message->to($to)->subject('Teste de envio — ManaLeague'),
        );
    } catch (Throwable $exception) {
        $this->error('Falha no envio: '.$exception->getMessage());
        $this->line('Dicas: conta SES em sandbox so envia para enderecos verificados; confira a IAM Role da EC2 e AWS_SES_REGION.');
        return 1;
    }

    $this->info('Enviado.');
    return 0;
})->purpose('Envia um e-mail de teste para validar a configuracao (SES em producao)');


Artisan::command('ml:admin {email : E-mail da conta} {--revoke : Remove o acesso de admin}', function (string $email): int {
    $user = \App\Models\User::where('email', $email)->first();
    if (! $user) {
        $this->error('Conta não encontrada.');
        return 1;
    }
    $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
    $this->info($this->option('revoke') ? "{$email} não é mais admin." : "{$email} agora é admin (moderação do ranked).");
    return 0;
})->purpose('Concede ou remove o papel de admin (moderação do ranked)');

Artisan::command('ml:construtor {email : E-mail da conta} {--revoke : Remove a conquista}', function (string $email): int {
    $user = \App\Models\User::where('email', $email)->first();
    if (! $user) {
        $this->error('Conta não encontrada.');
        return 1;
    }
    $service = app(\App\Services\AchievementService::class);
    $profile = $user->ensurePlayerProfile();
    if ($this->option('revoke')) {
        $this->info($service->revoke($profile, 'builder') ? "Conquista removida de {$email}." : "{$email} não tinha a conquista.");
    } else {
        $this->info($service->grant($profile, 'builder') ? "🛠️ {$email} agora é Construtor da Liga." : "{$email} já tinha a conquista.");
    }
    return 0;
})->purpose('Concede (ou remove) a conquista Construtor da Liga para quem ajudou no projeto');

Artisan::command('ranked:tick', function (): int {
    app(\App\Services\Ranked\SeasonService::class)->refresh();
    $this->info('Temporadas atualizadas.');
    return 0;
})->purpose('Avança temporadas por data (agendada → em andamento → encerrada)');

Artisan::command('ranked:prune', function (): int {
    $days = (int) config('ranked.ip_retention_days');
    $n = \App\Models\RankedResultSubmission::whereNotNull('ip_address')->where('created_at', '<', now()->subDays($days))->update(['ip_address' => null]);
    $this->info("IP removido de {$n} envio(s) de placar com mais de {$days} dias.");
    return 0;
})->purpose('Aplica a retenção limitada de IP dos envios de placar ranqueado');

\Illuminate\Support\Facades\Schedule::command('ranked:tick')->everyMinute()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('ranked:prune')->daily();
