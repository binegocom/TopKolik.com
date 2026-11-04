<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Athlete extends Model
{
    protected $fillable = [
        'sport_id',
        'current_team_id',
        'slug',
        'name',
        'first_name',
        'last_name',
        'nationality',
        'country_code',
        'position',
        'shirt_number',
        'birth_date',
        'height_cm',
        'weight_kg',
        'preferred_foot',
        'photo_url',
        'is_active',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'shirt_number' => 'integer',
        'height_cm' => 'integer',
        'weight_kg' => 'integer',
        'is_active' => 'boolean',
    ];

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function seasonStatistics(): HasMany
    {
        return $this->hasMany(AthleteSeasonStatistic::class);
    }
}
