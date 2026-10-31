<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SupportTicket extends Model
{
    protected $fillable = ['assigned_staff_id', 'subject', 'message', 'status'];

    public function replies(): HasMany
    {
        return $this->hasMany(SupportTicketReply::class, 'support_ticket_id');
    }

    protected function casts(): array
    {
        return [
            'subject' => 'encrypted',
            'message' => 'encrypted',
            'escalated_at' => 'immutable_datetime',
        ];
    }
}
