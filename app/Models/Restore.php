<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'backup_id', 'kind', 'source_archive', 'database', 'tmp_database', 'archive_name',
    'status', 'step', 'log', 'error_message', 'triggered_by', 'started_at', 'finished_at',
])]
class Restore extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['pending', 'running'], true);
    }

    /** Appends a timestamped line to the log and updates the current step in one write. */
    public function appendLog(string $step, string $message): void
    {
        $this->step = $step;
        $this->log = trim(($this->log ?? '')."\n[".Carbon::now()->format('H:i:s')."] {$step}: {$message}");
        $this->save();
    }
}
