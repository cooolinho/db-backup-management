<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'database', 'format', 'path', 'size_bytes', 'sha256', 'on_local', 'on_s3',
    'source', 'status', 'error_message', 'triggered_by', 'started_at', 'finished_at',
])]
class Backup extends Model
{
    protected function casts(): array
    {
        return [
            'on_local' => 'boolean',
            'on_s3' => 'boolean',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['running', 'validating'], true);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }
}
