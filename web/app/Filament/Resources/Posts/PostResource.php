<?php

namespace App\Filament\Resources\Posts;

use App\Filament\NavGroups;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Posts\Pages\ViewPost;
use App\Filament\Resources\Posts\RelationManagers\DeliveriesRelationManager;
use App\Models\AuditLog;
use App\Models\Post;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** "Postlar": the posts sent through the bot and their numbers (delivered, Tanishdim, used the bot after it came). */
class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::CITIZENS;

    protected static ?string $navigationLabel = 'Postlar';

    protected static ?string $modelLabel = 'post';

    protected static ?string $pluralModelLabel = 'Postlar';

    protected static ?int $navigationSort = 6;

    public const SEEN_HINT = 'Telegram shaxsiy chatda kim ko‘rganini botga bermaydi. «Ko‘rgan» — «Tanishdim» bosgan yoki post kelgandan keyin botdan foydalanganlar (taxminiy, kamida shuncha).';

    public static function canCreate(): bool
    {
        return false;  // through the "Post yuborish" button
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function statusLabel(Post $p): string
    {
        if ($p->recalled_at) {
            return $p->recall_finished_at
                ? 'Botdan o‘chirildi: '.$p->recalled.($p->recall_failed ? " (o‘chmadi: {$p->recall_failed})" : '')
                : 'Botdan o‘chirilmoqda…';
        }

        return $p->status === Post::SENDING ? 'Yuborilmoqda' : 'Yuborildi';
    }

    /** "N (x%)" of those it reached */
    public static function share(int $n, int $of): string
    {
        return $n.($of ? ' ('.round($n * 100 / $of).'%)' : '');
    }

    public static function table(Table $table): Table
    {
        $tz = config('app.display_timezone');

        return $table
            ->columns([
                TextColumn::make('created_at')->label('Sana')->dateTime('d.m.Y H:i')->timezone($tz)->sortable(),
                TextColumn::make('caption')->label('Post')->state(fn (Post $p) => $p->title())->wrap()
                    ->description(fn (Post $p) => (Post::MEDIA[$p->media_type] ?? 'Matn').($p->recalled_at ? ' · '.self::statusLabel($p) : '')),
                TextColumn::make('total')->label('Oluvchilar')->alignEnd(),
                TextColumn::make('delivered')->label('Yetkazildi')->alignEnd()
                    ->formatStateUsing(fn ($state, Post $p) => $p->status === Post::SENDING ? "{$state} / {$p->total} · yuborilmoqda" : $state)
                    ->description(fn (Post $p) => $p->failed ? "yetkazilmadi: {$p->failed}" : null),
                TextColumn::make('ack')->label('Tanishdim bosdi')->alignEnd()->color('success')
                    ->state(fn (Post $p) => $p->ack_button ? self::share($p->stats()['ack'], $p->delivered) : '—'),
                TextColumn::make('seen')->label('Ko‘rgan (taxminan)')->alignEnd()
                    ->state(fn (Post $p) => self::share($p->stats()['seen'], $p->delivered))
                    ->tooltip('«Tanishdim» bosgan yoki post kelgandan keyin botdan foydalangan'),
            ])
            ->recordActions([
                ViewAction::make()->label('Batafsil'),
                self::recallAction(),
                self::forgetAction(),
            ])
            ->recordUrl(fn (Post $p) => self::getUrl('view', ['record' => $p]))
            ->poll('15s')
            ->emptyStateHeading('Hali post yuborilmagan')
            ->emptyStateDescription('«Post yuborish» tugmasi orqali (shu sahifada yoki bosh sahifada).')
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        $tz = config('app.display_timezone');

        return $schema->components([
            Section::make()->columns(4)->columnSpanFull()->schema([
                TextEntry::make('created_at')->label('Yuborilgan')->dateTime('d.m.Y H:i')->timezone($tz),
                TextEntry::make('author.name')->label('Kim yubordi')->placeholder('—'),
                TextEntry::make('media_type')->label('Turi')->placeholder('Matn')
                    ->formatStateUsing(fn ($state) => Post::MEDIA[$state] ?? 'Matn')
                    ->url(fn (Post $p) => $p->media_path ? route('bot-post-media', $p) : null, shouldOpenInNewTab: true),
                TextEntry::make('status')->label('Holat')->badge()->state(fn (Post $p) => self::statusLabel($p))
                    ->color(fn (Post $p) => $p->recalled_at ? 'danger' : ($p->status === Post::SENDING ? 'warning' : 'success')),
                TextEntry::make('caption')->label('Matn')->columnSpanFull()->placeholder('—')->extraAttributes(['style' => 'white-space:pre-line']),
                ImageEntry::make('picture')->label('Rasm')->columnSpanFull()->imageHeight(260)->checkFileExistence(false)
                    ->state(fn (Post $p) => route('bot-post-media', $p))->visible(fn (Post $p) => $p->media_type === 'photo' && $p->media_path),
                TextEntry::make('video')->label('Video')->columnSpanFull()->visible(fn (Post $p) => $p->media_type === 'video' && $p->media_path)
                    ->state(fn (Post $p) => 'Videoni ochish'.($p->media_width ? " ({$p->media_width}×{$p->media_height}, {$p->media_duration} s)" : ''))
                    ->url(fn (Post $p) => route('bot-post-media', $p), shouldOpenInNewTab: true)->icon('heroicon-o-play'),
            ]),
            Section::make('Statistika')->columns(5)->columnSpanFull()->description(self::SEEN_HINT)
                ->schema(function (Post $record) {
                    $s = $record->stats();

                    return collect([
                        'total' => ['Oluvchilar', $s['total']],
                        'delivered' => ['Yetkazildi', $s['delivered'].($s['failed'] ? " (yetkazilmadi: {$s['failed']})" : '')],
                        'ack' => ['Tanishdim bosdi', $record->ack_button ? self::share($s['ack'], $s['delivered']) : 'tugma yo‘q'],
                        'active' => ['Postdan keyin botni ochgan', self::share($s['active'], $s['delivered'])],
                        'seen' => ['Ko‘rgan (taxminan)', self::share($s['seen'], $s['delivered'])],
                    ])->map(fn ($v, $k) => TextEntry::make('stat_'.$k)->label($v[0])->state($v[1])
                        ->size(TextSize::Large)->weight(FontWeight::Bold))->values()->all();
                }),
        ]);
    }

    /** "Botdan o'chirish": the bot deletes the post from every chat it reached. Telegram allows it for 48 hours only. */
    public static function recallAction(): Action
    {
        return Action::make('recall')->label('Botdan o‘chirish')->icon('heroicon-o-trash')->color('danger')
            ->visible(fn (Post $record) => $record->status === Post::SENT && ! $record->recalled_at && $record->delivered > 0)
            ->requiresConfirmation()
            ->modalHeading('Post barcha oluvchilar chatidan o‘chirilsinmi?')
            ->modalDescription(fn (Post $record) => "Bot {$record->delivered} ta oluvchi chatidan o‘chiradi. Statistika panelda qoladi. "
                .($record->recallHoursLeft() === 0
                    ? 'Diqqat: post 48 soatdan oldin yuborilgan. Telegram eski xabarlarni o‘chirishga ruxsat bermaydi, ko‘pchiligi o‘chmasligi mumkin.'
                    : 'Telegram faqat 48 soat ichida o‘chirishga ruxsat beradi: '.$record->recallHoursLeft().' soat qoldi.'))
            ->modalSubmitActionLabel('Ha, botdan o‘chirish')
            ->action(function (Post $record) {
                $record->update(['recalled_at' => now()]);
                AuditLog::record('post.recalled', ['post' => $record->id, 'recipients' => $record->delivered]);
                Notification::make()->success()->title('Post botdan o‘chirilmoqda')->body('Natija shu sahifada ko‘rinadi.')->send();
            });
    }

    /**
     * "Ro'yxatdan o'chirish" loses the message ids: the post could no longer be taken back from the chats (happened
     * once). So only after "Botdan o'chirish", or when Telegram would refuse anyway (48 hours), or nobody got it.
     */
    public static function forgetAction(): DeleteAction
    {
        return DeleteAction::make()->label('Ro‘yxatdan o‘chirish')
            ->visible(fn (Post $record) => $record->canBeForgotten())
            ->modalHeading('Post panel ro‘yxatidan o‘chirilsinmi?')
            ->modalDescription('Faqat statistika va fayl o‘chadi. Botdagi xabarlarga tegilmaydi.');
    }

    public static function getRelations(): array
    {
        return [DeliveriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'view' => ViewPost::route('/{record}'),
        ];
    }
}
