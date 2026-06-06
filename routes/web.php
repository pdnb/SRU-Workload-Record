<?php

use App\Http\Controllers\EvidenceDownloadController;
use App\Http\Controllers\PrintWorkRecordController;
use App\Http\Controllers\WorkRecordsExportController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::redirect('/dashboard', '/home')->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/home', function () {
        $user = Auth::user();

        return match (true) {
            $user->isStaff() => redirect()->route('staff.records.index'),
            $user->isSupervisor() => redirect()->route('supervisor.review.index'),
            default => redirect()->route('hr.dashboard'),
        };
    })->name('home');

    Route::middleware('role:staff')->prefix('staff')->name('staff.')->group(function () {
        Route::livewire('records', 'pages::staff.record-list')->name('records.index');
        Route::livewire('records/create', 'pages::staff.work-record-form')->name('records.create');
        Route::livewire('records/{record}', 'pages::staff.work-record-form')->name('records.show');
        Route::livewire('records/{record}/edit', 'pages::staff.work-record-form')->name('records.edit');
        Route::get('records/{record}/print', PrintWorkRecordController::class)->name('records.print');
        Route::get('evidence/{entry}/download/{fileIndex?}', EvidenceDownloadController::class)
            ->whereNumber('fileIndex')
            ->name('evidence.download');
    });

    Route::middleware('role:supervisor')->prefix('supervisor')->name('supervisor.')->group(function () {
        Route::livewire('review', 'pages::supervisor.review-queue')->name('review.index');
    });

    Route::middleware('role:hr,admin')->prefix('hr')->name('hr.')->group(function () {
        Route::livewire('dashboard', 'pages::hr.dashboard')->name('dashboard');
        Route::get('dashboard/export', WorkRecordsExportController::class)->name('dashboard.export');
    });

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::livewire('periods', 'pages::admin.period-manager')->name('periods');
        Route::livewire('workloads', 'pages::admin.workload-manager')->name('workloads');
    });
});

require __DIR__.'/auth.php';
