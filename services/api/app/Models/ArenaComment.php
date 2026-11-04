<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ArenaComment extends Model
{
    protected $fillable = [
        'match_arena_id',
        'user_id',
        'parent_comment_id',
        'section',
        'body',
        'moderation_state',
        'moderation_reason',
        'moderated_by_staff_id',
        'moderated_at',
    ];

    protected $casts = [
        'moderated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

