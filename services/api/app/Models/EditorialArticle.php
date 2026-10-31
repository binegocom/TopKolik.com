<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EditorialArticle extends Model
{
    protected $attributes = ['status' => 'draft', 'revision' => 1];

    protected $fillable = ['owner_staff_id', 'title', 'body'];

    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'body' => 'encrypted',
            'submitted_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }
}
