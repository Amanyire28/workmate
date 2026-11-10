<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invite Friends: each account's referral code, who invited whom and how it
 * stands, daily counts of invitation links opened, and recognition badges
 * earned at milestones. Recognition only: nothing here holds money or credit.
 */
class CreateReferralTables extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable()->unique();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            // One credited inviter for each new account
            $table->foreignId('referred_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('code', 12);
            // registered | review | qualified | rejected | revoked
            $table->string('status', 20);
            $table->json('risk_flags')->nullable();
            $table->timestamp('link_opened_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'status']);
            $table->index('status');
        });

        // Opened links, counted per inviter and day; nobody's identity is kept
        Schema::create('referral_link_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('visits')->default(0);
            $table->unique(['user_id', 'day']);
        });

        Schema::create('recognition_milestones', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 60);
            $table->string('description', 255);
            $table->unsignedSmallInteger('threshold')->unique();
            $table->string('icon', 40)->default('bi-award');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recognition_milestone_id')->constrained()->restrictOnDelete();
            $table->timestamp('awarded_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 500)->nullable();
            $table->timestamps();
            // A badge is awarded to someone once; taking it back keeps the record
            $table->unique(['user_id', 'recognition_milestone_id']);
        });

        $now = now();
        DB::table('recognition_milestones')->insert([
            ['key' => 'first_connection', 'name' => 'First Connection', 'threshold' => 1, 'icon' => 'bi-person-plus',
                'description' => 'Earned by inviting a first new member who joined WorkMate.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'community_builder', 'name' => 'Community Builder', 'threshold' => 5, 'icon' => 'bi-people',
                'description' => 'Earned by inviting five new members who joined WorkMate.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'workmate_connector', 'name' => 'WorkMate Connector', 'threshold' => 15, 'icon' => 'bi-diagram-3',
                'description' => 'Earned by inviting fifteen new members who joined WorkMate.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'community_champion', 'name' => 'Community Champion', 'threshold' => 30, 'icon' => 'bi-trophy',
                'description' => 'Earned by inviting thirty new members who joined WorkMate.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'workmate_ambassador', 'name' => 'WorkMate Ambassador', 'threshold' => 75, 'icon' => 'bi-stars',
                'description' => 'Earned by inviting seventy-five new members who joined WorkMate.', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Safe to run twice; the seeder does the same from config/permissions.php
        $permissions = [
            'referrals.view' => 'View invitations, referral records and badges',
            'referrals.manage' => 'Approve, reject or revoke referrals that need a decision',
            'referrals.configure' => 'Change the invitation badges and their milestones',
        ];
        $grants = ['super_admin' => array_keys($permissions), 'trust_safety' => ['referrals.view', 'referrals.manage']];
        foreach ($permissions as $key => $description) {
            DB::table('permissions')->insertOrIgnore(['key' => $key, 'description' => $description, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($grants as $roleKey => $keys) {
            foreach (DB::table('roles')->where('key', $roleKey)->pluck('id') as $roleId) {
                foreach ($keys as $key) {
                    DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => DB::table('permissions')->where('key', $key)->value('id')]);
                }
            }
        }
    }

    public function down()
    {
        DB::table('permissions')->whereIn('key', ['referrals.view', 'referrals.manage', 'referrals.configure'])->delete();
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('recognition_milestones');
        Schema::dropIfExists('referral_link_visits');
        Schema::dropIfExists('referrals');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }
}
