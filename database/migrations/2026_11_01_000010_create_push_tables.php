<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePushTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Devices that turned on push notifications. The endpoint is the push
        // service's address for one browser on one device; keys encrypt messages to it.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            // sha256 of the endpoint, so it can be unique (endpoints are long)
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('content_encoding', 20)->default('aes128gcm');
            // android | ios | desktop
            $table->string('platform', 10)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        // Announcements sent by administrators to every subscribed device
        Schema::create('push_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 60);
            $table->string('body', 200);
            // A WorkMate page to open when tapped, e.g. /browse
            $table->string('url', 255)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            // sending | sent
            $table->string('status', 12)->default('sending');
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('delivered')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('expired')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('push_announcements');
        Schema::dropIfExists('push_subscriptions');
    }
}
