<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Models\StaffMember;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffAuthorizationPolicy
{
    /**
     * Staff session permissions are not role privileges. Check both the
     * persisted staff identity and the current, scoped token.
     *
     * No publish, billing mutation, advertiser access, staff grant or
     * role-management permission exists until persisted resource policies
     * and dual approval gates are implemented.
     */
    public function allows(
        Authenticatable $principal,
        string $action,
        StaffResourceContext $resource,
        ?PersonalAccessToken $token,
    ): bool {
        if (! $principal instanceof StaffMember || $resource->id < 1
            || ! $token || $token->expires_at?->isPast()
            || ! $token->can('staff:session')
            || $token->tokenable_type !== $principal->getMorphClass()
            || (string) $token->tokenable_id !== (string) $principal->getKey()) {
            return false;
        }

        // A long-lived PHP process must not preserve stale grants after a
        // role change or operator deactivation.
        $staff = StaffMember::query()->find($principal->getKey());
        if (! $staff || ! $staff->is_active
            || ! $staff->mfa_enrolled_at
            || ! $staff->email_verified_at) {
            return false;
        }

        if ($staff->role === 'editor' && $resource->advertiserId === null
            && $resource->kind === 'editorial_article') {
            if ($resource->ownerStaffId !== (int) $staff->getKey()) {
                return false;
            }

            return match ($action) {
                'editorial.draft.view' =>
                    in_array($resource->state, ['draft', 'in_review', 'held'], true),
                'editorial.draft.edit',
                'editorial.draft.submit' => $resource->state === 'draft',
                'editorial.correction.propose' =>
                    $resource->state === 'published' && $token->can('staff:step-up'),
                default => false,
            };
        }

        if ($staff->role === 'support' && $resource->advertiserId === null
            && $resource->kind === 'support_ticket') {
            if ($resource->assignedStaffId !== (int) $staff->getKey()) {
                return false;
            }

            return match ($action) {
                'support.ticket.view' =>
                    in_array($resource->state, ['open', 'pending', 'resolved'], true),
                'support.ticket.reply' =>
                    in_array($resource->state, ['open', 'pending'], true),
                'support.ticket.escalate' =>
                    $resource->state === 'open' && $token->can('staff:step-up'),
                'support.ticket.resolve' =>
                    $resource->state === 'pending' && $token->can('staff:step-up'),
                default => false,
            };
        }

        if ($staff->role === 'ads' && $resource->advertiserId !== null
            && $resource->advertiserId > 0
            && $token->expires_at !== null) {
            $allowed = match ($action) {
                'advertising.organization.review' =>
                    $resource->kind === 'advertiser_organization'
                    && $resource->id === $resource->advertiserId
                    && $resource->state === 'active',
                'advertising.campaign.read',
                'advertising.campaign.request_changes' =>
                    $resource->kind === 'advertiser_campaign'
                    && $resource->state === 'in_review'
                    && $token->can('staff:step-up'),
                default => false,
            };

            if (! $allowed) {
                return false;
            }

            // Never trust a resource context or staff role alone: every grant
            // must exist as an active assignment to an active organization.
            return DB::table('staff_advertiser_assignments as a')
                ->join('advertiser_organizations as o', 'o.id', '=', 'a.advertiser_organization_id')
                ->where('a.staff_member_id', $staff->getKey())
                ->where('a.advertiser_organization_id', $resource->advertiserId)
                ->where('a.is_active', true)
                ->where('o.is_active', true)
                ->exists();
        }

        // Billing mutations, ad approval/publishing and privilege management
        // remain default-denied until independent reviewed policies exist.
        return false;
    }

    /**
     * Creation has no persisted resource ID yet; enforce a separate exact
     * editor privilege before the first insert. No general admin grant.
     */
    public function canCreateDraft(Authenticatable $principal, ?PersonalAccessToken $token): bool
    {
        if (! $principal instanceof StaffMember || ! $token
            || $token->expires_at?->isPast()
            || ! $token->can('staff:session')
            || $token->tokenable_type !== $principal->getMorphClass()
            || (string) $token->tokenable_id !== (string) $principal->getKey()) {
            return false;
        }

        $staff = StaffMember::query()->find($principal->getKey());

        return $staff !== null
            && $staff->role === 'editor'
            && $staff->is_active
            && $staff->email_verified_at !== null
            && $staff->mfa_enrolled_at !== null;
    }

    /**
     * List access is a separate permission: an empty inbox must not let
     * non-support staff discover whether support records exist.
     */
    public function canListOwnSupportTickets(
        Authenticatable $principal,
        ?PersonalAccessToken $token,
    ): bool {
        if (! $principal instanceof StaffMember || ! $token
            || $token->expires_at === null || $token->expires_at->isPast()
            || ! $token->can('staff:session')
            || $token->tokenable_type !== $principal->getMorphClass()
            || (string) $token->tokenable_id !== (string) $principal->getKey()) {
            return false;
        }

        $staff = StaffMember::query()->find($principal->getKey());

        return $staff !== null
            && $staff->role === 'support'
            && $staff->is_active
            && $staff->email_verified_at !== null
            && $staff->mfa_enrolled_at !== null;
    }

    /**
     * Only a distinct, server-assigned support reviewer with current MFA
     * step-up can authorize closure of an escalated support ticket.
     */
    public function canReviewSupportEscalation(
        Authenticatable $principal,
        SupportTicket $ticket,
        ?PersonalAccessToken $token,
    ): bool {
        if (! $this->canListOwnSupportTickets($principal, $token)
            || ! $token?->can('staff:step-up')) {
            return false;
        }

        $staffId = (int) $principal->getAuthIdentifier();

        return $ticket->exists
            && (int) $ticket->escalation_reviewer_staff_id === $staffId
            && (int) $ticket->assigned_staff_id !== $staffId
            && (int) $ticket->escalated_by_staff_id !== $staffId
            && $ticket->status === 'pending'
            && $ticket->escalated_at !== null
            && $ticket->escalation_reviewed_at === null;
    }

    /**
     * Only ads staff with current MFA-enrolled identity and a real expiring
     * staff session can discover their own advertiser review assignments.
     * This does not grant permission to provision or activate assignments.
     */
    public function canListOwnAdvertiserAssignments(
        Authenticatable $principal,
        ?PersonalAccessToken $token,
    ): bool {
        if (! $principal instanceof StaffMember || ! $token
            || $token->expires_at === null || $token->expires_at->isPast()
            || ! $token->can('staff:session')
            || $token->tokenable_type !== $principal->getMorphClass()
            || (string) $token->tokenable_id !== (string) $principal->getKey()) {
            return false;
        }

        $fresh = StaffMember::query()->find($principal->getKey());

        return $fresh !== null
            && $fresh->role === 'ads'
            && $fresh->is_active
            && $fresh->mfa_enrolled_at !== null
            && $fresh->email_verified_at !== null;
    }

    /**
     * Explicit out-of-band authorization record, separate from existing roles.
     * Two distinct currently authorized operators are required to grant ads
     * review assignments. No action authorizes security-admin self-enrollment.
     */
    public function canManageAdsAssignments(
        Authenticatable $principal,
        ?PersonalAccessToken $token,
    ): bool {
        if (! $principal instanceof StaffMember || ! $token
            || $token->expires_at === null || $token->expires_at->isPast()
            || ! $token->can('staff:session') || ! $token->can('staff:step-up')
            || $token->tokenable_type !== $principal->getMorphClass()
            || (string) $token->tokenable_id !== (string) $principal->getKey()) {
            return false;
        }

        $staff = StaffMember::query()->find($principal->getKey());
        if (! $staff || ! $staff->is_active || ! $staff->email_verified_at
            || ! $staff->mfa_enrolled_at || $staff->role === 'ads') {
            return false;
        }

        return DB::table('staff_security_admin_grants')
            ->where('staff_member_id', $staff->getKey())
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Staff can inspect only their own security metadata after a fresh MFA
     * step-up. This is not a global audit administration grant.
     */
    public function canViewOwnAudit(Authenticatable $principal, ?PersonalAccessToken $token): bool
    {
        if (! $principal instanceof StaffMember || ! $token
            || $token->expires_at === null || $token->expires_at->isPast()
            || ! $token->can('staff:session') || ! $token->can('staff:step-up')
            || $token->tokenable_type !== $principal->getMorphClass()
            || (string) $token->tokenable_id !== (string) $principal->getKey()) {
            return false;
        }

        $fresh = StaffMember::query()->find($principal->getKey());

        return $fresh !== null
            && $fresh->is_active
            && $fresh->email_verified_at !== null
            && $fresh->mfa_enrolled_at !== null
            && in_array($fresh->role, ['editor', 'support', 'ads', 'finance'], true);
    }

}
