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
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\SharedCostController;
use App\Http\Controllers\CostRecoveryController;
use App\Http\Controllers\AllocationLabController as Lab;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\BudgetLineController;
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

    // payroll: every employee enters the LOE of the month
    Route::get('/my-loe', fn () => redirect()->route('dashboard'))->name('loe.mine');   // off for now: everybody is counted as present
    Route::post('/my-loe', [PayrollController::class, 'myLoeSave'])->name('loe.mine.save');
    // payroll: HR and finance
    Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::post('/payroll', [PayrollController::class, 'store'])->name('payroll.store')->middleware('can:payroll.manage');
    Route::get('/payroll/contracts', [PayrollController::class, 'contracts'])->name('payroll.contracts');
    Route::get('/payroll/contracts/template', [PayrollController::class, 'contractsTemplate'])->name('payroll.contracts.template');
    Route::middleware('can:payroll.manage')->group(function () {
        Route::post('/payroll/contracts', [PayrollController::class, 'storeContract'])->name('payroll.contracts.store');
        Route::post('/payroll/contracts/import', [PayrollController::class, 'importContracts'])->name('payroll.contracts.import');
        Route::put('/payroll/contracts/{contract}', [PayrollController::class, 'updateContract'])->name('payroll.contracts.update');
        Route::post('/payroll/contracts/{contract}/change', [PayrollController::class, 'changeContract'])->name('payroll.contracts.change');
        Route::delete('/payroll/contracts/{contract}', [PayrollController::class, 'destroyContract'])->name('payroll.contracts.destroy');
        Route::post('/payroll/loe/copy', [PayrollController::class, 'loeCopy'])->name('payroll.loe.copy');
        Route::post('/payroll/loe/import', [PayrollController::class, 'loeImport'])->name('payroll.loe.import');
        Route::get('/payroll/loe/{individual}', [PayrollController::class, 'loeEdit'])->name('payroll.loe.edit');
        Route::post('/payroll/loe/{individual}', [PayrollController::class, 'loeSave'])->name('payroll.loe.save');
    });
    Route::get('/payroll/loe', [PayrollController::class, 'loe'])->name('payroll.loe');
    Route::get('/payroll/loe-template', [PayrollController::class, 'loeTemplate'])->name('payroll.loe.template');
    Route::get('/payroll/{run}', [PayrollController::class, 'show'])->name('payroll.show');
    Route::get('/payroll/{run}/export/{kind}', [PayrollController::class, 'export'])->name('payroll.export');
    Route::middleware('can:payroll.manage')->group(function () {
        Route::put('/payroll/{run}', [PayrollController::class, 'update'])->name('payroll.update');
        Route::delete('/payroll/{run}', [PayrollController::class, 'destroy'])->name('payroll.destroy');
        Route::post('/payroll/{run}/refresh', [PayrollController::class, 'refresh'])->name('payroll.refresh');
        Route::put('/payroll/{run}/lines/{line}', [PayrollController::class, 'line'])->name('payroll.line');
        Route::post('/payroll/{run}/attendance', [PayrollController::class, 'attendance'])->name('payroll.attendance');
        Route::post('/payroll/{run}/allocate', [PayrollController::class, 'allocate'])->name('payroll.allocate');
    });
    Route::post('/payroll/{run}/approve', [PayrollController::class, 'approve'])->name('payroll.approve')->middleware('can:payroll.approve');
    Route::post('/payroll/{run}/reopen', [PayrollController::class, 'reopen'])->name('payroll.reopen')->middleware('can:payroll.approve');

    // allocation lab (test area)
    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
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
    Route::get('/allocation-lab', [Lab::class, 'index'])->name('lab.index');
    Route::get('/allocation-lab/candidates', [Lab::class, 'candidates'])->name('lab.candidates');
    Route::get('/allocation-lab/export', [Lab::class, 'export'])->name('lab.export');
    Route::post('/allocation-lab/lines', [Lab::class, 'addLines'])->name('lab.lines.add');
    Route::put('/allocation-lab/lines/{id}', [Lab::class, 'updateLine'])->name('lab.lines.update');
    Route::delete('/allocation-lab/lines/{id}', [Lab::class, 'deleteLine'])->name('lab.lines.delete');
    Route::post('/allocation-lab/staff', [Lab::class, 'addStaff'])->name('lab.staff.add');
    Route::put('/allocation-lab/staff/{id}', [Lab::class, 'updateStaff'])->name('lab.staff.update');
    Route::delete('/allocation-lab/staff/{id}', [Lab::class, 'deleteStaff'])->name('lab.staff.delete');
    Route::post('/allocation-lab/expenses', [Lab::class, 'addExpense'])->name('lab.expenses.add');
    Route::post('/allocation-lab/expenses/import', [Lab::class, 'importExpenses'])->name('lab.expenses.import');
    Route::delete('/allocation-lab/expenses/{id}', [Lab::class, 'deleteExpense'])->name('lab.expenses.delete');

    // cost recovery plan: pool, key, funding map, funding lines
    Route::get('/cost-recovery', [CostRecoveryController::class, 'index'])->name('recovery.index');
    Route::get('/cost-recovery/funding', [CostRecoveryController::class, 'funding'])->name('recovery.funding');
    Route::post('/cost-recovery/funding', [CostRecoveryController::class, 'storeFunding'])->name('recovery.funding.store');
    Route::post('/cost-recovery/suggestions', [CostRecoveryController::class, 'acceptSuggestions'])->name('recovery.suggestions');
    Route::post('/cost-recovery/funding/{line}/toggle', [CostRecoveryController::class, 'toggleFunding'])->name('recovery.funding.toggle');
    Route::delete('/cost-recovery/funding/{line}', [CostRecoveryController::class, 'destroyFunding'])->name('recovery.funding.destroy');
    Route::post('/cost-recovery/import', [CostRecoveryController::class, 'import'])->name('recovery.import');
    Route::get('/cost-recovery/template/{kind}', [CostRecoveryController::class, 'template'])->name('recovery.template')->where('kind', 'funding|opening');

    // shared costs (QuickBooks report of the month, allocated by the FTE of the payroll)
    Route::get('/shared-costs', [SharedCostController::class, 'index'])->name('costs.index');
    Route::get('/shared-costs/categories', [SharedCostController::class, 'categories'])->name('costs.categories');
    Route::middleware('can:costs.manage')->group(function () {
        Route::post('/shared-costs', [SharedCostController::class, 'store'])->name('costs.store');
        Route::post('/shared-costs/categories', [SharedCostController::class, 'saveCategory'])->name('costs.categories.store');
        Route::put('/shared-costs/categories/{category}', [SharedCostController::class, 'saveCategory'])->name('costs.categories.update');
        Route::post('/shared-costs/mapping', [SharedCostController::class, 'saveMapping'])->name('costs.mapping');
    });
    Route::get('/shared-costs/{run}', [SharedCostController::class, 'show'])->name('costs.show');
    Route::get('/shared-costs/{run}/export/{kind}', [SharedCostController::class, 'export'])->name('costs.export');
    Route::middleware('can:costs.manage')->group(function () {
        Route::put('/shared-costs/{run}', [SharedCostController::class, 'update'])->name('costs.update');
        Route::delete('/shared-costs/{run}', [SharedCostController::class, 'destroy'])->name('costs.destroy');
        Route::put('/shared-costs/{run}/lines/{line}', [SharedCostController::class, 'line'])->name('costs.line');
        Route::post('/shared-costs/{run}/allocate', [SharedCostController::class, 'allocate'])->name('costs.allocate');
    });
    Route::post('/shared-costs/{run}/approve', [SharedCostController::class, 'approve'])->name('costs.approve')->middleware('can:costs.approve');
    Route::post('/shared-costs/{run}/reopen', [SharedCostController::class, 'reopen'])->name('costs.reopen')->middleware('can:costs.approve');

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
