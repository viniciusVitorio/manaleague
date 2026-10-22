<?php

return [
    'defaults' => ['guard' => env('AUTH_GUARD', 'web'), 'passwords' => env('AUTH_PASSWORD_BROKER', 'users')],
    // "remember" (em minutos) define quanto tempo o "Manter conectado" do login dura.
    // Navegadores limitam cookies a ~400 dias, então valores acima disso não têm efeito.
    'guards' => ['web' => [
        'driver' => 'session',
        'provider' => 'users',
        'remember' => (int) env('AUTH_REMEMBER_DAYS', 365) * 24 * 60,
    ]],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => env('AUTH_MODEL', App\Models\User::class)]],
    'passwords' => ['users' => ['provider' => 'users', 'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'), 'expire' => 60, 'throttle' => 60]],
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
