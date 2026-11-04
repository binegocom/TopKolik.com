<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Fixture extends Model
{
    protected $fillable = [
        'competition_id',
        'season_id',
        'home_team_id',
        'away_team_id',
        'status',
        'starts_at',
        'home_score',
        'away_score',
        'first_scoring_team',
        'source_revision',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'home_score' => 'integer',
        'away_score' => 'integer',
        'source_revision' => 'integer',
    ];

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }
}
