<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\StaffResourceContext;
use App\Models\StaffMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class StaffAuthorizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'editor'): StaffMember
    {
        $member = StaffMember::create([
            'name' => 'Staff Operator',
            'email' => 'operator-'.uniqid().'@example.test',
            'password' => 'Strong!StaffPassword2026',
            'role' => $role,
        ]);
        $member->forceFill([
            'email_verified_at' => now(),
            'mfa_enrolled_at' => now(),
            'is_active' => true,
        ])->save();

        return $member;
    }

    private function token(StaffMember $member, bool $stepUp = false): PersonalAccessToken
    {
        return $member->createToken(
            'staff-policy-test',
            $stepUp ? ['staff:session', 'staff:step-up'] : ['staff:session'],
            now()->addMinutes(10),
        )->accessToken;
    }

    private function article(int $id, int $owner, string $state = 'draft'): StaffResourceContext
    {
        return new StaffResourceContext($id, 'editorial_article', $state, ownerStaffId: $owner);
    }

    private function ticket(int $id, int $assignee, string $state = 'open'): StaffResourceContext
    {
        return new StaffResourceContext($id, 'support_ticket', $state, assignedStaffId: $assignee);
    }

    private function permits(
        StaffMember $member,
        string $action,
        StaffResourceContext $resource,
        PersonalAccessToken $token,
    ): bool {
        return Gate::forUser($member)->allows('staff-resource', [$action, $resource, $token]);
    }

    public function test_editor_only_owns_draft_operations_and_cannot_publish(): void
    {
        $editor = $this->staff('editor');
        $token = $this->token($editor);
        $draft = $this->article(10, $editor->id);

        foreach (['editorial.draft.view', 'editorial.draft.edit', 'editorial.draft.submit'] as $action) {
            $this->assertTrue($this->permits($editor, $action, $draft, $token), $action);
        }
        foreach (['editorial.publish', 'editorial.publish.force', 'staff.role.assign',
            'finance.invoice.pay', 'support.ticket.view', 'editorial.correction.propose'] as $action) {
            $this->assertFalse($this->permits($editor, $action, $draft, $token), $action);
        }
    }

    public function test_editorial_state_machine_denies_mutations_outside_draft(): void
    {
        $editor = $this->staff('editor');
        $token = $this->token($editor);

        foreach (['in_review', 'held', 'published', 'retracted', 'unknown'] as $state) {
            foreach (['editorial.draft.edit', 'editorial.draft.submit'] as $action) {
                $this->assertFalse($this->permits($editor, $action,
                    $this->article(10, $editor->id, $state), $token));
            }
        }
        $this->assertTrue($this->permits($editor, 'editorial.draft.view',
            $this->article(10, $editor->id, 'held'), $token));
        $this->assertFalse($this->permits($editor, 'editorial.draft.view',
            $this->article(10, $editor->id, 'published'), $token));
    }

    public function test_editor_cannot_access_other_staff_articles_even_with_valid_role(): void
    {
        $owner = $this->staff('editor');
        $other = $this->staff('editor');
        $token = $this->token($other);

        foreach (['editorial.draft.view', 'editorial.draft.edit', 'editorial.draft.submit'] as $action) {
            $this->assertFalse($this->permits($other, $action,
                $this->article(42, $owner->id), $token));
        }
    }

    public function test_support_scope_is_assignment_bound_and_state_bound(): void
    {
        $support = $this->staff('support');
        $other = $this->staff('support');
        $token = $this->token($support);
        $assigned = $this->ticket(11, $support->id);
        $unassigned = $this->ticket(12, $other->id);

        $this->assertTrue($this->permits($support, 'support.ticket.view', $assigned, $token));
        $this->assertTrue($this->permits($support, 'support.ticket.reply', $assigned, $token));
        $this->assertFalse($this->permits($support, 'support.ticket.view', $unassigned, $token));
        $this->assertFalse($this->permits($support, 'support.ticket.reply', $unassigned, $token));
        $this->assertFalse($this->permits($support, 'support.ticket.reply',
            $this->ticket(11, $support->id, 'resolved'), $token));
        $this->assertFalse($this->permits($support, 'editorial.draft.view',
            $this->article(11, $support->id), $token));
    }

    public function test_high_risk_proposals_require_recent_mfa_step_up(): void
    {
        $editor = $this->staff('editor');
        $support = $this->staff('support');
        $editorArticle = $this->article(1, $editor->id, 'published');
        $supportTicket = $this->ticket(2, $support->id);

        $this->assertFalse($this->permits($editor, 'editorial.correction.propose',
            $editorArticle, $this->token($editor)));
        $this->assertTrue($this->permits($editor, 'editorial.correction.propose',
            $editorArticle, $this->token($editor, true)));
        $this->assertFalse($this->permits($support, 'support.ticket.escalate',
            $supportTicket, $this->token($support)));
        $this->assertTrue($this->permits($support, 'support.ticket.escalate',
            $supportTicket, $this->token($support, true)));
        $this->assertFalse($this->permits($editor, 'editorial.correction.propose',
            $this->article(1, $editor->id, 'draft'), $this->token($editor, true)));
    }

    public function test_ads_and_finance_default_deny_until_real_scope_models_exist(): void
    {
        foreach (['ads', 'finance'] as $role) {
            $staff = $this->staff($role);
            $token = $this->token($staff, true);
            foreach (['advertising.campaign.read', 'advertising.campaign.approve',
                'finance.invoice.read', 'finance.invoice.pay',
                'finance.reconciliation.approve', 'staff.role.assign',
                'editorial.publish'] as $action) {
                $this->assertFalse($this->permits($staff, $action,
                    new StaffResourceContext(9, 'advertiser_campaign', 'approved'), $token));
            }
        }
    }

    public function test_advertiser_scoped_resources_are_always_denied_until_membership_is_verified(): void
    {
        $editor = $this->staff('editor');
        $token = $this->token($editor, true);
        $this->assertFalse($this->permits($editor, 'editorial.draft.edit',
            new StaffResourceContext(10, 'editorial_article', 'draft',
                ownerStaffId: $editor->id, advertiserId: 11), $token));
    }

    public function test_inactive_unverified_or_no_mfa_staff_cannot_use_prior_permissions(): void
    {
        $staff = $this->staff();
        $token = $this->token($staff);
        $draft = $this->article(10, $staff->id);
        $this->assertTrue($this->permits($staff, 'editorial.draft.edit', $draft, $token));

        $staff->forceFill(['is_active' => false])->save();
        $this->assertFalse($this->permits($staff, 'editorial.draft.edit', $draft, $token));
        $staff->forceFill(['is_active' => true, 'mfa_enrolled_at' => null])->save();
        $this->assertFalse($this->permits($staff, 'editorial.draft.edit', $draft, $token));
        $staff->forceFill(['mfa_enrolled_at' => now(), 'email_verified_at' => null])->save();
        $this->assertFalse($this->permits($staff, 'editorial.draft.edit', $draft, $token));
    }

    public function test_role_change_is_effective_without_reissuing_old_session_token(): void
    {
        $staff = $this->staff('editor');
        $token = $this->token($staff);
        $draft = $this->article(10, $staff->id);

        $this->assertTrue($this->permits($staff, 'editorial.draft.edit', $draft, $token));
        StaffMember::query()->whereKey($staff->getKey())->update(['role' => 'support']);
        $this->assertFalse($this->permits($staff, 'editorial.draft.edit', $draft, $token));
    }

    public function test_tokens_without_staff_session_or_from_other_staff_are_denied(): void
    {
        $staff = $this->staff();
        $other = $this->staff();
        $draft = $this->article(10, $staff->id);
        $this->assertFalse($this->permits($staff, 'editorial.draft.edit',
            $draft, $this->token($staff, true)->forceFill(['abilities' => ['other']])));

        $this->assertFalse($this->permits($staff, 'editorial.draft.edit',
            $draft, $this->token($other)));
        $expired = $staff->createToken('expired', ['staff:session'], now()->subMinute())->accessToken;
        $this->assertFalse($this->permits($staff, 'editorial.draft.edit', $draft, $expired));
    }

    public function test_unknown_actions_and_invalid_resource_ids_default_deny(): void
    {
        $staff = $this->staff();
        $token = $this->token($staff, true);

        foreach ([-1, 0] as $id) {
            $this->assertFalse($this->permits($staff, 'editorial.draft.view',
                $this->article($id, $staff->id), $token));
        }
        foreach (['*', '', 'root', 'admin.superuser'] as $action) {
            $this->assertFalse($this->permits($staff, $action,
                $this->article(1, $staff->id), $token));
        }
    }

    public function test_reader_account_has_no_staff_policy_privileges(): void
    {
        $reader = User::factory()->create();
        $token = $reader->createToken('reader', ['staff:session'])->accessToken;
        $this->assertFalse(Gate::forUser($reader)->allows('staff-resource', [
            'editorial.draft.edit',
            $this->article(1, $reader->id),
            $token,
        ]));
    }

    public function test_non_expiring_tokens_cannot_bypass_editor_or_support_resource_gates(): void
    {
        $editor = $this->staff('editor');
        $editorToken = $editor->createToken(
            'legacy-editor', ['staff:session', 'staff:step-up'],
        )->accessToken;
        $this->assertFalse($this->permits(
            $editor, 'editorial.draft.edit',
            $this->article(15, $editor->getKey()), $editorToken,
        ));
        $this->assertFalse(Gate::forUser($editor)->allows(
            'staff-create-draft', [$editorToken],
        ));

        $support = $this->staff('support');
        $supportToken = $support->createToken(
            'legacy-support', ['staff:session', 'staff:step-up'],
        )->accessToken;
        $this->assertFalse($this->permits(
            $support, 'support.ticket.view',
            $this->ticket(16, $support->getKey()), $supportToken,
        ));
        $this->assertFalse(Gate::forUser($support)->allows(
            'staff-support-tickets-self', [$supportToken],
        ));
    }

}
