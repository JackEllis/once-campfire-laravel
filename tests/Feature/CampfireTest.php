<?php

namespace Tests\Feature;

use App\Jobs\DeliverMessageNotifications;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Room;
use App\Models\User;
use App\Support\BlobStorage;
use App\Support\Media;
use App\Support\MessageWriter;
use App\Support\RailsCrypto;
use App\Support\RichTextRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CampfireTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::unprepared(file_get_contents(database_path('schema.sql')));
        Queue::fake();
    }

    private function fixture(): array
    {
        $u = User::create(['name' => 'David', 'email_address' => 'david@example.org', 'password_digest' => password_hash('secret123456', PASSWORD_BCRYPT), 'role' => 1, 'status' => 0]);
        $room = Room::create(['name' => 'Watercooler', 'type' => 'Rooms::Open', 'creator_id' => $u->id]);
        Membership::create(['room_id' => $room->id, 'user_id' => $u->id, 'involvement' => 'mentions']);
        DB::table('accounts')->insert(['name' => 'Campfire', 'join_code' => 'abcd-efgh-ijkl', 'created_at' => now(), 'updated_at' => now()]);

        return [$u, $room];
    }

    private function auth(User $u): void
    {
        $token = 'local-fixture-session';
        DB::table('sessions')->insert(['token' => $token, 'user_id' => $u->id, 'last_active_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->withUnencryptedCookie('session_token', app(RailsCrypto::class)->signCookie('session_token', $token));
    }

    public function test_message_writes_index_room_unread_and_notifications_after_commit(): void
    {
        [$u,$room] = $this->fixture();
        $other = User::create(['name' => 'Jason', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $other->id, 'involvement' => 'everything']);
        $message = app(MessageWriter::class)->create($room, $u, ['body' => '<p>Coffee and chatting</p>']);
        $this->assertSame('Coffee and chatting', $message->fresh()->plainText());
        $this->assertSame($message->id, (int) DB::selectOne("SELECT rowid FROM message_search_index WHERE body MATCH 'coffee'")->rowid);
        $this->assertNotNull($room->memberships()->where('user_id', $other->id)->value('unread_at'));
        Queue::assertPushed(DeliverMessageNotifications::class);
        app(MessageWriter::class)->update($message, ['body' => '<p>Tea</p>']);
        $this->assertSame(0, count(DB::select("SELECT rowid FROM message_search_index WHERE body MATCH 'coffee'")));
        app(MessageWriter::class)->destroy($message);
        $this->assertSame(0, DB::table('messages')->count());
        $this->assertSame(0, DB::table('action_text_rich_texts')->count());
    }

    public function test_authentication_and_populated_room_html(): void
    {
        [$u,$room] = $this->fixture();
        app(MessageWriter::class)->create($room, $u, ['body' => '<p>Hello Campfire</p>']);
        $this->get('/rooms/'.$room->id)->assertRedirect('/session/new');
        $this->auth($u);
        $this->get('/rooms/'.$room->id)->assertOk()->assertSee('Hello Campfire')->assertSee('RoomMessagesChannel');
        $this->get('/users/me/sidebar')->assertOk()->assertSee('Watercooler');
        $this->get('/searches?q=Hello')->assertOk()->assertSee('Hello Campfire');
    }

    public function test_nonmember_cannot_read_or_write_even_open_rooms(): void
    {
        [$u,$room] = $this->fixture();
        $stranger = User::create(['name' => 'Stranger', 'role' => 0, 'status' => 0]);
        $this->auth($stranger);
        $this->get('/rooms/'.$room->id)->assertNotFound();
        $this->post('/rooms/'.$room->id.'/messages', ['message' => ['body' => 'forbidden']])->assertNotFound();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_message_edit_permission_and_cross_room_ids(): void
    {
        [$u,$room] = $this->fixture();
        $m = app(MessageWriter::class)->create($room, $u, ['body' => 'hello']);
        $member = User::create(['name' => 'Member', 'role' => 0, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $member->id, 'involvement' => 'mentions']);
        $this->auth($member);
        $this->patch('/rooms/'.$room->id.'/messages/'.$m->id, ['message' => ['body' => 'stolen']])->assertForbidden();
        $this->delete('/rooms/'.$room->id.'/messages/'.$m->id)->assertForbidden();
    }

    public function test_direct_room_cannot_change_audience_or_type(): void
    {
        [$u,$room] = $this->fixture();
        $direct = Room::create(['name' => null, 'type' => 'Rooms::Direct', 'creator_id' => $u->id]);
        Membership::create(['room_id' => $direct->id, 'user_id' => $u->id, 'involvement' => 'everything']);
        $this->auth($u);
        $this->patch('/rooms/opens/'.$direct->id, ['room' => ['name' => 'public']])->assertNotFound();
        $this->assertSame('Rooms::Direct', $direct->fresh()->type);
    }

    public function test_rollback_does_not_enqueue_notifications(): void
    {
        [$u,$room] = $this->fixture();
        try {
            DB::transaction(function () use ($u, $room) {
                app(MessageWriter::class)->create($room, $u, ['body' => 'rollback']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }$this->assertDatabaseCount('messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_mentions_preserve_signed_reference_and_safe_html(): void
    {
        [$u,$room] = $this->fixture();
        $sgid = app(RailsCrypto::class)->sgid($u->id);
        $body = '<p>Hi <action-text-attachment sgid="'.$sgid.'" content-type="application/vnd.campfire.mention"></action-text-attachment><script>alert(1)</script></p>';
        $m = app(MessageWriter::class)->create($room, $u, ['body' => $body]);
        $stored = $m->fresh()->richText->body;
        $this->assertStringContainsString('action-text-attachment', $stored);
        $renderer = app(RichTextRenderer::class);
        $this->assertSame([$u->id], $renderer->mentions($stored));
        $this->assertStringContainsString('David', $renderer->html($stored));
        $this->assertStringNotContainsString('<script', $renderer->html($stored));
    }

    public function test_native_bot_raw_body_api_and_boost(): void
    {
        [$u,$room] = $this->fixture();
        $bot = User::create(['name' => 'Bender', 'bot_token' => 'BenderBot123', 'role' => 2, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $bot->id, 'involvement' => 'mentions']);
        $path = '/rooms/'.$room->id.'/'.$bot->id.'-'.$bot->bot_token.'/messages';
        $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'Coffee from Bender')->assertStatus(201);
        $m = Message::first();
        $this->assertSame($bot->id, $m->creator_id);
        $this->assertSame('Coffee from Bender', $m->plainText());
        $this->call('POST', $path.'/'.$m->id.'/boosts', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '👍')->assertStatus(201);
        $this->assertDatabaseHas('boosts', ['booster_id' => $bot->id, 'message_id' => $m->id, 'content' => '👍']);
        $this->get('/rooms/'.$room->id.'?bot_key='.$bot->id.'-'.$bot->bot_token)->assertForbidden();
    }

    public function test_webhook_callback_reply_is_real_native_message_and_does_not_loop(): void
    {
        [$u,$room] = $this->fixture();
        $room->update(['type' => 'Rooms::Direct']);
        $bot = User::create(['name' => 'Bender', 'bot_token' => 'BenderBot123', 'role' => 2, 'status' => 0]);
        Membership::create(['room_id' => $room->id, 'user_id' => $bot->id, 'involvement' => 'everything']);
        DB::table('webhooks')->insert(['user_id' => $bot->id, 'url' => 'http://fixture.test/hook', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['fixture.test/*' => Http::response('Hello from bot', 200, ['Content-Type' => 'text/plain'])]);
        $m = app(MessageWriter::class)->create($room, $u, ['body' => 'Hi bot'], true);
        (new DeliverMessageNotifications($m->id, true))->handle();
        $this->assertSame(2, Message::count());
        $reply = Message::where('creator_id', $bot->id)->first();
        $this->assertSame('Hello from bot', $reply->plainText());
        Http::assertSent(fn ($r) => $r->url() === 'http://fixture.test/hook' && $r['message']['id'] === $m->id);
        Queue::assertPushed(DeliverMessageNotifications::class, fn ($job) => $job->messageId === $reply->id && ! $job->webhooks);
    }

    public function test_real_image_upload_is_analyzed_and_synchronously_thumbnailed(): void
    {
        [$user, $room] = $this->fixture();
        $directory = storage_path('framework/testing/media-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0755, true);
        config(['campfire.files' => $directory]);
        $source = $directory.'/pixel.png';
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aY2kAAAAASUVORK5CYII='));
        try {
            $upload = new UploadedFile($source, 'pixel.png', 'image/png', null, true);
            $message = app(MessageWriter::class)->create($room, $user, ['body' => '', 'attachment' => $upload]);
            $blob = $message->attachment->blob;
            $metadata = json_decode($blob->metadata, true);
            $this->assertSame($message->id, (int) DB::selectOne("SELECT rowid FROM message_search_index WHERE body MATCH 'pixel'")->rowid);
            $this->assertSame(1, $metadata['width']);
            $this->assertSame(1, $metadata['height']);
            $this->assertSame(base64_encode(md5(file_get_contents($source), true)), $blob->checksum);
            $variant = app(Media::class)->variant($blob, ['resize_to_limit' => [1200, 800], 'format' => 'webp']);
            $this->assertFileExists($variant);
            $this->assertSame('image/webp', mime_content_type($variant));
            app(MessageWriter::class)->destroy($message);
            $this->assertDatabaseCount('active_storage_blobs', 0);
            $this->assertFileDoesNotExist($variant);
            $this->assertFileDoesNotExist(app(BlobStorage::class)->path($blob));
            DB::beginTransaction();
            $pending = app(MessageWriter::class)->create($room, $user, ['attachment' => $upload]);
            $pendingBlob = $pending->attachment->blob;
            $pendingPath = app(BlobStorage::class)->path($pendingBlob);
            $this->assertFileExists($pendingPath);
            DB::rollBack();
            $this->assertFileDoesNotExist($pendingPath);
            $this->assertDatabaseCount('messages', 0);
            $this->assertDatabaseCount('active_storage_blobs', 0);
        } finally {
            (new Process(['rm', '-rf', $directory]))->mustRun();
        }
    }

    public function test_failed_image_analysis_rolls_back_records_and_files(): void
    {
        [$user, $room] = $this->fixture();
        $directory = storage_path('framework/testing/failure-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0755, true);
        config(['campfire.files' => $directory]);
        $source = $directory.'/source.txt';
        file_put_contents($source, 'fixture upload');
        $this->app->instance(Media::class, new class
        {
            public function variant(): string
            {
                throw new \RuntimeException('Analysis failure');
            }
        });
        $upload = new class($source, 'picture.png', 'image/png', null, true) extends UploadedFile
        {
            public function getMimeType(): ?string
            {
                return 'image/png';
            }
        };
        try {
            app(MessageWriter::class)->create($room, $user, ['attachment' => $upload]);
            $this->fail('Analysis failure must abort the transaction');
        } catch (\RuntimeException $error) {
            $this->assertSame('Analysis failure', $error->getMessage());
            $this->assertDatabaseCount('messages', 0);
            $this->assertDatabaseCount('active_storage_blobs', 0);
            $files = iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)));
            $this->assertCount(1, array_filter($files, fn ($file) => $file->isFile()));
            Queue::assertNothingPushed();
        } finally {
            (new Process(['rm', '-rf', $directory]))->mustRun();
        }
    }

    public function test_fractional_timestamp_pagination_excludes_its_cursor(): void
    {
        [$user, $room] = $this->fixture();
        $this->auth($user);
        $messages = [];
        foreach (['100000', '200000', '300000'] as $fraction) {
            $message = app(MessageWriter::class)->create($room, $user, ['body' => $fraction]);
            $message->update(['created_at' => '2026-01-01 12:00:00.'.$fraction]);
            $messages[] = $message;
        }
        $before = $this->get('/rooms/'.$room->id.'/messages?before='.$messages[1]->id, ['Accept' => 'application/json'])->assertOk()->json();
        $after = $this->get('/rooms/'.$room->id.'/messages?after='.$messages[1]->id, ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame([$messages[0]->id], array_column($before, 'id'));
        $this->assertSame([$messages[2]->id], array_column($after, 'id'));
    }
}
