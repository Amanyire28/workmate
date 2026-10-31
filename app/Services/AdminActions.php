<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProviderReport;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Account, review and report decisions made by administrators. Each one
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
            // Signed-in API tokens stop working straight away; web sessions end on the next request
            if ($status !== User::STATUS_ACTIVE) {
                $account->tokens()->delete();
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

    public function setReportStatus(ProviderReport $report, string $status, User $admin, string $reason): ProviderReport
    {
        return DB::transaction(function () use ($report, $status, $admin, $reason) {
            $before = $report->status;
            $report->forceFill(['status' => $status])->save();
            $this->audit->record('admin.report_status_changed', $report, ['status' => $before], ['status' => $status], $reason, $admin);

            return $report;
        });
    }
}
