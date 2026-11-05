<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:deployment', function () {
    $this->info('E-commerce Laravel application is running.');
})->purpose('Show deployment health information');
