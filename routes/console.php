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
