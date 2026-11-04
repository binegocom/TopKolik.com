<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserSavedItem extends Model
{
    protected $fillable = [
        'user_id',
        'item_type',
        'item_id',
        'notes',
    ];

    protected $casts = [
        'item_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

