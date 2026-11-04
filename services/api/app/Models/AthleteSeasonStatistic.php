<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AthleteSeasonStatistic extends Model
{
    protected $fillable = [
        'athlete_id',
        'season_id',
        'team_id',
        'appearances',
        'minutes_played',
        'goals',
        'assists',
        'yellow_cards',
        'red_cards',
        'rating',
    ];

    protected $casts = [
        'appearances' => 'integer',
        'minutes_played' => 'integer',
        'goals' => 'integer',
        'assists' => 'integer',
        'yellow_cards' => 'integer',
        'red_cards' => 'integer',
        'rating' => 'float',
    ];

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(Athlete::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
