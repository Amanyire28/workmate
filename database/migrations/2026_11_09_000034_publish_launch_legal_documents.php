<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Publishes the final version 1.0 of each legal document from the text in
 * database/legal, whatever was edited or drafted in Admin → Legal Documents:
 * [[drafting notes]] are taken out (proposed figures stay as plain text), the
 * company registration and PDPO certificate numbers are left out, and
 * features the app doesn't offer yet are replaced by asking us by email.
 * Unpublished drafts are discarded so they can't replace it. It's material,
 * so everyone is asked to review and accept it once. Earlier published
 * versions stay on record for those who accepted them.
 */
class PublishLaunchLegalDocuments extends Migration
{
    private const CHANGE_NOTE = 'We’ve updated this document. Please read it before you continue.';

    public function up()
    {
        $dir = database_path('legal');
        $now = now();

        foreach (json_decode(file_get_contents($dir.'/documents.json'), true) as $source) {
            $doc = DB::table('legal_documents')->where('key', $source['key'])->first();
            if (! $doc) {
                continue;
            }
            $versions = DB::table('legal_document_versions')->where('legal_document_id', $doc->id);
            if ((clone $versions)->where('change_note', self::CHANGE_NOTE)->exists()) {
                continue;
            }

            (clone $versions)->where('status', '!=', 'published')->delete();

            $taken = (clone $versions)->pluck('version')->all();
            $minor = 0;
            while (in_array('1.'.$minor, $taken, true)) {
                $minor++;
            }

            DB::table('legal_document_versions')->insert([
                'legal_document_id' => $doc->id,
                'version' => '1.'.$minor,
                'status' => 'published',
                'summary' => $this->finalSummary($source['summary']),
                'body' => $this->finalBody(file_get_contents($dir.'/'.$source['key'].'.md')),
                'change_note' => self::CHANGE_NOTE,
                'is_material' => true,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('legal_documents')->where('id', $doc->id)->update(['title' => $source['title'], 'summary' => $this->finalSummary($source['summary']), 'updated_at' => $now]);
        }
    }

    public function down()
    {
        // Only versions nobody has accepted yet; an accepted version is a legal record
        DB::table('legal_document_versions')
            ->where('change_note', self::CHANGE_NOTE)
            ->whereNotIn('id', DB::table('legal_acceptances')->select('legal_document_version_id'))
            ->delete();
    }

    private function finalSummary(string $summary): string
    {
        return str_replace(
            'You can download, correct or delete your data,',
            'You can ask for a copy of your data, correct it or have it deleted,',
            $summary
        );
    }

    private function finalBody(string $body): string
    {
        $body = preg_replace('/^Retention periods marked \[\[proposed\]\] are still to be confirmed\.\R+/m', '', $body);
        $body = str_replace(
            '- **Email delivery** [[if the email provider is outside Uganda]].',
            '- **Email delivery** passes through our email provider, {{email_provider}}.',
            $body
        );
        $body = str_replace('[[999 / 112]]', '999 or 112', $body);
        $body = str_replace('are deleted or shown as "Former member" [[choose]];', 'are shown under "Former member";', $body);

        // The company number and PDPO certificate number aren't published
        $body = preg_replace('/^- Registration number: \{\{registration_number\}\}\R/m', '', $body);
        $body = str_replace(' {{pdpo_certificate}}', '', $body);

        // Point only at what the app offers; the rest is done by email
        $body = strtr($body, [
            '| Edit in Account; delete the account |' => '| Edit in Account; ask us to close the account |',
            '| Download; delete the account |' => '| Ask us for a copy; ask us to close the account |',
            '| Download; report a message |' => '| Ask us for a copy; report a message |',
            '| Account → Privacy |' => '| Account → Legal and privacy |',
            'View in Account → Legal |' => 'View in Account → Legal and privacy |',
            ' You can also use **Download my data** any time.' => '',
            'To use a right, go to **Account → Privacy** or email {{privacy_email}}.' => 'To use a right, email {{privacy_email}}.',
            '17.1 When you close your account:' => '17.1 To close your account, email {{privacy_email}}. When it’s closed:',
            'under **Account → Legal** and at' => 'under **Account → Legal and privacy** and at',
            'You can close your account at any time under **Account → Privacy → Delete my account**.' => 'You can close your account at any time by emailing {{support_email}}.',
            '**Fonts and icons** currently load from' => '**Fonts and icons** load from',
        ]);

        // A proposed figure stays ("[[30 days, proposed]]" becomes "30 days"); notes for the reviewer go
        return preg_replace_callback('/([ \t]*)\[\[(.+?)\]\]/s', function ($m) {
            $value = trim(preg_replace('/[,;]\s*(proposed|advocate)\b.*$/s', '', $m[2]));
            if ($value === '' || preg_match('/^(proposed|advocate|confirm|choose|remove this|if the)\b/i', $value)) {
                return '';
            }

            return $m[1].$value;
        }, $body);
    }
}
