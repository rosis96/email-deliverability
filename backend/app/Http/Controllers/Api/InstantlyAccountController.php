<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\Workspace;
use App\Services\Integrations\InstantlyAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Live Instantly Email Accounts management: list by tag, inspect, and edit
 * accounts with changes written straight to Instantly (gated + audit-logged).
 */
class InstantlyAccountController extends Controller
{
    public function __construct(private InstantlyAccountService $accounts) {}

    /** Workspaces that have an Instantly connection (for the workspace picker). */
    public function workspaces(): JsonResponse
    {
        $data = IntegrationConnection::with('workspace:id,name,code')
            ->where('provider', 'instantly')
            ->whereNotNull('workspace_id')
            ->get()
            ->map(fn (IntegrationConnection $c) => [
                'workspace_id' => $c->workspace_id,
                'name' => $c->workspace?->name,
                'code' => $c->workspace?->code,
                'status' => $c->status,
                'has_credentials' => filled($c->credentials),
            ])
            ->filter(fn ($w) => $w['has_credentials'])
            ->sortBy('name')
            ->values();

        return response()->json(['data' => $data]);
    }

    public function index(Workspace $workspace, Request $request): JsonResponse
    {
        return $this->guard(fn () => response()->json(
            $this->accounts->listAccounts($workspace, $request->only(['tag_ids', 'status', 'search', 'starting_after', 'limit'])),
        ));
    }

    public function tags(Workspace $workspace): JsonResponse
    {
        return $this->guard(fn () => response()->json(['data' => $this->accounts->tags($workspace)]));
    }

    public function show(Workspace $workspace, Request $request): JsonResponse
    {
        $email = $this->email($request);

        return $this->guard(fn () => response()->json(['data' => $this->accounts->detail($workspace, $email)]));
    }

    /** PATCH account settings + warmup. Writes directly to Instantly. */
    public function update(Workspace $workspace, Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'daily_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2000'],
            'sending_gap' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1440'],
            'enable_slow_ramp' => ['sometimes', 'boolean'],
            'reply_to' => ['sometimes', 'nullable', 'email'],
            'inbox_placement_test_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'tracking_domain_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'warmup_limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'warmup_increment' => ['sometimes', 'nullable', Rule::in(['disabled', '0', '1', '2', '3', '4'])],
            'warmup_reply_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $email = $data['email'];
        unset($data['email']);

        // Build the Instantly PATCH body: flat settings pass through; the three
        // warmup fields fold into a nested warmup object.
        $body = [];
        foreach (['daily_limit', 'sending_gap', 'enable_slow_ramp', 'reply_to', 'inbox_placement_test_limit', 'tracking_domain_name', 'first_name', 'last_name'] as $f) {
            if (array_key_exists($f, $data)) {
                $body[$f] = $data[$f];
            }
        }
        $warmup = [];
        if (array_key_exists('warmup_limit', $data)) {
            $warmup['limit'] = $data['warmup_limit'];
        }
        if (array_key_exists('warmup_increment', $data)) {
            $warmup['increment'] = $data['warmup_increment'];
        }
        if (array_key_exists('warmup_reply_rate', $data)) {
            $warmup['reply_rate'] = $data['warmup_reply_rate'];
        }
        if ($warmup) {
            $body['warmup'] = $warmup;
        }

        if (! $body) {
            return response()->json(['message' => 'No changes to save.'], 422);
        }

        return $this->guard(fn () => response()->json([
            'message' => 'Saved to Instantly.',
            'data' => $this->accounts->update($workspace, $email, $body),
        ]));
    }

    public function pause(Workspace $workspace, Request $request): JsonResponse
    {
        $email = $this->email($request);

        return $this->guard(fn () => response()->json([
            'message' => 'Account paused on Instantly.',
            'data' => $this->accounts->pause($workspace, $email),
        ]));
    }

    public function resume(Workspace $workspace, Request $request): JsonResponse
    {
        $email = $this->email($request);

        return $this->guard(fn () => response()->json([
            'message' => 'Account resumed on Instantly.',
            'data' => $this->accounts->resume($workspace, $email),
        ]));
    }

    public function warmup(Workspace $workspace, Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'enabled' => ['required', 'boolean'],
        ]);

        return $this->guard(fn () => response()->json([
            'message' => $data['enabled']
                ? 'Warmup enable queued on Instantly (processed asynchronously).'
                : 'Warmup disable queued on Instantly (processed asynchronously).',
            'data' => $this->accounts->setWarmup($workspace, $data['email'], (bool) $data['enabled']),
        ]));
    }

    private function email(Request $request): string
    {
        return (string) $request->validate(['email' => ['required', 'email']])['email'];
    }

    /**
     * Run an action and translate Instantly client failures into clean HTTP
     * responses: 403 for a scope problem, 422 for everything else.
     */
    private function guard(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            $status = str_contains($e->getMessage(), '(403)') ? 403 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        }
    }
}
