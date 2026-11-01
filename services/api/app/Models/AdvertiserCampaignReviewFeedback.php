<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AdvertiserCampaignReviewFeedback extends Model
{
    public $timestamps = false;

    protected $table = 'advertiser_campaign_review_feedback';

    protected $fillable = [
        'advertiser_organization_id', 'advertiser_campaign_id', 'staff_member_id',
        'action', 'feedback', 'from_revision', 'to_revision', 'request_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'feedback' => 'encrypted',
            'from_revision' => 'integer',
            'to_revision' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
