<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos and PDF documents sent in a chat. Files live on the private disk
 * and are served only to the two sides of the conversation.
 */
class CreateMessageAttachmentsTable extends Migration
{
    public function up()
    {
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            // Kept here too, so a file is found and checked without loading the message
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->uuid('uuid')->unique();
            // photo | file
            $table->string('kind', 10);
            $table->string('path', 255);
            $table->string('thumb_path', 255)->nullable();
            $table->string('original_name', 150)->nullable();
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->index(['conversation_id', 'uuid']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('message_attachments');
    }
}
