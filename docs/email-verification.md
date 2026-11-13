# ML-102 — Confirmação de e-mail

## Regra

- Contas novas recebem um e-mail com link assinado, com prazo de 60 minutos.
- Até confirmar, a pessoa pode abrir a tela de confirmação, pedir reenvio e sair da conta, mas não usar recursos privados nem autorizar canais privados do Reverb.
- O link é vinculado ao ID e ao e-mail da conta conectada. Outra conta, hash alterado, assinatura adulterada ou prazo vencido não confirmam nada.
- A confirmação é idempotente: abrir o mesmo link novamente não duplica o evento de confirmação.
- Reenvio é autenticado, limitado por conta e protegido contra CSRF. O cadastro já inicia o cooldown para evitar envio imediato duplicado.
- Falha do provedor não desfaz a conta nem libera o acesso: a tela informa o problema e permite tentar novamente.

## Compatibilidade com quem já tem conta

A migration acrescenta `users.email_verification_required`. Novas contas exigem confirmação por padrão; contas presentes ao executar a migration recebem `false` e preservam acesso. O processo **não** preenche artificialmente `email_verified_at`: contas antigas não passam a ser descritas como verificadas sem terem confirmado o e-mail.

O campo de exigência é controlado pelo servidor, não por parâmetros de cadastro ou edição de perfil. Um usuário pendente que faça login novamente volta à tela de confirmação.

## E-mail e operação

O fluxo usa o transporte já configurado em `config/mail.php`, sem serviço ou worker novo:

- `MAIL_MAILER=log`: desenvolvimento; a mensagem fica no log, **não chega à caixa postal**.
- `MAIL_MAILER=ses`: envio pelo SES já integrado ao projeto; exige remetente verificado, permissão de envio e conta SES habilitada para os destinatários reais. Em sandbox, destinatários comuns não verificados não recebem.
- `MAIL_MAILER=smtp`: transporte SMTP existente, inclusive para captura local em testes.

Antes do deploy, confirme que `APP_URL` é o domínio HTTPS público e que o provedor consegue enviar para destinatários reais. Um cadastro bloqueado à espera de uma mensagem gravada apenas no log não é um fluxo utilizável em produção. Não trocar silenciosamente o provedor nem desativar a confirmação para esconder falhas de envio.

O link exige login na mesma conta. Se o usuário estiver desconectado, o login preserva o destino assinado. A interface não exibe links de confirmação nem tokens; apenas o e-mail do próprio usuário.

## Testes

```bash
php artisan test --filter EmailVerification
php artisan test --filter AuthenticationTest
```

Os testes cobrem cadastro, envio, reenvio/cooldown, falha real de SMTP, autorização das rotas privadas, contas anteriores à migration, assinatura, prazo, ID/hash, confirmação repetida e a tela de espera sem navegação privada.
