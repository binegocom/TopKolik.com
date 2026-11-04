<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserFollow extends Model
{
    protected $fillable = [
        'user_id',
        'followable_type',
        'followable_id',
        'notify_match_start',
        'notify_goals',
    ];

    protected $casts = [
        'followable_id' => 'integer',
        'notify_match_start' => 'boolean',
        'notify_goals' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

