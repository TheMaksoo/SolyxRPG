<?php

use App\Http\Controllers\Api\WikiController;
use Illuminate\Support\Facades\Route;

Route::get('/wiki', [WikiController::class, 'index']);
