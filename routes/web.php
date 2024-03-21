<?php

use App\Http\Controllers\DiageoController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');


Route::get('/clientes_originais', [DiageoController::class,'index'])->middleware(['auth', 'verified'])->name('clientes_originais');
Route::get('/clientes_ajustados', [DiageoController::class,'index'])->middleware(['auth', 'verified'])->name('clientes_ajustados');


Route::get('/venda/{venda}', [DiageoController::class,'vendas'])->middleware(['auth', 'verified'])->name('vendas');
Route::get('/cnpj/{cnpj}', [DiageoController::class,'vendas_cnpj'])->middleware(['auth', 'verified'])->name('cnpj');
Route::get('/grafico', [DiageoController::class,'grafico'])->middleware(['auth', 'verified'])->name('grafico');


Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
