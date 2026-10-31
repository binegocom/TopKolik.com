<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReaderPreference extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'preferred_locale',
        'personalized_news_enabled',
        'analytics_consent',
    ];

    protected function casts(): array
    {
        return [
            'personalized_news_enabled' => 'boolean',
            'analytics_consent' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
