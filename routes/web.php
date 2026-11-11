<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Integration docs (plan §15): rendered from docs/openapi.yaml.
Route::view('/docs/api', 'docs.api')->name('api.docs');
