<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApplicationSetting extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** Fetch a setting value by key with a fallback default. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('key', $key)->first();

        return $row ? ($row->value['value'] ?? $row->value) : $default;
    }
}
