<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\FormsController;
use App\Http\Controllers\Admin\MasterController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\BudgetLineController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::get('/login/code', [AuthController::class, 'twoFactor'])->name('login.2fa');
Route::post('/login/code', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:20,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', 'active', 'idle', 'twofactor'])->group(function () {
    Route::get('/ping', fn () => response()->noContent())->name('ping');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/lookup/{source}', [LookupController::class, 'search'])->where('source', '[a-z_:]+')->name('lookup');
    Route::post('/lookup/{source}/resolve', [LookupController::class, 'resolve'])->where('source', '[a-z_:]+')->name('lookup.resolve');

    // notifications
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/read', [AlertController::class, 'readAll'])->name('alerts.read');
    Route::get('/alerts/{alert}', [AlertController::class, 'open'])->name('alerts.open');

    // transaction numbers
    Route::get('/numbers', [ReservationController::class, 'index'])->name('reservations.index');
    Route::post('/numbers', [ReservationController::class, 'store'])->name('reservations.store')->middleware('can:numbers.reserve');
    Route::post('/numbers/{reservation}/cancel', [ReservationController::class, 'cancel'])->whereNumber('reservation')->name('reservations.cancel');
    Route::put('/numbers/{reservation}', [ReservationController::class, 'update'])->whereNumber('reservation')->name('reservations.update');
    Route::get('/numbers/template', [ReservationController::class, 'template'])->name('reservations.template');
    Route::post('/numbers/import', [ReservationController::class, 'import'])->name('reservations.import')->middleware('can:numbers.reserve');
    Route::get('/numbers/old-template', [ReservationController::class, 'oldTemplate'])->name('reservations.old-template')->middleware('can:numbers.manage');
    Route::post('/numbers/import-old', [ReservationController::class, 'importOld'])->name('reservations.import-old')->middleware('can:numbers.manage');
    Route::get('/numbers/mine', [ReservationController::class, 'mine'])->name('reservations.mine');
    Route::get('/numbers/{reservation}/edit', [ReservationController::class, 'edit'])->whereNumber('reservation')->name('reservations.edit');
    Route::get('/numbers/export', [ReservationController::class, 'export'])->name('reservations.export');
    Route::get('/numbers/types', [ReservationController::class, 'types'])->name('reservations.types')->middleware('can:numbers.manage');
    Route::put('/numbers/types', [ReservationController::class, 'saveTypes'])->name('reservations.types.save')->middleware('can:numbers.manage');
    Route::delete('/numbers/{reservation}', [ReservationController::class, 'destroy'])->whereNumber('reservation')->name('reservations.destroy')->middleware('can:numbers.manage');

    // forms / documents
    Route::get('/inbox', [DocumentController::class, 'inbox'])->name('documents.inbox');
    Route::get('/forms/{code}', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/forms/{code}/new', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('/forms/{code}', [DocumentController::class, 'store'])->name('documents.store');
    Route::post('/forms/{code}/compute', [DocumentController::class, 'compute'])->name('documents.compute');
    Route::get('/documents/{doc}', [DocumentController::class, 'show'])->name('documents.show');
    Route::put('/documents/{doc}', [DocumentController::class, 'update'])->name('documents.update');
    Route::post('/documents/{doc}/number', [DocumentController::class, 'number'])->name('documents.number');
    Route::post('/documents/{doc}/cancel', [DocumentController::class, 'cancel'])->name('documents.cancel');
    Route::post('/documents/{doc}/duplicate', [DocumentController::class, 'duplicate'])->name('documents.duplicate');
    Route::delete('/documents/{doc}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::get('/documents/{doc}/print', [DocumentController::class, 'printAll'])->name('documents.print-all');
    Route::get('/documents/{doc}/print/{report}', [DocumentController::class, 'print'])->name('documents.print');
    Route::post('/documents/{doc}/uploader/email', [DocumentController::class, 'uploaderEmail'])->name('documents.uploader.email');
    Route::post('/documents/{doc}/treasury', [DocumentController::class, 'treasury'])->name('documents.treasury');
    Route::post('/documents/{doc}/unlock', [DocumentController::class, 'unlock'])->name('documents.unlock');
    Route::post('/documents/{doc}/lock', [DocumentController::class, 'lock'])->name('documents.lock');
    Route::get('/documents/{doc}/final', [DocumentController::class, 'finalFile'])->name('documents.final');
    Route::get('/documents/{doc}/uploader', [DocumentController::class, 'uploader'])->name('documents.uploader');
    Route::post('/documents/{doc}/attachments', [DocumentController::class, 'attach'])->name('documents.attach');
    Route::get('/attachments/{attachment}', [DocumentController::class, 'download'])->name('attachments.download');
    Route::delete('/attachments/{attachment}', [DocumentController::class, 'detach'])->name('attachments.destroy');

    // internal review
    Route::get('/review', [ReviewController::class, 'index'])->name('review.index')->middleware('can:review.access');
    Route::post('/documents/{doc}/notes', [ReviewController::class, 'store'])->name('review.store');
    Route::post('/notes/{note}/fix', [ReviewController::class, 'fix'])->name('review.fix');
    Route::post('/notes/{note}/close', [ReviewController::class, 'close'])->name('review.close');
    Route::post('/notes/{note}/reopen', [ReviewController::class, 'reopen'])->name('review.reopen');

    // received payments
    Route::get('/receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::post('/receipts', [ReceiptController::class, 'store'])->name('receipts.store')->middleware('can:receipts.inform');
    Route::get('/receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::put('/receipts/{receipt}', [ReceiptController::class, 'update'])->name('receipts.update');
    Route::post('/receipts/{receipt}/project', [ReceiptController::class, 'project'])->name('receipts.project')->middleware('can:receipts.assign');
    Route::post('/receipts/{receipt}/record', [ReceiptController::class, 'record'])->name('receipts.record')->middleware('can:receipts.record');
    Route::post('/receipts/{receipt}/back', [ReceiptController::class, 'back'])->name('receipts.back');
    Route::post('/receipts/{receipt}/files', [ReceiptController::class, 'files'])->name('receipts.files');
    Route::put('/receipts/{receipt}/manage', [ReceiptController::class, 'manage'])->name('receipts.manage')->middleware('can:receipts.manage');
    Route::delete('/receipts/{receipt}', [ReceiptController::class, 'destroy'])->name('receipts.destroy')->middleware('can:receipts.manage');

    // projects and budget lines
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/new', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{id}/edit', [ProjectController::class, 'edit'])->whereNumber('id')->name('projects.edit');
    Route::put('/projects/{id}', [ProjectController::class, 'update'])->whereNumber('id')->name('projects.update');
    Route::post('/projects/{id}/extend', [ProjectController::class, 'extend'])->whereNumber('id')->name('projects.extend');
    Route::delete('/projects/{id}/amendments/{amendment}', [ProjectController::class, 'destroyAmendment'])->whereNumber('id')->name('projects.amendments.destroy');
    Route::post('/projects/{id}/lines', [ProjectController::class, 'addLines'])->whereNumber('id')->name('projects.lines.add');
    Route::get('/projects/{id}/quickbooks', [ProjectController::class, 'quickbooks'])->whereNumber('id')->name('projects.qb');
    Route::post('/projects/{id}/quickbooks', [ProjectController::class, 'confirmQuickbooks'])->whereNumber('id')->name('projects.qb.confirm');
    Route::put('/projects', [ProjectController::class, 'bulk'])->name('projects.bulk');
    Route::get('/projects/export', [ProjectController::class, 'exportList'])->name('projects.list.export');
    Route::post('/projects/import', [ProjectController::class, 'importList'])->name('projects.list.import');
    Route::get('/budget-lines', [BudgetLineController::class, 'index'])->name('lines.index');
    Route::put('/budget-lines', [BudgetLineController::class, 'save'])->name('lines.save');
    Route::get('/budget-lines/export', [BudgetLineController::class, 'export'])->name('lines.export');
    Route::post('/budget-lines/import', [BudgetLineController::class, 'import'])->name('lines.import');
    Route::get('/projects/{id}', [ProjectController::class, 'show'])->whereNumber('id')->name('projects.show');
    Route::put('/projects/{id}/lines', [ProjectController::class, 'saveLines'])->name('projects.lines.save');
    Route::get('/projects/{id}/export', [ProjectController::class, 'export'])->name('projects.export');
    Route::post('/projects/{id}/import', [ProjectController::class, 'import'])->name('projects.import');
    // reports
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index')->middleware('can:reports.view');
    Route::get('/reports/budget-lines', [ReportController::class, 'lines'])->name('reports.lines')->middleware('can:reports.view');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show')->middleware('can:reports.view');

    // account
    Route::get('/account', [AccountController::class, 'index'])->name('account');
    Route::post('/account/two-factor', [AccountController::class, 'startTwoFactor'])->name('account.2fa.start');
    Route::post('/account/two-factor/confirm', [AccountController::class, 'confirmTwoFactor'])->name('account.2fa.confirm');
    Route::post('/account/two-factor/codes', [AccountController::class, 'recoveryCodes'])->name('account.2fa.codes');
    Route::delete('/account/two-factor', [AccountController::class, 'disableTwoFactor'])->name('account.2fa.disable');
    Route::post('/account/password', [AccountController::class, 'password'])->name('account.password');

    // administration
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('can:users.manage')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/new', [UserController::class, 'create'])->name('users.create');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}', [UserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/two-factor-reset', [UserController::class, 'resetTwoFactor'])->name('users.2fa.reset');
            Route::get('/audit', [\App\Http\Controllers\Admin\AuditController::class, 'index'])->name('audit.index');
        });
        Route::middleware('can:master.currencies.manage')->group(function () {
            Route::get('/rates', [\App\Http\Controllers\Admin\RateController::class, 'index'])->name('rates.index');
            Route::post('/rates', [\App\Http\Controllers\Admin\RateController::class, 'store'])->name('rates.store');
            Route::delete('/rates/{rate}', [\App\Http\Controllers\Admin\RateController::class, 'destroy'])->name('rates.destroy');
        });
        Route::middleware('can:roles.manage')->group(function () {
            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('/roles/new', [RoleController::class, 'create'])->name('roles.create');
            Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
            Route::get('/roles/{role}', [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        Route::get('/master', [MasterController::class, 'home'])->name('master.home');
        Route::get('/master/{entity}', [MasterController::class, 'index'])->name('master.index');
        Route::get('/master/{entity}/new', [MasterController::class, 'create'])->name('master.create');
        Route::post('/master/{entity}', [MasterController::class, 'store'])->name('master.store');
        Route::get('/master/{entity}/{id}', [MasterController::class, 'edit'])->name('master.edit');
        Route::put('/master/{entity}/{id}', [MasterController::class, 'update'])->name('master.update');
        Route::delete('/master/{entity}/{id}', [MasterController::class, 'destroy'])->name('master.destroy');

        Route::middleware('can:forms.design')->group(function () {
            Route::get('/forms', [FormsController::class, 'index'])->name('forms.index');
            Route::post('/forms', [FormsController::class, 'store'])->name('forms.store');
            Route::post('/forms/import', [FormsController::class, 'import'])->name('forms.import');
            Route::post('/forms/check', [FormsController::class, 'check'])->name('forms.check');
            Route::get('/forms/{type}', [FormsController::class, 'show'])->name('forms.show');
            Route::put('/forms/{type}', [FormsController::class, 'update'])->name('forms.update');
            Route::delete('/forms/{type}', [FormsController::class, 'destroy'])->name('forms.destroy');
            Route::put('/forms/{type}/schema', [FormsController::class, 'saveSchema'])->name('forms.schema');
            Route::put('/forms/{type}/roles', [FormsController::class, 'roles'])->name('forms.roles');
            Route::post('/forms/{type}/duplicate', [FormsController::class, 'duplicate'])->name('forms.duplicate');
            Route::get('/forms/{type}/export', [FormsController::class, 'export'])->name('forms.export');
            Route::post('/forms/{type}/versions/{version}/restore', [FormsController::class, 'restore'])->name('forms.restore');
            Route::post('/forms/{type}/prints', [FormsController::class, 'addPrint'])->name('forms.prints.store');
            Route::get('/forms/{type}/preview/{report}', [FormsController::class, 'preview'])->name('forms.preview');
            Route::get('/printouts/{print}', [FormsController::class, 'print'])->name('forms.print');
            Route::put('/printouts/{print}', [FormsController::class, 'savePrint'])->name('forms.print.save');
            Route::delete('/printouts/{print}', [FormsController::class, 'destroyPrint'])->name('forms.print.destroy');
            Route::get('/form-numbers', [FormsController::class, 'numbers'])->name('forms.numbers');
            Route::put('/form-numbers', [FormsController::class, 'saveNumbers'])->name('forms.numbers.save');
        });

        Route::get('/settings', [SettingsController::class, 'index'])->name('settings')->middleware('can:settings.manage');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update')->middleware('can:settings.manage');
    });
});
