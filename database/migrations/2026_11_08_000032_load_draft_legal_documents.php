<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Loads the eight draft legal documents (database/legal) as version 0.1, for
 * review by a Ugandan advocate and editing in Admin → Legal Documents.
 * Safe to run again: a document that already exists is left alone.
 */
class LoadDraftLegalDocuments extends Migration
{
    public function up()
    {
        $dir = database_path('legal');
        $index = json_decode(file_get_contents($dir.'/documents.json'), true);
        $now = now();

        foreach ($index as $doc) {
            if (DB::table('legal_documents')->where('key', $doc['key'])->exists()) {
                continue;
            }

            $id = DB::table('legal_documents')->insertGetId([
                'key' => $doc['key'],
                'title' => $doc['title'],
                'summary' => $doc['summary'],
                'audience' => $doc['audience'],
                'sort_order' => $doc['order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('legal_document_versions')->insert([
                'legal_document_id' => $id,
                'version' => '0.1',
                'status' => 'published',
                'summary' => $doc['summary'],
                'body' => file_get_contents($dir.'/'.$doc['key'].'.md'),
                'change_note' => 'First draft, for review by a Ugandan advocate.',
                'is_material' => true,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down()
    {
        // The documents may have been edited since; the table migration removes them
    }
}
