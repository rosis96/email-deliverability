<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\DailyActivity;
use App\Models\ImportedRecord;
use App\Services\AuditLogger;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyActivityController extends Controller
{
    public function __construct(private MetricsService $metrics, private AuditLogger $audit) {}

    public function index(Request $request, Batch $batch): JsonResponse
    {
        $rows = $batch->dailyActivity()->orderByDesc('activity_date')->limit(90)->get();

        return response()->json([
            'data' => $rows->map(fn (DailyActivity $a) => array_merge($a->toArray(), $this->metrics->deriveDaily($a))),
            'summary' => $this->metrics->summaryForBatch($batch),
        ]);
    }

    public function store(Request $request, Batch $batch): JsonResponse
    {
        $data = $this->validatePayload($request);
        $data['activity_date'] = $request->input('activity_date');

        $record = DailyActivity::updateOrCreate(
            ['batch_id' => $batch->id, 'activity_date' => $data['activity_date']],
            array_merge($data, ['workspace_id' => $batch->workspace_id, 'source' => 'manual']),
        );

        $this->audit->log('daily_activity.saved', $batch, ['date' => $data['activity_date']]);

        return response()->json(['data' => array_merge($record->toArray(), $this->metrics->deriveDaily($record))], 201);
    }

    /**
     * CSV import. Columns: batch_id,activity_date,active_accounts,connected_accounts,
     * sent,daily_limit_per_account,replies,positive_replies,bounces,hard_bounces,
     * soft_bounces,rejections,warmup_emails,campaign_status,notes
     * Enforces batch_id + date uniqueness via upsert and keeps raw rows for reconciliation.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Could not read the uploaded file.'], 422);
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return response()->json(['message' => 'The file is empty or has no header row.'], 422);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $processed = 0;
        $errors = [];
        $line = 1;

        DB::beginTransaction();
        try {
            while (($cols = fgetcsv($handle)) !== false) {
                $line++;
                if (count(array_filter($cols, fn ($c) => $c !== null && $c !== '')) === 0) {
                    continue; // skip blank line
                }
                $row = array_combine($header, array_pad($cols, count($header), null));

                $batch = Batch::where('batch_id', trim((string) ($row['batch_id'] ?? '')))->first();
                if (! $batch) {
                    $errors[] = "Line {$line}: unknown batch_id '".($row['batch_id'] ?? '')."'";
                    continue;
                }
                if (empty($row['activity_date'])) {
                    $errors[] = "Line {$line}: missing activity_date";
                    continue;
                }

                $payload = [
                    'workspace_id' => $batch->workspace_id,
                    'active_accounts' => $this->int($row['active_accounts'] ?? null),
                    'connected_accounts' => $this->int($row['connected_accounts'] ?? null),
                    'sent' => (int) ($row['sent'] ?? 0),
                    'daily_limit_per_account' => $this->int($row['daily_limit_per_account'] ?? null),
                    'replies' => (int) ($row['replies'] ?? 0),
                    'positive_replies' => (int) ($row['positive_replies'] ?? 0),
                    'bounces' => (int) ($row['bounces'] ?? 0),
                    'hard_bounces' => (int) ($row['hard_bounces'] ?? 0),
                    'soft_bounces' => (int) ($row['soft_bounces'] ?? 0),
                    'rejections' => (int) ($row['rejections'] ?? 0),
                    'warmup_emails' => $this->int($row['warmup_emails'] ?? null),
                    'campaign_status' => $row['campaign_status'] ?? null,
                    'notes' => $row['notes'] ?? null,
                    'source' => 'csv',
                ];

                DailyActivity::updateOrCreate(
                    ['batch_id' => $batch->id, 'activity_date' => $row['activity_date']],
                    $payload,
                );

                ImportedRecord::create([
                    'source' => 'csv',
                    'entity_type' => 'activity',
                    'workspace_id' => $batch->workspace_id,
                    'batch_id' => $batch->id,
                    'original_period' => $row['activity_date'],
                    'batch_mapping_confidence' => 'high',
                    'payload' => $row,
                    'imported_at' => now(),
                ]);

                $processed++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);

            return response()->json(['message' => 'Import failed: '.$e->getMessage()], 500);
        }

        fclose($handle);
        $this->audit->log('daily_activity.imported', null, ['processed' => $processed, 'errors' => count($errors)]);

        return response()->json(['processed' => $processed, 'errors' => $errors]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'activity_date' => ['required', 'date'],
            'active_accounts' => ['nullable', 'integer', 'min:0'],
            'connected_accounts' => ['nullable', 'integer', 'min:0'],
            'sent' => ['required', 'integer', 'min:0'],
            'daily_limit_per_account' => ['nullable', 'integer', 'min:0'],
            'replies' => ['nullable', 'integer', 'min:0'],
            'positive_replies' => ['nullable', 'integer', 'min:0'],
            'bounces' => ['nullable', 'integer', 'min:0'],
            'hard_bounces' => ['nullable', 'integer', 'min:0'],
            'soft_bounces' => ['nullable', 'integer', 'min:0'],
            'rejections' => ['nullable', 'integer', 'min:0'],
            'warmup_emails' => ['nullable', 'integer', 'min:0'],
            'campaign_status' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);
    }

    private function int($v): ?int
    {
        return ($v === null || $v === '') ? null : (int) $v;
    }
}
