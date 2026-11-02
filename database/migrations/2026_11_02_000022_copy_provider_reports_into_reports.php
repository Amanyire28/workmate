<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copies the reports about providers made before the reports table existed.
 * provider_reports is left as it was. Safe to run again: each row is copied
 * once (legacy_provider_report_id), in chunks.
 */
class CopyProviderReportsIntoReports extends Migration
{
    public function up()
    {
        $statuses = ['open' => 'open', 'under_review' => 'under_review', 'confirmed' => 'resolved', 'dismissed' => 'closed'];
        $outcomes = ['confirmed' => 'resolved', 'dismissed' => 'no_violation'];
        $high = ['scam', 'fake', 'unsafe'];

        DB::table('provider_reports')->orderBy('id')->chunkById(500, function ($rows) use ($statuses, $outcomes, $high) {
            $copied = DB::table('reports')->whereIn('legacy_provider_report_id', $rows->pluck('id'))->pluck('legacy_provider_report_id')->flip();
            $insert = [];
            foreach ($rows as $r) {
                if (isset($copied[$r->id])) {
                    continue;
                }
                $insert[] = [
                    'reporter_id' => $r->reporter_id, 'reporter_role' => 'customer', 'subject_type' => 'provider',
                    'provider_profile_id' => $r->provider_profile_id, 'reason' => $r->reason, 'details' => $r->details,
                    'severity' => in_array($r->reason, $high, true) ? 'high' : ($r->reason === 'no_show' ? 'medium' : 'low'),
                    'status' => $statuses[$r->status] ?? 'open', 'outcome' => $outcomes[$r->status] ?? null,
                    'legacy_provider_report_id' => $r->id, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
                ];
            }
            if ($insert) {
                DB::table('reports')->insert($insert);
            }
        });
    }

    /** Removes only the copies; provider_reports is untouched */
    public function down()
    {
        DB::table('reports')->whereNotNull('legacy_provider_report_id')->delete();
    }
}
