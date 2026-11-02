<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportedRecord;
use App\Models\IntegrationConnection;
use App\Services\AuditLogger;
use App\Services\Integrations\InstantlySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function __construct(
        private InstantlySyncService $instantly,
        private AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        $connections = IntegrationConnection::with('workspace:id,name,code')
            ->orderBy('provider')->get()
            ->map(fn (IntegrationConnection $c) => $this->payload($c));

        return response()->json(['data' => $connections]);
    }

    /**
     * Store a provider API key (encrypted) for a workspace and verify it.
     * Key is write-only from the client's perspective — it is never returned.
     */
    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['instantly'])],
            'workspace_id' => ['required', 'uuid', 'exists:workspaces,id'],
            'api_key' => ['required', 'string', 'min:10'],
        ]);

        $conn = IntegrationConnection::firstOrNew([
            'provider' => $data['provider'],
            'workspace_id' => $data['workspace_id'],
        ]);
        $conn->credentials = $data['api_key'];   // encrypted at rest via cast
        $conn->status = 'not_configured';
        $conn->save();

        try {
            $ws = $this->instantly->verify($conn);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'connection' => $this->payload($conn->fresh())], 422);
        }

        $this->audit->log('integration.connected', $conn, ['provider' => $data['provider'], 'workspace_external_id' => $ws['id'] ?? null]);

        return response()->json([
            'message' => 'Connected to Instantly workspace "'.($ws['name'] ?? 'unknown').'".',
            'connection' => $this->payload($conn->fresh('workspace')),
        ]);
    }

    public function sync(IntegrationConnection $integration): JsonResponse
    {
        if ($integration->provider !== 'instantly') {
            return response()->json(['message' => 'Sync is only implemented for Instantly so far.'], 422);
        }

        try {
            $log = $this->instantly->sync($integration);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->log('integration.synced', $integration, ['records' => $log->records_processed]);

        return response()->json([
            'message' => $log->message,
            'log' => $log,
            'connection' => $this->payload($integration->fresh('workspace')),
        ]);
    }

    public function syncLogs(IntegrationConnection $integration): JsonResponse
    {
        return response()->json(['data' => $integration->syncLogs()->orderByDesc('started_at')->limit(50)->get()]);
    }

    private function payload(IntegrationConnection $c): array
    {
        $unmapped = $c->provider === 'instantly' && $c->workspace_id
            ? ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')->where('workspace_id', $c->workspace_id)->count()
            : null;

        return [
            'id' => $c->id,
            'provider' => $c->provider,
            'workspace' => $c->workspace?->name,
            'workspace_id' => $c->workspace_id,
            'status' => $c->status,
            'status_label' => $this->statusLabel($c->status),
            'last_success_at' => $c->last_success_at,
            'last_error' => $c->last_error,
            'observed' => $c->config['observed'] ?? null,
            'campaigns_count' => $c->config['campaigns_count'] ?? null,
            'workspace_name_remote' => $c->config['workspace_name'] ?? null,
            'imported_accounts' => $unmapped,
            // Never return the key itself.
            'has_credentials' => filled($c->credentials),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'connected' => 'Connected',
            'disconnected' => 'Disconnected',
            'permission_denied' => 'Permission denied',
            'sync_failed' => 'Sync failed',
            default => 'Not configured',
        };
    }
}
