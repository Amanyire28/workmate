<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comment notifications (Phase C). Additive only: both columns are nullable, so every notification
 * written before this keeps working exactly as it was.
 *
 * context_id  the post a comment notification is about (the notification's own subject is the
 *             comment, or for a grouped one the post), so a recipient's notifications for one post
 *             and its one-minute window can be found without joining the comments table
 * parent_id   when a burst of comments is consolidated into one grouped notification, the
 *             individual notifications that were unread stay as hidden children of it. Nothing is
 *             deleted: the group is read from them (who commented, how many comments, which ones),
 *             and notifications the person had already read are never made children
 */
class AddCommentGroupingToUserNotifications extends Migration
{
    public function up()
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('context_id')->nullable()->after('subject_id');
            $table->unsignedBigInteger('parent_id')->nullable()->after('actor_id');

            $table->index(['user_id', 'kind', 'context_id', 'group_started_at'], 'user_notifications_context_index');
            $table->index('parent_id', 'user_notifications_parent_index');
        });
    }

    public function down()
    {
        Schema::table('user_notifications', function (Blueprint $table) {
            $table->dropIndex('user_notifications_parent_index');
            $table->dropIndex('user_notifications_context_index');
            $table->dropColumn(['context_id', 'parent_id']);
        });
    }
}
