<?php

/*
 * Foto de perfil.
 *
 * - Em dev/testes as fotos ficam no disco "local" (storage/app/private) e sao servidas pela rota
 *   /avatars/{arquivo}.
 * - Em producao use AVATAR_DISK=s3: bucket privado, credenciais da IAM Role da EC2 (IMDSv2) e
 *   URLs temporarias assinadas (presigned GET). Nenhum objeto do bucket e publico.
 */
return [
    'disk' => env('AVATAR_DISK', 'local'),

    // Pasta (prefixo) dentro do disco; a policy IAM da EC2 so libera este prefixo.
    'directory' => 'avatars',

    // Lado do quadrado gerado (px). A imagem enviada e recortada no centro e reencodada em WebP.
    'size' => 256,
    'quality' => 82,

    // Limites do upload.
    'max_kilobytes' => 5120,
    'min_side' => 64,
    'max_pixels' => 16_777_216, // 16 MP: evita "bombas de descompressao" estourando a memoria do GD

    // URLs assinadas sao geradas dentro de "janelas" fixas: na mesma janela a URL e identica,
    // entao o navegador reaproveita o cache. Cada URL vale a janela + o tempo extra.
    'url_window_minutes' => 30,
    'url_grace_minutes' => 90,
];
