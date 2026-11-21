<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real-time push delivery (Phase B). Additive only.
 *
 * notification_pushes is the outbox: a row is saved in the same transaction as the event it is
 * about, tried at once after the commit, and retried by the scheduler if it did not finish.
 *   kind     the notification kind (for the person's choices, checked again when it is sent)
 *   payload  what to send, for events that have no group to read it from; cleared as soon as the
 *            push is finished, so no wording is kept after delivery
 *   silent   whether the phone was asked to replace the notification without a sound
 *   devices_failed / devices_expired / last_http_status   how the last attempt went (numbers only)
 *
 * notification_push_deliveries: the outcome per device, so a retry goes only to the devices that
 * have not accepted the push yet. Statuses and HTTP codes only, never message text or addresses.
 */
class AddPushOutboxAndDeliveries extends Migration
{
    public function up()
    {
        Schema::table('notification_pushes', function (Blueprint $table) {
            $table->string('kind', 30)->nullable()->after('type');
            $table->text('payload')->nullable()->after('user_notification_id');
            $table->boolean('silent')->nullable()->after('payload');
            $table->unsignedSmallInteger('devices_failed')->default(0)->after('devices_delivered');
            $table->unsignedSmallInteger('devices_expired')->default(0)->after('devices_failed');
            $table->unsignedSmallInteger('last_http_status')->nullable()->after('devices_expired');

            $table->index(['user_id', 'created_at'], 'notification_pushes_user_created_index');
        });

        Schema::create('notification_push_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_push_id')->constrained('notification_pushes')->cascadeOnDelete();
            $table->foreignId('push_subscription_id')->constrained('push_subscriptions')->cascadeOnDelete();
            // accepted (the push service took it) | failed (try again) | rejected (don't) | expired (device gone)
            $table->string('status', 10);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['notification_push_id', 'push_subscription_id'], 'notification_push_deliveries_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('notification_push_deliveries');
        Schema::table('notification_pushes', function (Blueprint $table) {
            $table->dropIndex('notification_pushes_user_created_index');
            $table->dropColumn(['kind', 'payload', 'silent', 'devices_failed', 'devices_expired', 'last_http_status']);
        });
    }
}
