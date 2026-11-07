<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProviderReport;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Account and review decisions made by administrators. Each one
 * needs a reason and is written to the audit log.
 */
class AdminActions
{
    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    public function setAccountStatus(User $account, string $status, User $admin, string $reason): User
    {
        if ($account->is($admin)) {
            throw new BusinessRuleException('cannot_change_own_account', 'You can’t change the status of your own account.', 403);
        }

        // Only administrators who manage admins may suspend another administrator
        if ($account->roles()->exists() && ! $admin->hasPermission('admin.manage')) {
            throw new BusinessRuleException('admin_account', 'This is an administrator account. Ask a Super Administrator.', 403);
        }

        if ($account->status === $status) {
            return $account;
        }

        return DB::transaction(function () use ($account, $status, $admin, $reason) {
            $before = $account->status;
            $account->forceFill(['status' => $status])->save();
            // Signed out everywhere straight away: tokens, sessions and "Keep me signed in"
            if ($status !== User::STATUS_ACTIVE) {
                $account->tokens()->delete();
                app(DeviceSessions::class)->endSessions($account);
                $account->forceFill(['remember_token' => Str::random(60)])->save();
            }

            $this->audit->record(
                $status === User::STATUS_ACTIVE ? 'admin.account_reactivated' : 'admin.account_suspended',
                $account, ['status' => $before], ['status' => $status], $reason, $admin
            );

            return $account;
        });
    }

    public function setReviewStatus(Review $review, string $status, User $admin, string $reason): Review
    {
        return DB::transaction(function () use ($review, $status, $admin, $reason) {
            $before = $review->status;
            $review->forceFill(['status' => $status])->save();
            $this->audit->record('admin.review_status_changed', $review, ['status' => $before], ['status' => $status], $reason, $admin);

            return $review;
        });
    }
}
