<?php

use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\DailyActivityController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\PlacementTestController;
use App\Http\Controllers\Api\ProblemController;
use App\Http\Controllers\Api\RotationController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    // Session
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // --- Read endpoints (any authenticated role, including viewer) ---
    Route::get('/dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('/dashboard/batches', [DashboardController::class, 'batches']);

    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show']);

    Route::get('/batches', [BatchController::class, 'index']);
    Route::get('/batches/{batch}', [BatchController::class, 'show']);
    Route::get('/batches/{batch}/activity', [DailyActivityController::class, 'index']);

    Route::get('/problems', [ProblemController::class, 'index']);
    Route::get('/problems/{problem}', [ProblemController::class, 'show']);

    Route::get('/rotation/candidates', [RotationController::class, 'candidates']);
    Route::get('/rotation/plans', [RotationController::class, 'plans']);

    Route::get('/placement-tests', [PlacementTestController::class, 'index']);
    Route::get('/integrations', [IntegrationController::class, 'index']);
    Route::get('/integrations/{integration}/logs', [IntegrationController::class, 'syncLogs']);
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::get('/audit-logs', [AuditLogController::class, 'index']);

    Route::get('/approvals', [ApprovalController::class, 'index']);

    // --- Operational writes (super_admin, deliverability_manager, operator) ---
    Route::middleware('role:super_admin,deliverability_manager,operator')->group(function () {
        Route::post('/batches', [BatchController::class, 'store']);
        Route::patch('/batches/{batch}', [BatchController::class, 'update']);
        Route::post('/batches/{batch}/transition', [BatchController::class, 'transition']);
        Route::delete('/batches/{batch}', [BatchController::class, 'destroy']);

        Route::post('/batches/{batch}/activity', [DailyActivityController::class, 'store']);
        Route::post('/activity/import', [DailyActivityController::class, 'import']);

        Route::post('/batches/{batch}/detect', [ProblemController::class, 'detect']);
        Route::post('/problems', [ProblemController::class, 'store']);
        Route::patch('/problems/{problem}', [ProblemController::class, 'update']);

        Route::post('/rotation/plans', [RotationController::class, 'store']);
        Route::patch('/rotation/plans/{rotationPlan}', [RotationController::class, 'update']);

        Route::post('/placement-tests', [PlacementTestController::class, 'store']);

        // Any non-viewer can REQUEST an approval.
        Route::post('/approvals', [ApprovalController::class, 'store']);
        Route::post('/approvals/{approval}/execute', [ApprovalController::class, 'execute']);
    });

    // --- Decisions restricted to managers/admins ---
    Route::middleware('role:super_admin,deliverability_manager')->group(function () {
        Route::put('/settings', [SettingsController::class, 'update']);
        Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve']);
        Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject']);

        // Integration credentials + sync (read-only toward the provider).
        Route::post('/integrations/connect', [IntegrationController::class, 'connect']);
        Route::post('/integrations/{integration}/sync', [IntegrationController::class, 'sync']);
    });
});
