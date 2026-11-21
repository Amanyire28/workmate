<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * @mentions in community posts and comments. Additive only: existing posts and
 * comments are untouched and simply have no mentions.
 *
 * community_mentions: one row per mention in the text, by account id (so it
 * survives a change of name), with where it sits in the text (start and
 * length in UTF-16 code units, as browsers and phones count them).
 *
 * community_mention_alerts: who has already been told about being mentioned
 * in a post or comment, so editing (or removing and re-adding a mention)
 * never tells them twice.
 *
 * mentionable_type is "post" or "comment" (checked by CommunityMentions).
 */
class CreateCommunityMentions extends Migration
{
    public function up()
    {
        Schema::create('community_mentions', function (Blueprint $table) {
            $table->id();
            $table->string('mentionable_type', 16);
            $table->unsignedBigInteger('mentionable_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('start');
            $table->unsignedSmallInteger('length');
            $table->timestamps();

            $table->unique(['mentionable_type', 'mentionable_id', 'start'], 'community_mentions_place_unique');
            $table->index('user_id');
        });

        Schema::create('community_mention_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('mentionable_type', 16);
            $table->unsignedBigInteger('mentionable_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['mentionable_type', 'mentionable_id', 'user_id'], 'community_mention_alerts_unique');
            $table->index('user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('community_mention_alerts');
        Schema::dropIfExists('community_mentions');
    }
}
