<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAppInstallEventsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Anonymous install events from the installable web app. A device is a
        // random ID the browser keeps; no account, IP address or location is stored.
        Schema::create('app_install_events', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 36);
            // prompt_shown | installed | first_launch
            $table->string('event', 20);
            // android | ios | desktop
            $table->string('platform', 10);
            // button (WorkMate's install button) | browser (the browser's own menu or banner)
            $table->string('source', 10)->nullable();
            $table->date('occurred_on');
            $table->timestamp('created_at')->nullable();
            // One row per device, event and day; installs and first launches once per device
            $table->unique(['device_id', 'event', 'occurred_on']);
            $table->index(['occurred_on', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('app_install_events');
    }
}
