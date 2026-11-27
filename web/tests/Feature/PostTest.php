<?php

namespace Tests\Feature;

use App\Filament\Actions\PostAction;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\Pages\ViewPost;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Resources\Posts\RelationManagers\DeliveriesRelationManager;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\PostDelivery;
use App\Models\User;
use App\Services\PostAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

/** "Post yuborish": who it goes to, what is saved for the bot, the numbers, taking it back. Runs on a COPY of the bot database. */
class PostTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    private User $admin;

    private int $district;

    private int $otherDistrict;

    // Telegram ids: a staff member here, one in another district, a leader, a citizen, a citizen who blocked the bot
    private const STAFF = 801, STAFF2 = 802, LEADER = 803, CITIZEN = 804, BLOCKED = 805;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = storage_path('framework/testing/posts-'.uniqid());
        File::makeDirectory($this->tmp.'/uploads/posts', 0777, true);
        copy(base_path('../tests/fixtures/bot.sqlite'), $this->tmp.'/bot.db');
        config([
            'database.connections.bot.database' => $this->tmp.'/bot.db',
            'bot.uploads_path' => $this->tmp.'/uploads',
            'filesystems.disks.bot_uploads.root' => $this->tmp.'/uploads',
        ]);
        DB::purge('bot');
        $db = DB::connection('bot');
        foreach (['post_deliveries', 'posts', 'staff', 'report_recipients', 'users'] as $t) {
            $db->table($t)->delete();
        }
        $this->district = (int) $db->table('districts')->orderBy('id')->value('id');
        $this->otherDistrict = (int) $db->table('districts')->where('id', '!=', $this->district)->orderBy('id')->value('id');
        foreach ([self::STAFF => 'Aliyev Vali', self::STAFF2 => 'Karimov Bek', self::LEADER => 'Rahbar Ota', self::CITIZEN => 'Fuqaro Ona', self::BLOCKED => 'Bloklagan'] as $id => $name) {
            $db->table('users')->insert(['user_id' => $id, 'first_name' => 'X', 'full_name' => $name, 'last_seen' => '2026-01-01 00:00:00']);
        }
        $db->table('users')->where('user_id', self::BLOCKED)->update(['blocked_at' => '2026-01-01 00:00:00']);
        $db->table('staff')->insert(['full_name' => 'Aliyev Vali', 'phone' => '+998900000801', 'district_id' => $this->district, 'user_id' => self::STAFF, 'is_active' => true]);
        $db->table('staff')->insert(['full_name' => 'Karimov Bek', 'phone' => '+998900000802', 'district_id' => $this->otherDistrict, 'user_id' => self::STAFF2, 'is_active' => true]);
        $db->table('report_recipients')->insert(['user_id' => self::LEADER, 'label' => 'Rahbar']);

        $this->admin = User::factory()->create();
        config(['app.admin_emails' => [$this->admin->email]]);
    }

    protected function tearDown(): void
    {
        DB::purge('bot');
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    public function test_citizens_get_it_only_when_ticked_and_blocked_never(): void
    {
        $default = PostAudience::options(array_keys(PostAudience::GROUPS));
        $this->assertEqualsCanonicalizing([self::STAFF, self::STAFF2, self::LEADER], array_map('intval', array_keys($default)));
        $this->assertStringContainsString('Aliyev Vali · xodim', $default[(string) self::STAFF]);
        $this->assertStringContainsString('rahbar', $default[(string) self::LEADER]);

        $all = PostAudience::options(array_keys(PostAudience::groups()));
        $this->assertArrayHasKey((string) self::CITIZEN, $all);
        $this->assertStringContainsString('aholi', $all[(string) self::CITIZEN]);
        $this->assertArrayNotHasKey((string) self::BLOCKED, $all);

        // a district narrows the staff only
        $one = PostAudience::options([PostAudience::STAFF, PostAudience::CITIZENS], [$this->district]);
        $this->assertEqualsCanonicalizing([self::STAFF, self::CITIZEN], array_map('intval', array_keys($one)));
    }

    public function test_a_picture_post_is_saved_with_a_pending_delivery_per_person(): void
    {
        $this->actingAs($this->admin);
        file_put_contents($this->tmp.'/uploads/posts/a.jpg', base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
        $post = PostAction::create(['media' => 'posts/a.jpg', 'caption' => "  Salom <b>hammaga</b> & rahmat  ",
            'groups' => array_keys(PostAudience::GROUPS), 'people' => [self::STAFF, self::LEADER, self::BLOCKED, self::CITIZEN], 'ack_button' => true]);

        $this->assertSame('photo', $post->media_type);
        $this->assertSame('Salom <b>hammaga</b> & rahmat', $post->caption);  // stored as written: the bot escapes it when sending
        $this->assertSame(Post::SENDING, $post->status);
        // the blocked one is dropped; the citizen was in the list, so ticked on purpose
        $this->assertSame(3, $post->total);
        $this->assertEqualsCanonicalizing([self::STAFF, self::LEADER, self::CITIZEN], $post->deliveries()->pluck('user_id')->all());
        $this->assertSame([PostDelivery::PENDING], $post->deliveries()->distinct()->pluck('status')->all());
        $this->assertSame('post.created', AuditLog::query()->latest('id')->value('action'));
        $this->assertSame(3, AuditLog::query()->latest('id')->first()->data['recipients']);
    }

    public function test_a_video_gets_its_upright_size_and_the_text_is_not_transliterated(): void
    {
        $this->actingAs($this->admin);
        file_put_contents($this->tmp.'/uploads/posts/v.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");
        // filmed upright on a phone: stored 1920x1080 with a 90 degree rotation
        Process::fake(['*' => Process::result(json_encode(['streams' => [['width' => 1920, 'height' => 1080, 'side_data_list' => [['rotation' => -90]]]], 'format' => ['duration' => '12.4']]))]);
        $post = PostAction::create(['media' => 'posts/v.mp4', 'caption' => 'Ҳурматли ходимлар', 'groups' => [PostAudience::STAFF], 'people' => [self::STAFF]]);

        $this->assertSame('video', $post->media_type);
        $this->assertSame([1080, 1920, 12], [$post->media_width, $post->media_height, $post->media_duration]);
        $this->assertSame('Ҳурматли ходимлар', $post->caption);
    }

    public function test_the_numbers(): void
    {
        $post = $this->sentPost();
        $db = DB::connection('bot');
        // STAFF pressed Tanishdim; LEADER used the bot after the post; CITIZEN neither; STAFF2 not reached
        $db->table('post_deliveries')->where('post_id', $post->id)->where('user_id', self::STAFF)->update(['ack_at' => '2026-11-27 10:05:00']);
        $db->table('users')->where('user_id', self::LEADER)->update(['last_seen' => '2026-11-27 11:00:00']);

        $this->assertSame(['total' => 4, 'delivered' => 3, 'failed' => 1, 'ack' => 1, 'active' => 1, 'seen' => 2], $post->fresh()->stats());
        $this->assertSame('1 (33%)', PostResource::share(1, 3));
    }

    public function test_pages_open_and_the_recipient_filter_works(): void
    {
        $post = $this->sentPost();
        DB::connection('bot')->table('post_deliveries')->where('post_id', $post->id)->where('user_id', self::STAFF)->update(['ack_at' => '2026-11-27 10:05:00']);
        $this->actingAs($this->admin);

        $this->get(PostResource::getUrl('index'))->assertOk()->assertSee('Postlar')->assertSee('Post yuborish');
        $this->get(PostResource::getUrl('view', ['record' => $post]))->assertOk()->assertSee('Statistika')->assertSee('Tanishdim bosdi');
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('Post yuborish');
        Livewire::test(\App\Filament\Widgets\PostsStats::class)->assertSee('Botdagi postlar')->assertSee('Test');  // widgets load lazily

        $rows = fn (string $view) => Livewire::test(DeliveriesRelationManager::class, ['ownerRecord' => $post, 'pageClass' => ViewPost::class])
            ->filterTable('view', $view);
        $byUser = fn (int $id) => PostDelivery::query()->where('post_id', $post->id)->where('user_id', $id)->first();
        $rows('ack')->assertCanSeeTableRecords([$byUser(self::STAFF)])->assertCanNotSeeTableRecords([$byUser(self::LEADER)]);
        $rows('unseen')->assertCanSeeTableRecords([$byUser(self::LEADER), $byUser(self::CITIZEN)])->assertCanNotSeeTableRecords([$byUser(self::STAFF)]);
        $rows('failed')->assertCanSeeTableRecords([$byUser(self::STAFF2)])->assertCanNotSeeTableRecords([$byUser(self::STAFF)]);
    }

    public function test_the_modal_sends_a_text_post_and_the_list_follows_the_groups(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(ListPosts::class)
            ->mountAction('post')
            ->assertSchemaStateSet(['people' => array_keys(PostAudience::options(array_keys(PostAudience::GROUPS)))], 'mountedActionSchema0')
            ->fillForm(['groups' => [PostAudience::LEADERS]])
            ->assertSchemaStateSet(['people' => [(string) self::LEADER]], 'mountedActionSchema0')
            ->fillForm(['caption' => 'Ertaga yig‘ilish soat 9:00 da'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $post = Post::query()->latest('id')->first();
        $this->assertSame('Ertaga yig‘ilish soat 9:00 da', $post->caption);
        $this->assertNull($post->media_type);
        $this->assertSame([self::LEADER], $post->deliveries()->pluck('user_id')->all());
    }

    public function test_taking_back_asks_the_bot_and_keeps_the_numbers(): void
    {
        $post = $this->sentPost();
        $this->actingAs($this->admin);
        $before = $post->stats();

        $page = Livewire::test(ViewPost::class, ['record' => $post->getRouteKey()])
            ->assertActionVisible('recall')->assertActionHidden('delete');  // the message ids are still needed
        $page->callAction('recall');

        $post->refresh();
        $this->assertNotNull($post->recalled_at);  // the bot deletes the messages (bot/posts.py)
        $this->assertSame($before, $post->stats());
        $this->assertSame('post.recalled', AuditLog::query()->latest('id')->value('action'));
        Livewire::test(ViewPost::class, ['record' => $post->getRouteKey()])->assertActionHidden('recall')->assertActionVisible('delete');

        // older than 48 hours: Telegram would refuse anyway, so it may go from the list
        $old = $this->sentPost();
        $old->update(['created_at' => now()->subHours(49)]);
        $this->assertSame(0, $old->fresh()->recallHoursLeft());
        $this->assertTrue($old->fresh()->canBeForgotten());
    }

    public function test_someone_who_is_not_an_admin_sees_nothing(): void
    {
        $post = $this->sentPost();
        file_put_contents($this->tmp.'/uploads/posts/x.jpg', 'x');
        $post->update(['media_type' => 'photo', 'media_path' => 'posts/x.jpg']);
        $this->actingAs(User::factory()->create());

        $this->get(PostResource::getUrl('index'))->assertForbidden();
        $this->get(PostResource::getUrl('view', ['record' => $post]))->assertForbidden();
        $this->get(route('bot-post-media', $post))->assertForbidden();
        $this->actingAs($this->admin)->get(route('bot-post-media', $post))->assertOk();
    }

    /** a post the bot has sent: STAFF2 could not be reached */
    private function sentPost(): Post
    {
        $post = Post::create(['caption' => 'Test', 'status' => Post::SENT, 'total' => 4, 'delivered' => 3, 'failed' => 1,
            'ack_button' => true, 'created_at' => now()->subHour(), 'finished_at' => now()]);
        foreach ([self::STAFF, self::LEADER, self::CITIZEN] as $i => $id) {
            PostDelivery::query()->insert(['post_id' => $post->id, 'user_id' => $id, 'status' => PostDelivery::SENT,
                'message_id' => 100 + $i, 'sent_at' => '2026-11-27 10:00:00']);
        }
        PostDelivery::query()->insert(['post_id' => $post->id, 'user_id' => self::STAFF2, 'status' => PostDelivery::FAILED, 'error' => 'Forbidden: bot was blocked by the user']);

        return $post;
    }
}
