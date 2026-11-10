<?php

namespace Tests\Feature\Api;

use App\Models\MessageAttachment;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\ProviderApplications;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ChatAttachmentsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function provider(string $name = 'Okello Mukasa'): ProviderProfile
    {
        $owner = User::factory()->create(['name' => $name]);
        $service = app(ProviderApplications::class);
        $service->saveDetails($owner, ['type' => 'individual', 'display_name' => $name.' Plumbing', 'professions' => [['profession' => 'plumber']], 'base_area' => 'ntinda']);
        $service->approve($service->submit($owner), $this->userWithRoles(['provider_review']));

        return $owner->providerProfile()->first();
    }

    /** The signed-in customer's chat with the provider */
    private function chatWith(ProviderProfile $provider): int
    {
        return $this->postJson("/api/v1/me/conversations/provider/{$provider->id}")->assertOk()->json('data.id');
    }

    private function pdf(string $name = 'Quote for Nakato.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF");
    }

    public function test_photos_and_pdfs_are_sent_and_only_the_two_sides_can_open_them()
    {
        $provider = $this->provider();
        $customer = $this->signIn(User::factory()->create(['name' => 'Nakato Sarah']));
        $chat = $this->chatWith($provider);

        // Files alone are enough: no text needed
        $sent = $this->post("/api/v1/me/conversations/{$chat}/messages", [
            'attachments' => [UploadedFile::fake()->image('leak.jpg', 3000, 2000), $this->pdf()],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('', $sent['body']);
        $this->assertCount(2, $sent['attachments']);
        [$photo, $file] = $sent['attachments'];
        $this->assertSame('photo', $photo['kind']);
        $this->assertSame(1600, $photo['width']);   // re-drawn at the full size
        $this->assertSame('file', $file['kind']);
        $this->assertSame('Quote for Nakato.pdf', $file['name']);
        $this->assertNull($file['thumb_url']);
        // Links only, never storage paths
        $this->assertStringNotContainsString('chat/', json_encode($sent['attachments']));

        // The customer and the provider can open them
        foreach ([$customer, $provider->owner] as $side) {
            $this->signIn($side);
            $this->get($photo['url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
            $this->get($photo['thumb_url'])->assertOk();
            $response = $this->get($file['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }

        // The provider sees them in the chat and in the list of chats
        $this->getJson("/api/v1/me/conversations/{$chat}")->assertJsonPath('data.messages.0.attachments.0.kind', 'photo');
        $this->getJson('/api/v1/me/conversations')->assertJsonPath('data.0.last_message.attachments.1.kind', 'file');

        // Anyone else gets "not found"
        $this->signIn(User::factory()->create());
        $this->get($photo['url'])->assertNotFound();
        $this->get($file['url'])->assertNotFound();
    }

    public function test_a_file_cant_be_reached_through_another_chat()
    {
        $customer = $this->signIn(User::factory()->create());
        $first = $this->chatWith($this->provider('Okello Mukasa'));
        $second = $this->chatWith($this->provider('Waiswa Brian'));

        $id = $this->post("/api/v1/me/conversations/{$first}/messages", ['attachments' => [$this->pdf()]], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.attachments.0.id');

        $this->get("/api/v1/me/conversations/{$first}/attachments/{$id}.pdf")->assertOk();
        // Same person, but the file isn't part of this chat
        $this->get("/api/v1/me/conversations/{$second}/attachments/{$id}.pdf")->assertNotFound();
        // A PDF has no photo versions
        $this->get("/api/v1/me/conversations/{$first}/attachments/{$id}.jpg")->assertNotFound();
        $this->get("/api/v1/me/conversations/{$first}/attachments/{$id}-t.jpg")->assertNotFound();
    }

    public function test_only_photos_and_real_pdfs_are_accepted()
    {
        $provider = $this->provider();
        $this->signIn(User::factory()->create());
        $chat = $this->chatWith($provider);
        $send = function (array $data) use ($chat) {
            return $this->post("/api/v1/me/conversations/{$chat}/messages", $data, ['Accept' => 'application/json']);
        };

        $send([])->assertStatus(422)->assertJsonValidationErrors('body');
        $send(['body' => '   '])->assertStatus(422);
        $send(['attachments' => [UploadedFile::fake()->create('notes.txt', 10, 'text/plain')]])->assertStatus(422)->assertJsonValidationErrors('attachments.0');
        $send(['attachments' => [UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload')]])->assertStatus(422);
        $send(['attachments' => array_fill(0, 6, UploadedFile::fake()->image('p.jpg'))])->assertStatus(422)->assertJsonValidationErrors('attachments');
        $send(['attachments' => [UploadedFile::fake()->image('huge.jpg')->size(11000)]])->assertStatus(422);

        $this->assertSame(0, MessageAttachment::count());
        // Text and a photo together
        $send(['body' => 'Here is the leak', 'attachments' => [UploadedFile::fake()->image('leak.png', 800, 600)]])
            ->assertCreated()->assertJsonPath('data.body', 'Here is the leak')->assertJsonPath('data.attachments.0.width', 800);
    }

    public function test_a_file_pretending_to_be_a_pdf_is_refused_and_nothing_is_kept()
    {
        $provider = $this->provider();
        $this->signIn(User::factory()->create());
        $chat = $this->chatWith($provider);

        $fake = UploadedFile::fake()->createWithContent('invoice.pdf', 'not a pdf at all');
        $this->post("/api/v1/me/conversations/{$chat}/messages", ['attachments' => [UploadedFile::fake()->image('ok.jpg'), $fake]], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(0, MessageAttachment::count());
        // Nothing from the refused message is left on disk
        $this->assertSame([], Storage::disk('local')->allFiles('chat'));
        $this->assertSame([], Storage::disk('local')->allFiles('chat-files'));
    }

    public function test_outsiders_cant_send_files_into_a_chat()
    {
        $provider = $this->provider();
        $this->signIn(User::factory()->create());
        $chat = $this->chatWith($provider);

        $this->signIn(User::factory()->create());
        $this->post("/api/v1/me/conversations/{$chat}/messages", ['attachments' => [$this->pdf()]], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertSame(0, MessageAttachment::count());
        $this->assertSame([], Storage::disk('local')->allFiles('chat-files'));
    }
}
