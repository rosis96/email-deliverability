<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Operator and above may register batches; viewers may not.
        return $this->user() && $this->user()->hasRole('super_admin', 'deliverability_manager', 'operator');
    }

    public function rules(): array
    {
        return [
            // Permanent human identifier WORKSPACE-CODE-TYPE-NUMBER, e.g. NE-SMTP-01.
            'batch_id' => ['required', 'string', 'max:64', 'unique:batches,batch_id', 'regex:/^[A-Z0-9]+-[A-Z0-9]+-\d{2,}$/'],
            'workspace_id' => ['required', 'uuid', 'exists:workspaces,id'],
            'provider_type' => ['required', Rule::in(['smtp', 'smtp_maildoso', 'google_dfy_gen2', 'other'])],
            'domain_family' => ['nullable', 'string', 'max:255'],
            'planned_account_count' => ['required', 'integer', 'min:1'],
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => ['string', 'max:255', 'distinct'],
            'warmup_start' => ['required', 'date'],
            'active_daily_limit' => ['required', 'integer', 'min:1'],
            // Editable defaults (21 / 90).
            'warmup_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'lifecycle_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'batch_owner' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'batch_id.regex' => 'Batch ID must follow WORKSPACE-TYPE-NUMBER (e.g. NE-SMTP-01).',
            'batch_id.unique' => 'This batch ID already exists and can never be reused.',
        ];
    }
}
