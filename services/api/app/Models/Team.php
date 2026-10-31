<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Team extends Model
{
    protected $fillable = ['sport_id', 'slug', 'name'];

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }
}
