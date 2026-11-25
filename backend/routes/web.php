<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $frontend = dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'index.html';

    abort_unless(is_file($frontend), 404);

    return response()->file($frontend);
});

Route::get('/js/{asset}', function (string $asset) {
    abort_unless(in_array($asset, ['api.js', 'stats.js', 'simulacao.js'], true), 404);

    $script = dirname(base_path())
        .DIRECTORY_SEPARATOR.'frontend'
        .DIRECTORY_SEPARATOR.'js'
        .DIRECTORY_SEPARATOR.$asset;

    abort_unless(is_file($script), 404);

    return response()->file($script, ['Content-Type' => 'application/javascript; charset=UTF-8']);
});
