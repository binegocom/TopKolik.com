<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AdvertiserOrganization extends Model
{
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
