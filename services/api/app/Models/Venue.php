<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Venue extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'city',
        'country_code',
        'capacity',
        'surface',
    ];

    protected $casts = [
        'capacity' => 'integer',
    ];

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }
}
