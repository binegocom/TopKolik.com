<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SupportTicket extends Model
{
    protected $fillable = ['assigned_staff_id', 'subject', 'message', 'status'];

    protected function casts(): array
    {
        return ['subject' => 'encrypted', 'message' => 'encrypted'];
    }
}
