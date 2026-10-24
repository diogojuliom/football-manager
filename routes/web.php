<?php

use App\Http\Controllers\CareerController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FriendlyController;
use App\Http\Controllers\ProgressionController;
use App\Http\Controllers\InteractiveMatchController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SquadController;
use App\Http\Controllers\StadiumController;
use App\Http\Controllers\StadiumProjectController;
use App\Http\Controllers\TicketController;
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
    Route::post('/careers/{career}/matches/{match}/live/start', [InteractiveMatchController::class, 'start'])->name('careers.matches.live.start');
    Route::get('/careers/{career}/matches/{match}/live', [InteractiveMatchController::class, 'show'])->name('careers.matches.live.show');
    Route::post('/careers/{career}/matches/{match}/live/control', [InteractiveMatchController::class, 'control'])->name('careers.matches.live.control');
    Route::post('/careers/{career}/matches/{match}/live/advance', [InteractiveMatchController::class, 'advance'])->name('careers.matches.live.advance');
    Route::post('/careers/{career}/matches/{match}/live/decision', [InteractiveMatchController::class, 'decide'])->name('careers.matches.live.decision');
    Route::post('/careers/{career}/matches/{match}/live/finish', [InteractiveMatchController::class, 'finish'])->name('careers.matches.live.finish');
    Route::post('/careers/{career}/matches/{match}/live/finalize', [InteractiveMatchController::class, 'finalize'])->name('careers.matches.live.finalize');
    Route::get('/careers/{career}/results/{result}', [ProgressionController::class, 'result'])->name('careers.results.show');
    Route::get('/careers/{career}/squad', [SquadController::class, 'edit'])->name('careers.squad.edit');
    Route::put('/careers/{career}/squad', [SquadController::class, 'update'])->name('careers.squad.update');
    Route::get('/careers/{career}/friendlies/create', [FriendlyController::class, 'create'])->name('careers.friendlies.create');
    Route::post('/careers/{career}/friendlies', [FriendlyController::class, 'store'])->name('careers.friendlies.store');
    Route::get('/careers/{career}/friendlies/{simulation}', [FriendlyController::class, 'show'])->name('careers.friendlies.show');
    Route::get('/careers/{career}/stadium', [StadiumController::class, 'show'])->name('careers.stadium.show');
    Route::post('/careers/{career}/stadium/projects', [StadiumProjectController::class, 'store'])->name('careers.stadium.projects.store');
    Route::get('/careers/{career}/tickets', [TicketController::class, 'index'])->name('careers.tickets.index');
    Route::patch('/careers/{career}/tickets/default', [TicketController::class, 'updateDefault'])->name('careers.tickets.default');
    Route::get('/careers/{career}/matches/{match}/tickets/preview', [TicketController::class, 'preview'])->name('careers.matches.tickets.preview');
    Route::put('/careers/{career}/matches/{match}/tickets', [TicketController::class, 'updateMatch'])->name('careers.matches.tickets.update');
    Route::delete('/careers/{career}/matches/{match}/tickets', [TicketController::class, 'clearMatch'])->name('careers.matches.tickets.clear');
    Route::get('/careers/{career}/finances', [FinanceController::class, 'show'])->name('careers.finances.show');
    Route::resource('careers', CareerController::class)->only(['index', 'create', 'store', 'show']);
});
