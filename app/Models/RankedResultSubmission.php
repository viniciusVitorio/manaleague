<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankedResultSubmission extends Model
{
    protected $fillable = ['ranked_match_id', 'submitted_by', 'score_one', 'score_two', 'status', 'reviewed_by', 'dispute_reason', 'resolution_reason', 'idempotency_key', 'ip_address', 'reviewed_at'];

    protected $hidden = ['ip_address'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'score_one' => 'integer', 'score_two' => 'integer'];
    }

    public function rankedMatch(): BelongsTo
    {
        return $this->belongsTo(RankedMatch::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
