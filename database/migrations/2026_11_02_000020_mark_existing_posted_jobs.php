<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marks the jobs posted before is_posted_job existed: those without a
 * provider. Safe to run again; works through the table in chunks.
 */
class MarkExistingPostedJobs extends Migration
{
    public function up()
    {
        DB::table('service_requests')
            ->whereNull('provider_profile_id')
            ->where('is_posted_job', false)
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                DB::table('service_requests')->whereIn('id', $rows->pluck('id'))->update(['is_posted_job' => true]);
            });
    }

    /** The column is dropped by the previous migration's down() */
    public function down()
    {
        //
    }
}
