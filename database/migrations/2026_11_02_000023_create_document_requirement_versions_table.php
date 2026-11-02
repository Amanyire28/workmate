<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDocumentRequirementVersionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Published versions of the documents each profession needs. Never edited:
        // publishing adds a new version, and submitted applications keep theirs.
        Schema::create('document_requirement_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            // [{id, name, instructions, requirement, condition, enabled, count, maxSizeMb, formats, frontBack, multiPage, expiry}]
            $table->json('documents');
            $table->string('note', 500)->nullable();
            // null = loaded by WorkMate
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['category_id', 'version']);
        });

        Schema::table('document_verifications', function (Blueprint $table) {
            // The requirements it was submitted under (null before submitting, or when none applied)
            $table->foreignId('requirement_version_id')->nullable()->after('status')->constrained('document_requirement_versions')->nullOnDelete();
            // The provider's answers to questions such as "Do you use your own vehicle?"
            $table->json('answers')->nullable()->after('requirement_version_id');
        });

        Schema::table('verification_documents', function (Blueprint $table) {
            // Which profession requirement a "profession" document provides
            $table->string('requirement_key', 40)->nullable()->after('kind');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('verification_documents', function (Blueprint $table) {
            $table->dropColumn('requirement_key');
        });
        Schema::table('document_verifications', function (Blueprint $table) {
            $table->dropForeign(['requirement_version_id']);
            $table->dropColumn(['requirement_version_id', 'answers']);
        });
        Schema::dropIfExists('document_requirement_versions');
    }
}
