<?php

use App\Http\Controllers\CareerController;
use App\Http\Controllers\FriendlyController;
use App\Http\Controllers\ProgressionController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SquadController;
use App\Http\Controllers\StadiumController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'careers.welcome')->name('home');

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:6,1');
    Route::view('/register', 'auth.register')->name('register');
    Route::post('/register', [SessionController::class, 'register'])->middleware('throttle:6,1');
    Route::view('/forgot-password', 'auth.forgot-password')->name('password.request');
    Route::post('/forgot-password', [SessionController::class, 'sendResetLink'])->middleware('throttle:6,1')->name('password.email');
    Route::get('/reset-password/{token}', [SessionController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [SessionController::class, 'resetPassword'])->middleware('throttle:6,1')->name('password.update');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [SessionController::class, 'logout'])->name('logout');
    Route::post('/careers/{career}/progression', [ProgressionController::class, 'store'])->name('careers.progression.store');
    Route::post('/careers/{career}/operations/{operation}/resume', [ProgressionController::class, 'resume'])->name('careers.operations.resume');
    Route::get('/careers/{career}/matches/{match}/pre-match', [ProgressionController::class, 'preMatch'])->name('careers.matches.pre-match');
    Route::get('/careers/{career}/results/{result}', [ProgressionController::class, 'result'])->name('careers.results.show');
    Route::get('/careers/{career}/squad', [SquadController::class, 'edit'])->name('careers.squad.edit');
    Route::put('/careers/{career}/squad', [SquadController::class, 'update'])->name('careers.squad.update');
    Route::get('/careers/{career}/friendlies/create', [FriendlyController::class, 'create'])->name('careers.friendlies.create');
    Route::post('/careers/{career}/friendlies', [FriendlyController::class, 'store'])->name('careers.friendlies.store');
    Route::get('/careers/{career}/friendlies/{simulation}', [FriendlyController::class, 'show'])->name('careers.friendlies.show');
    Route::get('/careers/{career}/stadium', [StadiumController::class, 'show'])->name('careers.stadium.show');
    Route::resource('careers', CareerController::class)->only(['index', 'create', 'store', 'show']);
});
