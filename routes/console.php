<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function (): void {
    $this->comment('Shuffle up, play fair, deploy securely.');
})->purpose('Display a secure-development message');
