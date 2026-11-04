<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportedRecord;
use App\Models\IntegrationConnection;
use App\Services\AuditLogger;
use App\Services\DeliverabilityRefreshService;
use App\Services\Integrations\InstantlySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    public function __construct(
        private InstantlySyncService $instantly,
        private DeliverabilityRefreshService $refresh,
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
            // Record the failure so the card shows "Disconnected" + the reason,
            // instead of a misleading "Key stored".
            $conn->update(['status' => $this->instantly->statusFromError($e), 'last_error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage(), 'connection' => $this->payload($conn->fresh('workspace'))], 422);
        }

        $this->audit->log('integration.connected', $conn, ['provider' => $data['provider'], 'workspace_external_id' => $ws['id'] ?? null]);

        // Pull data straight away so a newly connected workspace isn't empty until the next hourly run.
        @set_time_limit(110);
        $message = 'Connected to Instantly workspace "'.($ws['name'] ?? 'unknown').'".';
        try {
            $result = $this->refresh->refresh($conn);
            $message .= ' '.$result['message'];
        } catch (\Throwable $e) {
            $message .= ' Initial sync failed: '.$e->getMessage();
        }

        return response()->json([
            'message' => $message,
            'connection' => $this->payload($conn->fresh('workspace')),
        ]);
    }

    public function sync(IntegrationConnection $integration): JsonResponse
    {
        if ($integration->provider !== 'instantly') {
            return response()->json(['message' => 'Sync is only implemented for Instantly so far.'], 422);
        }

        // Sync + mapping + 30-day analytics can exceed PHP's default 30s on big workspaces.
        @set_time_limit(110);

        try {
            $result = $this->refresh->refresh($integration);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'connection' => $this->payload($integration->fresh('workspace')),
            ], 422);
        }

        $this->audit->log('integration.synced', $integration, [
            'mapped' => $result['mapping']['mapped'] ?? 0,
            'removed' => $result['mapping']['removed'] ?? 0,
            'activity_rows' => is_int($result['activity']) ? $result['activity'] : null,
            'problems_created' => $result['problems']['created'] ?? 0,
            'problems_resolved' => $result['problems']['resolved'] ?? 0,
        ]);

        return response()->json([
            'message' => $result['message'],
            'connection' => $this->payload($integration->fresh('workspace')),
        ]);
    }

    public function syncLogs(IntegrationConnection $integration): JsonResponse
    {
        return response()->json(['data' => $integration->syncLogs()->orderByDesc('started_at')->limit(50)->get()]);
    }

    private function payload(IntegrationConnection $c): array
    {
        $imported = $unmapped = null;
        if ($c->provider === 'instantly' && $c->workspace_id) {
            $base = ImportedRecord::where('source', 'instantly')->where('entity_type', 'account')->where('workspace_id', $c->workspace_id);
            $imported = (clone $base)->count();
            $unmapped = (clone $base)->whereNull('batch_id')->count();
        }

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
            'imported_accounts' => $imported,
            'unmapped_accounts' => $unmapped,
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
