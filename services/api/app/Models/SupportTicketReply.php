<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportTicketReply extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'support_ticket_id',
        'staff_member_id',
        'idempotency_key',
        'message_fingerprint',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'message' => 'encrypted',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
