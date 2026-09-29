<?php

use App\Http\Controllers\EvidenceDownloadController;
use App\Http\Controllers\EvidencePreviewController;
use App\Http\Controllers\PrintWorkRecordController;
use App\Http\Controllers\PublicEvidenceDownloadController;
use App\Http\Controllers\PublicEvidencePreviewController;
use App\Http\Controllers\PublicWorkloadSummaryController;
use App\Http\Controllers\WorkRecordsExportController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('throttle:60,1')->prefix('s')->name('share.workload.')->group(function () {
    Route::get('{token}', PublicWorkloadSummaryController::class)->name('show');
    Route::get('{token}/evidence/{evidence}/preview', PublicEvidencePreviewController::class)
        ->name('evidence.preview');
    Route::get('{token}/evidence/{evidence}/download', PublicEvidenceDownloadController::class)
        ->name('evidence.download');
});

Route::redirect('/dashboard', '/home')->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/home', function () {
        $user = Auth::user();

        return match (true) {
            $user->isStaff() => redirect()->route('staff.dashboard'),
            default => redirect()->route('hr.dashboard'),
        };
    })->name('home');

    Route::middleware('role:staff')->prefix('staff')->name('staff.')->group(function () {
        Route::livewire('dashboard', 'pages::staff.dashboard')->name('dashboard');
        Route::livewire('records', 'pages::staff.record-list')->name('records.index');
        Route::livewire('records/create', 'pages::staff.work-record-form')->name('records.create');
        Route::livewire('records/{record}', 'pages::staff.work-record-form')->name('records.show');
        Route::livewire('records/{record}/edit', 'pages::staff.work-record-form')->name('records.edit');
        Route::get('records/{record}/print', PrintWorkRecordController::class)->name('records.print');
        Route::get('evidence/{evidence}/download', EvidenceDownloadController::class)
            ->name('evidence.download');
        Route::get('evidence/{evidence}/preview', EvidencePreviewController::class)
            ->name('evidence.preview');
    });

    Route::middleware('role:hr,admin')->prefix('hr')->name('hr.')->group(function () {
        Route::livewire('dashboard', 'pages::hr.dashboard')->name('dashboard');
        Route::get('dashboard/export', WorkRecordsExportController::class)->name('dashboard.export');
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::livewire('periods', 'pages::admin.period-manager')->name('periods');
        Route::livewire('workloads', 'pages::admin.workload-manager')->name('workloads');
        Route::livewire('users', 'pages::admin.user-manager')->name('users');
    });
});

require __DIR__.'/auth.php';
