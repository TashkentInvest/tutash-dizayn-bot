<?php

namespace App\Filament\Resources\Feedback;

use App\Filament\Resources\Feedback\Pages\ListFeedback;
use App\Models\Feedback;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Citizens' requests (objections, needs, suggestions) sent through the bot; an answer written here reaches the user in Telegram. */
class FeedbackResource extends Resource
{
    public const ANSWER_MAX = 3000; // with the quoted request it stays under Telegram's 4096-character message limit

    protected static ?string $model = Feedback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $modelLabel = 'murojaat';

    protected static ?string $pluralModelLabel = 'Murojaatlar';

    protected static ?string $slug = 'murojaatlar';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    /** Unanswered requests, shown next to the menu item. */
    public static function getNavigationBadge(): ?string
    {
        $n = Feedback::query()->whereNull('answer')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('№')->sortable(),
                TextColumn::make('created_at')->label('Vaqt')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('user.full_name')->label('Fuqaro')->placeholder('—')
                    ->description(fn (Feedback $r) => $r->user?->phone)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'user',
                        fn ($u) => $u->where('full_name', 'like', "%$search%")->orWhere('phone', 'like', "%$search%")
                    )),
                TextColumn::make('level')->label('Daraja')->badge()->sortable()
                    ->formatStateUsing(fn (string $state) => Feedback::LEVELS[$state] ?? $state)
                    ->color(fn (string $state) => Feedback::LEVEL_COLORS[$state] ?? 'gray'),
                TextColumn::make('text')->label('Murojaat')->limit(80)->wrap()->searchable()
                    ->tooltip(fn (Feedback $r) => mb_strlen($r->text) > 80 ? $r->text : null),
                TextColumn::make('status')->label('Holat')->badge()
                    ->state(fn (Feedback $r) => $r->status())
                    ->formatStateUsing(fn (string $state) => Feedback::STATUSES[$state])
                    ->color(fn (string $state) => Feedback::STATUS_COLORS[$state])
                    ->tooltip(fn (Feedback $r) => $r->answer_error),
                TextColumn::make('answered_at')->label('Javob vaqti')->dateTime('d.m.Y H:i')->sortable()->placeholder('—')
                    ->description(fn (Feedback $r) => $r->answered_by)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('level')->label('Daraja')->options(Feedback::LEVELS)->multiple(),
                SelectFilter::make('status')->label('Holat')->options(Feedback::STATUSES)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'new' => $query->whereNull('answer'),
                        'answered' => $query->whereNotNull('answer')->whereNull('answer_sent_at'),
                        'delivered' => $query->whereNotNull('answer_sent_at')->whereNull('answer_error'),
                        'failed' => $query->whereNotNull('answer_error'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('view')->label('Ko‘rish')->icon('heroicon-o-eye')->color('gray')
                    ->modalHeading(fn (Feedback $r) => "Murojaat №{$r->id}")
                    ->modalSubmitAction(false)->modalCancelActionLabel('Yopish')
                    ->schema(fn (Feedback $r) => self::details($r)),
                Action::make('answer')->label('Javob berish')->icon('heroicon-o-paper-airplane')->color('primary')
                    ->visible(fn (Feedback $r) => $r->answer === null)
                    ->modalHeading(fn (Feedback $r) => "Murojaat №{$r->id} ga javob")
                    ->modalDescription('Javob fuqaroga bot orqali Telegramda yuboriladi. Yuborilgandan keyin uni o‘zgartirib bo‘lmaydi.')
                    ->modalSubmitActionLabel('Yuborish')
                    ->schema(fn (Feedback $r) => [
                        ...self::details($r),
                        Textarea::make('answer')->label('Javob matni')->required()->rows(6)
                            ->minLength(2)->maxLength(self::ANSWER_MAX),
                    ])
                    ->action(function (Feedback $r, array $data) {
                        // only an unanswered request: two admins answering at once must not overwrite each other
                        Feedback::query()->whereKey($r->getKey())->whereNull('answer')->update([
                            'answer' => trim($data['answer']),
                            'answered_at' => now()->utc()->format('Y-m-d H:i:s'),
                            'answered_by' => auth()->user()?->email,
                        ]);
                    })
                    ->successNotificationTitle('Javob saqlandi, bot uni fuqaroga yuboradi'),
            ]);
    }

    /** @return array<int, TextEntry> */
    private static function details(Feedback $r): array
    {
        return [
            TextEntry::make('who')->label('Fuqaro')
                ->state(trim(($r->user?->full_name ?? '—').' · '.($r->user?->phone ?? '').($r->user?->username ? ' · @'.$r->user->username : ''), ' ·')),
            TextEntry::make('meta')->label('Daraja va vaqt')
                ->state((Feedback::LEVELS[$r->level] ?? $r->level).' · '.$r->created_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i')),
            TextEntry::make('text')->label('Murojaat matni')->state($r->text)->extraAttributes(['style' => 'white-space: pre-wrap']),
            ...($r->answer !== null ? [
                TextEntry::make('answer_shown')->label('Javob')->state($r->answer)->extraAttributes(['style' => 'white-space: pre-wrap'])
                    ->helperText(trim(($r->answered_by ?? '').' · '.($r->answered_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i') ?? '').' · '.Feedback::STATUSES[$r->status()].($r->answer_error ? ': '.$r->answer_error : ''), ' ·')),
            ] : []),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListFeedback::route('/')];
    }
}
