<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Smart notifications (Step 5). Additive only: every new column is nullable or
 * has a default, so existing notifications, devices and accounts keep working.
 *
 * user_notifications: a like or follow notification is one row per recipient,
 *   content and group window (group_key), with the live count of people in it.
 *   The people themselves are read from the likes / follows tables, not copied.
 * notification_pushes: one row per push that is waiting, sending or finished.
 *   dedupe_key is unique, so concurrent requests and retries can't send twice.
 * users: quiet hours (local "HH:MM" times) and the timezone they are in.
 * push_subscriptions: consecutive failures, so dead devices are removed.
 */
class AddSmartNotifications extends Migration
{
    public function up()
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->string('group_key', 120)->nullable()->after('tag');
            $table->string('subject_type', 20)->nullable()->after('group_key');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $table->unsignedBigInteger('actor_id')->nullable()->after('subject_id');
            $table->timestamp('group_started_at')->nullable()->after('actor_id');
            $table->unsignedInteger('group_count')->nullable()->after('group_started_at');

            $table->unique(['user_id', 'group_key'], 'user_notifications_group_unique');
            $table->index(['user_id', 'read_at'], 'user_notifications_unread_index');
            $table->index(['user_id', 'kind', 'subject_type', 'subject_id'], 'user_notifications_subject_index');
        });

        Schema::create('notification_pushes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // like_group | follow_group | digest
            $table->string('type', 20);
            $table->string('dedupe_key', 120)->unique();
            // The grouped notification this push is about (digests have none)
            $table->unsignedBigInteger('user_notification_id')->nullable();
            // pending | sending | sent | skipped | gave_up
            $table->string('status', 10)->default('pending');
            $table->timestamp('send_after')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedSmallInteger('devices_delivered')->default(0);
            // Why it was skipped, or the last failure: short text, never message content
            $table->string('note', 120)->nullable();
            $table->timestamps();

            $table->index(['status', 'send_after'], 'notification_pushes_due_index');
            $table->index(['user_id', 'type', 'sent_at'], 'notification_pushes_user_sent_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->char('quiet_hours_start', 5)->nullable()->after('notification_preferences');
            $table->char('quiet_hours_end', 5)->nullable()->after('quiet_hours_start');
            $table->string('timezone', 40)->nullable()->after('quiet_hours_end');
        });

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->unsignedSmallInteger('failure_count')->default(0)->after('last_success_at');
        });
    }

    public function down()
    {
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropColumn('failure_count');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['quiet_hours_start', 'quiet_hours_end', 'timezone']);
        });
        Schema::dropIfExists('notification_pushes');
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex('user_notifications_subject_index');
            $table->dropIndex('user_notifications_unread_index');
            $table->dropUnique('user_notifications_group_unique');
            $table->dropColumn(['group_key', 'subject_type', 'subject_id', 'actor_id', 'group_started_at', 'group_count']);
        });
    }
}
