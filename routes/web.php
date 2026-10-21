<?php

use App\Http\Controllers\CareerController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SquadController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'careers.welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:6,1');
    Route::view('/register', 'auth.register')->name('register');
    Route::post('/register', [SessionController::class, 'register'])->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [SessionController::class, 'logout'])->name('logout');
    Route::get('/careers/{career}/squad', [SquadController::class, 'edit'])->name('careers.squad.edit');
    Route::put('/careers/{career}/squad', [SquadController::class, 'update'])->name('careers.squad.update');
    Route::resource('careers', CareerController::class)->only(['index', 'create', 'store', 'show']);
});
