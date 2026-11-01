<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AdvertiserCampaign extends Model
{
    protected $attributes = ['status' => 'draft', 'revision' => 1];

    protected $fillable = ['advertiser_organization_id', 'title', 'body'];

    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'body' => 'encrypted',
            'revision' => 'integer',
        ];
    }
}
