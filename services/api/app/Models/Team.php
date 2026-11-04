<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

final class Team extends Model
{
    protected $fillable = [
        'sport_id',
        'slug',
        'name',
        'short_name',
        'logo_url',
        'venue_id',
        'primary_color',
        'secondary_color',
    ];

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function athletes(): HasMany
    {
        return $this->hasMany(Athlete::class, 'current_team_id');
    }
}
