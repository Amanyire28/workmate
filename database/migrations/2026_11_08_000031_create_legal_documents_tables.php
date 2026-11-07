<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal documents with versions, who accepted which version, and optional
 * consents (marketing). Acceptances and consents are append-only records.
 */
class CreateLegalDocumentsTables extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('title', 150);
            $table->string('summary', 500)->nullable();
            // everyone | providers
            $table->string('audience', 20)->default('everyone');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('legal_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_document_id')->constrained()->cascadeOnDelete();
            $table->string('version', 20);
            // draft | published; a published version never changes
            $table->string('status', 20)->default('draft');
            $table->string('summary', 500)->nullable();
            $table->longText('body');
            $table->string('change_note', 1000)->nullable();
            // A material change asks everyone to accept again
            $table->boolean('is_material')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['legal_document_id', 'version']);
            $table->index(['legal_document_id', 'status', 'published_at'], 'legal_versions_document_status_published');
        });

        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_document_version_id')->constrained()->cascadeOnDelete();
            // sign_up | prompt | provider_application
            $table->string('method', 30);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('accepted_at');
            $table->unique(['user_id', 'legal_document_version_id']);
        });

        Schema::create('user_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->boolean('granted');
            // sign_up | settings
            $table->string('source', 30);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at');
            $table->index(['user_id', 'purpose', 'id']);
        });

        Schema::table('users', function (Blueprint $table) {
            // When the person confirmed they are old enough (DPP Regulations 11)
            $table->timestamp('adult_confirmed_at')->nullable()->after('email_verified_at');
        });

        // Managing legal documents and launch facts. Safe to run twice; the
        // seeder does the same from config/permissions.php.
        DB::table('permissions')->insertOrIgnore([
            'key' => 'legal.manage',
            'description' => 'Draft, publish and export legal documents, and set the company facts they show',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('key', 'legal.manage')->value('id');
        foreach (DB::table('roles')->where('key', 'super_admin')->pluck('id') as $roleId) {
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('permissions')->where('key', 'legal.manage')->delete();
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('adult_confirmed_at');
        });
        Schema::dropIfExists('user_consents');
        Schema::dropIfExists('legal_acceptances');
        Schema::dropIfExists('legal_document_versions');
        Schema::dropIfExists('legal_documents');
    }
}
