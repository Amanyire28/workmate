<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service requests shown in the chat between the customer and the provider.
 * The message only points at the request (the request stays the one record
 * of the job), so the card always shows its current status. One card per
 * request per chat: the unique key stops a retried send adding a second.
 */
class AddRequestCardsToMessages extends Migration
{
    public function up()
    {
        Schema::table('messages', function (Blueprint $table) {
            // text | request
            $table->string('kind', 20)->default('text')->after('sender_id');
            $table->foreignId('service_request_id')->nullable()->after('kind')->constrained()->nullOnDelete();
            $table->unique(['conversation_id', 'service_request_id'], 'messages_one_card_per_request');
        });
    }

    public function down()
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_one_card_per_request');
            $table->dropConstrainedForeignId('service_request_id');
            $table->dropColumn('kind');
        });
    }
}
