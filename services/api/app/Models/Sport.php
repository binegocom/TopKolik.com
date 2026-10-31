<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Sport extends Model
{
    protected $fillable = ['slug', 'name'];

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }
}
