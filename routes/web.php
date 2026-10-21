<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdvanceController;
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
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', 'active', 'idle'])->group(function () {
    Route::get('/ping', fn () => response()->noContent())->name('ping');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/lookup/{source}', [LookupController::class, 'search'])->where('source', '[a-z_:]+')->name('lookup');

    // notifications
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/read', [AlertController::class, 'readAll'])->name('alerts.read');
    Route::get('/alerts/{alert}', [AlertController::class, 'open'])->name('alerts.open');

    // transaction numbers
    Route::get('/numbers', [ReservationController::class, 'index'])->name('reservations.index');
    Route::post('/numbers', [ReservationController::class, 'store'])->name('reservations.store')->middleware('can:numbers.reserve');
    Route::post('/numbers/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::put('/numbers/{reservation}', [ReservationController::class, 'update'])->name('reservations.update');
    Route::get('/numbers/template', [ReservationController::class, 'template'])->name('reservations.template');
    Route::post('/numbers/import', [ReservationController::class, 'import'])->name('reservations.import')->middleware('can:numbers.reserve');
    Route::get('/numbers/mine', [ReservationController::class, 'mine'])->name('reservations.mine');

    // forms / documents
    Route::get('/inbox', [DocumentController::class, 'inbox'])->name('documents.inbox');
    Route::get('/forms/{code}', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/forms/{code}/new', [DocumentController::class, 'create'])->name('documents.create');
    Route::post('/forms/{code}', [DocumentController::class, 'store'])->name('documents.store');
    Route::post('/forms/{code}/compute', [DocumentController::class, 'compute'])->name('documents.compute');
    Route::get('/documents/{doc}', [DocumentController::class, 'show'])->name('documents.show');
    Route::put('/documents/{doc}', [DocumentController::class, 'update'])->name('documents.update');
    Route::post('/documents/{doc}/number', [DocumentController::class, 'number'])->name('documents.number');
    Route::post('/documents/{doc}/submit', [DocumentController::class, 'submit'])->name('documents.submit');
    Route::post('/documents/{doc}/sign', [DocumentController::class, 'sign'])->name('documents.sign');
    Route::post('/documents/{doc}/return', [DocumentController::class, 'returnDoc'])->name('documents.return');
    Route::post('/documents/{doc}/reset', [DocumentController::class, 'reset'])->name('documents.reset');
    Route::post('/documents/{doc}/cancel', [DocumentController::class, 'cancel'])->name('documents.cancel');
    Route::post('/documents/{doc}/duplicate', [DocumentController::class, 'duplicate'])->name('documents.duplicate');
    Route::delete('/documents/{doc}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::post('/documents/{doc}/payment', [DocumentController::class, 'payment'])->name('documents.payment');
    Route::post('/documents/{doc}/payment-number', [DocumentController::class, 'paymentNumber'])->name('documents.payment-number');
    Route::get('/documents/{doc}/print/{report}', [DocumentController::class, 'print'])->name('documents.print');
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

    // advances: holder
    Route::get('/my-advances', [AdvanceController::class, 'mine'])->name('advances.mine');
    Route::post('/expenses', [AdvanceController::class, 'storeExpense'])->name('expenses.store');
    Route::put('/expenses/{expense}', [AdvanceController::class, 'updateExpense'])->name('expenses.update');
    Route::delete('/expenses/{expense}', [AdvanceController::class, 'destroyExpense'])->name('expenses.destroy');
    Route::post('/settlements', [AdvanceController::class, 'submitSettlement'])->name('settlements.store');
    Route::post('/advance-payments/{payment}/proof', [AdvanceController::class, 'proof'])->name('advance-payments.proof');
    // advances: finance
    Route::get('/advances', [AdvanceController::class, 'index'])->name('advances.index');
    Route::get('/advances/{advance}', [AdvanceController::class, 'show'])->name('advances.show');
    Route::post('/advances', [AdvanceController::class, 'store'])->name('advances.store');
    Route::put('/advances/{advance}', [AdvanceController::class, 'update'])->name('advances.update');
    Route::post('/advances/{advance}/payments', [AdvanceController::class, 'addPayments'])->name('advances.payments');
    Route::delete('/advance-payments/{payment}', [AdvanceController::class, 'removePayment'])->name('advance-payments.destroy');
    Route::get('/settlements/{settlement}', [AdvanceController::class, 'settlement'])->name('settlements.show');
    Route::post('/settlements/{settlement}/accept', [AdvanceController::class, 'accept'])->name('settlements.accept');
    Route::post('/settlements/{settlement}/return', [AdvanceController::class, 'returnSettlement'])->name('settlements.return');
    Route::post('/advance-holders', [AdvanceController::class, 'storeHolder'])->name('holders.store');
    Route::put('/advance-holders/{holder}', [AdvanceController::class, 'updateHolder'])->name('holders.update');

    // received payments
    // remittance statements (internal viewer) and their payment (treasury)
    Route::get('/remittances', [\App\Http\Controllers\RemittanceController::class, 'index'])->name('remittances.index')->middleware('can:remittance.prepare');
    Route::post('/remittances', [\App\Http\Controllers\RemittanceController::class, 'store'])->name('remittances.store')->middleware('can:remittance.prepare');
    Route::get('/payments', [\App\Http\Controllers\RemittanceController::class, 'payments'])->name('payments.index')->middleware('can:payments.pay');
    Route::post('/payments', [\App\Http\Controllers\RemittanceController::class, 'pay'])->name('payments.store')->middleware('can:payments.pay');

    Route::get('/receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::post('/receipts', [ReceiptController::class, 'store'])->name('receipts.store')->middleware('can:receipts.inform');
    Route::get('/receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::put('/receipts/{receipt}', [ReceiptController::class, 'update'])->name('receipts.update');
    Route::post('/receipts/{receipt}/project', [ReceiptController::class, 'project'])->name('receipts.project')->middleware('can:receipts.assign');
    Route::post('/receipts/{receipt}/record', [ReceiptController::class, 'record'])->name('receipts.record')->middleware('can:receipts.record');
    Route::post('/receipts/{receipt}/back', [ReceiptController::class, 'back'])->name('receipts.back');
    Route::post('/receipts/{receipt}/files', [ReceiptController::class, 'files'])->name('receipts.files');

    // reports
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index')->middleware('can:reports.view');
    Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show')->middleware('can:reports.view');

    // account
    Route::get('/account/signature', [AccountController::class, 'signature'])->name('account.signature');
    Route::post('/account/signature', [AccountController::class, 'saveSignature'])->name('account.signature.save');
    Route::post('/account/password', [AccountController::class, 'password'])->name('account.password');

    // administration
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('can:users.manage')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/new', [UserController::class, 'create'])->name('users.create');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::get('/users/{user}', [UserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/signature', [UserController::class, 'signature'])->name('users.signature');
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
