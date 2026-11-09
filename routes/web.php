<?php

use App\Http\Controllers\PublicFuneralController;
use App\Http\Controllers\StaffAccountsController;
use App\Http\Controllers\StaffFuneralController;
use App\Http\Controllers\StaffSessionController;
use App\Http\Middleware\RequireActiveStaff;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicFuneralController::class, 'create'])->name('home');
Route::post('/pengajuan', [PublicFuneralController::class, 'store'])->middleware('throttle:20,1');

Route::middleware('guest')->group(function () {
    Route::inertia('/masuk', 'login')->name('login');
    Route::post('/masuk', [StaffSessionController::class, 'store']);
});

Route::middleware(['auth', RequireActiveStaff::class])->group(function () {
    Route::get('/pengajuan/{id}', fn (string $id) => to_route('staff.requests.show', $id))->whereUuid('id');
    Route::post('/keluar', [StaffSessionController::class, 'destroy']);
    Route::get('/staf', [StaffFuneralController::class, 'index'])->name('staff');
    Route::get('/staf/pengajuan', [StaffFuneralController::class, 'requests'])->name('staff.requests');
    Route::prefix('/staf/pengajuan/{id}')->whereUuid('id')->group(function () {
        Route::get('/', [StaffFuneralController::class, 'show'])->name('staff.requests.show');
        Route::patch('/', [StaffFuneralController::class, 'update']);
        Route::post('/status', [StaffFuneralController::class, 'transition']);
        Route::post('/notifikasi/{delivery}/kirim-ulang', [StaffFuneralController::class, 'retryNotification'])->whereUuid('delivery')->middleware('throttle:5,1');
        Route::post('/penanggung-jawab', [StaffFuneralController::class, 'transfer']);
        Route::post('/dokumen', [StaffFuneralController::class, 'attach']);
        Route::get('/dokumen/{document}', [StaffFuneralController::class, 'download'])->whereUuid('document');
    });
    Route::get('/staf/pengguna', [StaffAccountsController::class, 'index'])->can('manage', User::class)->name('staff.users');
    Route::post('/staf/pengguna', [StaffAccountsController::class, 'store'])->can('manage', User::class);
    Route::patch('/staf/pengguna/{user}', [StaffAccountsController::class, 'update'])->can('manage', User::class);
});
