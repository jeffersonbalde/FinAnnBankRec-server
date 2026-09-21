<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BankAccountController;
use App\Http\Controllers\Api\V1\BrsController;
use App\Http\Controllers\Api\V1\CheckIssuanceController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\ImportBatchController;
use App\Http\Controllers\Api\V1\MatchController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OutstandingCheckController;
use App\Http\Controllers\Api\V1\ReconciliationController;
use App\Http\Controllers\Api\V1\ReconcilingItemController;
use App\Http\Controllers\Api\V1\ReferenceUacsController;
use App\Http\Controllers\Api\V1\SignatoryController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('me/password', [AuthController::class, 'changePassword']);
        Route::post('logout', [AuthController::class, 'logout']);

        // Notifications (all roles)
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        // Dashboard & registers (all roles)
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('outstanding-checks', [OutstandingCheckController::class, 'index']);

        // Workflow transitions (role enforced inside WorkflowService)
        Route::post('reconciliations/{reconciliation}/submit', [WorkflowController::class, 'submit']);
        Route::post('reconciliations/{reconciliation}/certify', [WorkflowController::class, 'certify']);
        Route::post('reconciliations/{reconciliation}/return', [WorkflowController::class, 'returnForRevision']);
        Route::post('reconciliations/{reconciliation}/roll-forward', [WorkflowController::class, 'rollForward']);

        /*
        |------------------------------------------------------------------
        | Reconciliations — read access for the reviewer + disbursing officer
        |------------------------------------------------------------------
        */
        Route::middleware('role:financial_analyst,admin,budget_officer,disbursing_officer')->group(function (): void {
            Route::get('reconciliations', [ReconciliationController::class, 'index']);
            Route::get('reconciliations/{reconciliation}', [ReconciliationController::class, 'show']);
            Route::get('reconciliations/{reconciliation}/brs', [BrsController::class, 'show']);
            Route::get('reconciliations/{reconciliation}/matches', [MatchController::class, 'index']);
            Route::get('reconciliations/{reconciliation}/reconciling-items', [ReconcilingItemController::class, 'index']);

            Route::get('reconciliations/{reconciliation}/export/brs.xlsx', [ExportController::class, 'brsXlsx']);
            Route::get('reconciliations/{reconciliation}/export/schedule-1.xlsx', [ExportController::class, 'scheduleXlsx']);
            Route::get('reconciliations/{reconciliation}/export/brs.pdf', [ExportController::class, 'brsPdf']);
        });

        /*
        |------------------------------------------------------------------
        | Disbursing Officer — owns the Report of Checks Issued + cancelled checks
        | (import file-type is enforced inside ImportBatchController)
        |------------------------------------------------------------------
        */
        Route::middleware('role:financial_analyst,admin,disbursing_officer')->group(function (): void {
            Route::post('reconciliations/{reconciliation}/imports', [ImportBatchController::class, 'store']);
            Route::post('imports/{importBatch}/commit', [ImportBatchController::class, 'commit']);
            Route::delete('imports/{importBatch}', [ImportBatchController::class, 'destroy']);

            Route::post('check-issuances/{checkIssuance}/cancel', [CheckIssuanceController::class, 'cancel']);
        });

        /*
        |------------------------------------------------------------------
        | Reconciliation preparation & engine — Financial Analyst + Admin
        |------------------------------------------------------------------
        */
        Route::middleware('role:financial_analyst,admin')->group(function (): void {
            Route::apiResource('reconciliations', ReconciliationController::class)->except(['index', 'show']);

            Route::post('reconciliations/{reconciliation}/match', [MatchController::class, 'run']);
            Route::post('matches/{bankTransaction}/link', [MatchController::class, 'link']);
            Route::post('matches/{bankTransaction}/unlink', [MatchController::class, 'unlink']);

            Route::post('reconciliations/{reconciliation}/reconciling-items', [ReconcilingItemController::class, 'store']);
            Route::put('reconciling-items/{reconcilingItem}', [ReconcilingItemController::class, 'update']);
            Route::delete('reconciling-items/{reconcilingItem}', [ReconcilingItemController::class, 'destroy']);
            Route::post('reconciliations/{reconciliation}/regenerate-items', [ReconcilingItemController::class, 'regenerate']);

            // Read-only bank account list — needed to populate the "new reconciliation" form.
            Route::get('bank-accounts', [BankAccountController::class, 'index']);
        });

        /*
        |------------------------------------------------------------------
        | Master data — Administrator only
        |------------------------------------------------------------------
        */
        Route::middleware('role:admin')->group(function (): void {
            Route::get('audit-logs', [AuditLogController::class, 'index']);

            Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive']);
            Route::apiResource('users', UserController::class);

            Route::apiResource('bank-accounts', BankAccountController::class)->except(['index']);
            Route::apiResource('bank-accounts.signatories', SignatoryController::class)
                ->shallow()
                ->except(['show']);

            Route::apiResource('reference-uacs', ReferenceUacsController::class)
                ->parameters(['reference-uacs' => 'referenceUac']);

            Route::get('system/status', [SystemController::class, 'status']);
            Route::get('system/backups', [SystemController::class, 'indexBackups']);
            Route::post('system/backups', [SystemController::class, 'createBackup']);
            Route::get('system/backups/{filename}', [SystemController::class, 'downloadBackup'])
                ->where('filename', 'finann-backup-\d{8}-\d{6}\.(sql|json)');
            Route::delete('system/backups/{filename}', [SystemController::class, 'destroyBackup'])
                ->where('filename', 'finann-backup-\d{8}-\d{6}\.(sql|json)');
            Route::get('system/backup', [SystemController::class, 'downloadFreshBackup']);
            Route::get('system/backup-schedule', [SystemController::class, 'showSchedule']);
            Route::put('system/backup-schedule', [SystemController::class, 'updateSchedule']);
            Route::delete('system/activity-data', [SystemController::class, 'clearActivityData']);
            Route::put('system/password', [SystemController::class, 'changePassword']);
        });
    });
});
