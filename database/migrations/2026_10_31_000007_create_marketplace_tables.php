<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMarketplaceTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Neighbourhoods and towns people search from and providers serve.
        // Centre points are approximate area centres, never anyone's position.
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name');
            $table->string('town')->nullable();
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            // Stands for the whole town (e.g. "Kampala") rather than one area
            $table->boolean('is_whole_town')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Sample records stand in for real ones while WorkMate is in
        // development; the website labels them and they can be removed together.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_sample')->default(false)->index()->after('status');
        });

        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->boolean('is_sample')->default(false)->index()->after('status');
            $table->foreignId('base_area_id')->nullable()->after('business_registration_number')->constrained('areas')->nullOnDelete();
            // Approximate registered base. Rounded before it is ever shown publicly.
            $table->decimal('latitude', 9, 6)->nullable()->after('base_area_id');
            $table->decimal('longitude', 9, 6)->nullable()->after('latitude');
            $table->json('skills')->nullable()->after('bio');
            // available | busy | unavailable (null: not set by the provider)
            $table->string('availability_status', 20)->nullable()->after('skills');
            $table->string('availability_note', 120)->nullable()->after('availability_status');
            $table->string('next_available', 60)->nullable()->after('availability_note');
            // Whole UGX; indicative "from" price only
            $table->unsignedInteger('price_from')->nullable()->after('next_available');
            $table->string('price_basis', 40)->nullable()->after('price_from');
            // Set by the document verification and blue tick processes (Phase 5);
            // until then only sample providers carry them.
            $table->timestamp('document_verified_at')->nullable()->after('approved_at');
            $table->timestamp('blue_tick_awarded_at')->nullable()->after('document_verified_at');
            $table->index(['status', 'base_area_id']);
        });

        Schema::create('provider_service_areas', function (Blueprint $table) {
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->primary(['provider_profile_id', 'area_id']);
            $table->index('area_id');
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            // Shown publicly in short form, e.g. "Aisha N."
            $table->string('reviewer_name', 60);
            $table->string('job_title');
            $table->string('area_name')->nullable();
            $table->unsignedTinyInteger('rating');
            $table->boolean('would_hire_again')->nullable();
            $table->text('comment')->nullable();
            // published | hidden (moderation)
            $table->string('status', 20)->default('published');
            $table->boolean('is_sample')->default(false)->index();
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->index(['provider_profile_id', 'status']);
        });

        // A job posted for any provider to apply to, or a request sent to one provider
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            // Set for a request sent directly to one provider
            $table->foreignId('provider_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->string('area_name');
            $table->string('preferred_time', 100)->nullable();
            $table->unsignedTinyInteger('workers_needed')->default(1);
            $table->unsignedInteger('budget')->nullable();
            // fixed | total | per day | per hour …
            $table->string('budget_basis', 30)->nullable();
            // open | in_progress | completed | cancelled
            $table->string('status', 20)->default('open');
            $table->boolean('is_sample')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'provider_profile_id', 'created_at']);
            $table->index('customer_id');
        });

        // A provider's application (with an optional price) for a request
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->string('start_time', 60)->nullable();
            $table->unsignedInteger('amount')->nullable();
            // pending | accepted | declined | withdrawn
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->unique(['service_request_id', 'provider_profile_id']);
            $table->index('provider_profile_id');
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('provider_profile_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['customer_id', 'provider_profile_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('service_requests');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('provider_service_areas');

        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropIndex(['status', 'base_area_id']);
            $table->dropIndex(['is_sample']);
            $table->dropConstrainedForeignId('base_area_id');
            $table->dropColumn([
                'is_sample', 'latitude', 'longitude', 'skills', 'availability_status', 'availability_note',
                'next_available', 'price_from', 'price_basis', 'document_verified_at', 'blue_tick_awarded_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_sample']);
            $table->dropColumn('is_sample');
        });

        Schema::dropIfExists('areas');
    }
}
