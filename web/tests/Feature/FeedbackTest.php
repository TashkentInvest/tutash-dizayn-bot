<?php

namespace Tests\Feature;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Resources\Feedback\Pages\ListFeedback;
use App\Models\Feedback;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** Citizens' requests: list, answer from the dashboard. Runs against a COPY of the bot database. */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    private User $admin;

    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = storage_path('framework/testing/feedback-'.uniqid());
        File::makeDirectory($this->tmp, 0777, true);
        copy(base_path('../bot.db'), $this->tmp.'/bot.db');
        config(['database.connections.bot.database' => $this->tmp.'/bot.db']);
        DB::purge('bot');

        $this->admin = User::factory()->create();
        config(['app.admin_emails' => [$this->admin->email]]);
        $this->actingAs($this->admin);

        $this->uid = 777000111;
        DB::connection('bot')->table('users')->insert(['user_id' => $this->uid, 'first_name' => 'T', 'full_name' => 'Karimov Alisher', 'phone' => '+998901234567']);
    }

    protected function tearDown(): void
    {
        DB::purge('bot');
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function request(string $level, string $text): Feedback
    {
        $id = DB::connection('bot')->table('feedback')->insertGetId(['user_id' => $this->uid, 'level' => $level, 'text' => $text]);

        return Feedback::findOrFail($id);
    }

    public function test_list_shows_requests_with_level_and_badge(): void
    {
        $high = $this->request('high', 'Ko‘chada yoritish yo‘q, kechasi xavfli');
        $low = $this->request('low', 'Skameykalar qo‘shilsa yaxshi bo‘lardi');
        $low->forceFill(['answer' => 'Rahmat', 'answered_at' => now()->utc()])->save();

        Livewire::test(ListFeedback::class)
            ->assertCanSeeTableRecords([$high, $low])
            ->assertSee('Yuqori')->assertSee('Karimov Alisher')
            ->filterTable('status', 'new')->assertCanSeeTableRecords([$high])->assertCanNotSeeTableRecords([$low]);

        $this->assertSame('1', FeedbackResource::getNavigationBadge());
        $this->get('/admin/murojaatlar')->assertOk();
    }

    public function test_answer_is_saved_once_for_the_bot_to_deliver(): void
    {
        $f = $this->request('medium', 'Bekat yonida chiqindi qutisi kerak');

        Livewire::test(ListFeedback::class)
            ->callAction(TestAction::make('answer')->table($f), ['answer' => '  Murojaatingiz ko‘rib chiqildi, quti o‘rnatiladi.  '])
            ->assertHasNoActionErrors();

        $f->refresh();
        $this->assertSame('Murojaatingiz ko‘rib chiqildi, quti o‘rnatiladi.', $f->answer);
        $this->assertSame($this->admin->email, $f->answered_by);
        $this->assertNull($f->answer_sent_at);
        $this->assertSame('answered', $f->status());

        // answered requests cannot be answered again (the message already went out)
        Livewire::test(ListFeedback::class)->assertActionHidden(TestAction::make('answer')->table($f));
    }

    public function test_answer_is_required(): void
    {
        $f = $this->request('low', 'Yo‘lak tor, kengaytirish kerak');

        Livewire::test(ListFeedback::class)
            ->callAction(TestAction::make('answer')->table($f), ['answer' => ''])
            ->assertHasActionErrors(['answer' => 'required']);

        $this->assertNull($f->refresh()->answer);
    }
}
