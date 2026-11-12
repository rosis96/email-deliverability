<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailboxOperation;
use App\Models\Workspace;
use App\Services\Integrations\MailboxBulkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/** Mailbox health overview (all workspaces x tags) and bulk edits to Instantly. */
class MailboxController extends Controller
{
    public function __construct(private MailboxBulkService $service) {}

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->service->summary(), 'actions' => MailboxBulkService::ACTIONS]);
    }

    public function operations(Workspace $workspace): JsonResponse
    {
        return response()->json(['data' => MailboxOperation::where('workspace_id', $workspace->id)
            ->with('user:id,name')->orderByDesc('created_at')->limit(20)->get()]);
    }

    public function operation(MailboxOperation $operation): JsonResponse
    {
        return response()->json(['data' => $operation->load('user:id,name')]);
    }

    /**
     * Bulk edit. scope=emails (explicit selection) or scope=filter (everything
     * matching tag/status/search in Instantly, across all pages).
     * dry_run=true only resolves and counts the mailboxes.
     */
    public function bulk(Request $request, Workspace $workspace): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(array_keys(MailboxBulkService::ACTIONS))],
            'value' => ['nullable'],
            'scope' => ['required', Rule::in(['emails', 'filter'])],
            'emails' => ['required_if:scope,emails', 'array', 'max:5000'],
            'emails.*' => ['email'],
            'filter' => ['nullable', 'array'],
            'filter.tag_ids' => ['nullable', 'string'],
            'filter.status' => ['nullable'],
            'filter.search' => ['nullable', 'string'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        $needsValue = ! in_array($data['action'], ['warmup_on', 'warmup_off', 'pause', 'resume'], true);
        if ($needsValue && ($data['value'] ?? '') === '') {
            return response()->json(['message' => 'Enter a value for this action.'], 422);
        }
        if (in_array($data['action'], ['daily_limit', 'sending_gap', 'warmup_limit', 'warmup_reply_rate'], true)
            && (! is_numeric($data['value']) || $data['value'] < 0)) {
            return response()->json(['message' => 'Value must be a non-negative number.'], 422);
        }
        if ($data['action'] === 'warmup_increment' && ! in_array((string) $data['value'], ['disabled', '0', '1', '2', '3', '4'], true)) {
            return response()->json(['message' => 'Warmup increase must be disabled or 0–4.'], 422);
        }

        try {
            $emails = $this->service->resolveEmails($workspace, $data);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        if (! $emails) {
            return response()->json(['message' => 'No mailboxes match this selection.'], 422);
        }

        if ($request->boolean('dry_run')) {
            return response()->json(['data' => ['count' => count($emails), 'sample' => array_slice($emails, 0, 5)]]);
        }

        $op = $this->service->queue($workspace, $data['action'], ['value' => $data['value'] ?? null, 'scope' => $data['scope'], 'filter' => $data['filter'] ?? null], $emails, $request->user()?->id);

        $service = $this->service;
        $id = $op->id;
        app()->terminating(function () use ($service, $id) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            $op = MailboxOperation::find($id);
            if ($op && $op->status === 'queued') {
                $service->run($op);
            }
        });

        return response()->json([
            'message' => MailboxBulkService::ACTIONS[$data['action']].' — started for '.count($emails).' mailbox(es).',
            'data' => $op,
        ], 202);
    }
}
