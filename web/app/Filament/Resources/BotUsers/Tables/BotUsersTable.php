<?php

namespace App\Filament\Resources\BotUsers\Tables;

use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Illuminate\Database\Eloquent\Collection;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BotUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('first_seen', 'desc')
            ->columns([
                TextColumn::make('full_name')->label('F.I.SH.')->placeholder('— F.I.Sh. kiritilmagan —')->searchable()->sortable()
                    ->description(fn ($record) => $record->blocked_at ? 'Botni bloklagan ('.$record->blocked_at->timezone(config('app.display_timezone'))->format('d.m.Y').')' : null),
                TextColumn::make('phone')->label('Telefon')->searchable()->copyable()->placeholder('— raqam yo‘q —'),
                TextColumn::make('lang')->label('Til')->badge()->color('gray')->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => ['uz' => 'O‘zbekcha', 'ru' => 'Русский'][$state] ?? $state)->toggleable(),
                TextColumn::make('username')->label('Username')->prefix('@')->searchable()->placeholder('—'),
                IconColumn::make('is_registered')->label('Ro‘yxatdan o‘tgan')->boolean()
                    ->state(fn ($record) => $record->registered_at !== null),
                IconColumn::make('approved')->label('Tasdiqlangan')->boolean()
                    ->state(fn ($record) => $record->approved_at !== null)
                    ->tooltip('Bot REQUIRE_APPROVAL=1 bo‘lganda faqat tasdiqlanganlar fayl oladi'),
                TextColumn::make('photos_count')->label('Rasmlar')->sortable(),
                TextColumn::make('downloads_count')->label('Yuklab olish')->sortable(),
                TextColumn::make('registered_at')->label('Ro‘yxatdan o‘tgan vaqt')->dateTime('d.m.Y H:i')->sortable()->placeholder('—'),
                TextColumn::make('first_seen')->label('Birinchi kirish')->dateTime('d.m.Y H:i')->sortable()->toggleable(),
                TextColumn::make('last_seen')->label('Oxirgi faollik')->dateTime('d.m.Y H:i')->sortable()->toggleable(),
                TextColumn::make('user_id')->label('Telegram ID')->searchable()->copyable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('chat_id')->label('Chat ID')->searchable()->copyable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // nobody may stay anonymous: these get the missing step (name, phone, consent) at their next /start
                Filter::make('incomplete')->label('F.I.Sh. yoki telefoni yo‘q')->toggle()
                    ->query(fn (Builder $query) => $query->where(fn ($w) => $w->whereNull('full_name')->orWhere('full_name', '')
                        ->orWhereNull('phone')->orWhere('phone', '')->orWhereNull('consent_at'))),
                TernaryFilter::make('blocked')->label('Botni bloklagan')
                    ->nullable()->attribute('blocked_at')
                    ->trueLabel('Ha')->falseLabel('Yo‘q'),
                TernaryFilter::make('registered')->label('Ro‘yxatdan o‘tgan')
                    ->nullable()->attribute('registered_at')
                    ->trueLabel('Ha')->falseLabel('Yo‘q'),
                TernaryFilter::make('has_location')->label('Lokatsiya bor')
                    ->nullable()->attribute('latitude')
                    ->trueLabel('Ha')->falseLabel('Yo‘q'),
                self::dateRange('registered_at', 'Ro‘yxatdan o‘tgan sana'),
                self::dateRange('last_seen', 'Oxirgi faollik sanasi'),
                TernaryFilter::make('approved')->label('Tasdiqlangan')
                    ->nullable()->attribute('approved_at')
                    ->trueLabel('Ha')->falseLabel('Yo‘q'),
                Filter::make('downloaded')->label('Fayl yuklab olganlar')->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('downloads')),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')->label('Tasdiqlash')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn ($record) => $record->registered_at !== null && $record->approved_at === null)
                    ->action(fn ($record) => $record->forceFill(['approved_at' => now()->utc()->format('Y-m-d H:i:s')])->save()),
                Action::make('revoke')->label('Bekor qilish')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn ($record) => $record->approved_at !== null)->requiresConfirmation()
                    ->action(fn ($record) => $record->forceFill(['approved_at' => null])->save()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('approve_selected')->label('Tanlanganlarni tasdiqlash')->icon('heroicon-o-check-circle')
                        ->action(fn (Collection $records) => $records->each(
                            fn ($r) => $r->registered_at && $r->forceFill(['approved_at' => now()->utc()->format('Y-m-d H:i:s')])->save()
                        ))->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /** From/until filter on a UTC datetime column; the picked days are interpreted in the display timezone. */
    private static function dateRange(string $column, string $label): Filter
    {
        $tz = config('app.display_timezone');

        return Filter::make($column)->label($label)
            ->schema([
                DatePicker::make('from')->label("$label: dan"),
                DatePicker::make('until')->label("$label: gacha"),
            ])
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn ($q, $d) => $q->where($column, '>=', Carbon::parse($d, $tz)->startOfDay()->utc()))
                ->when($data['until'] ?? null, fn ($q, $d) => $q->where($column, '<=', Carbon::parse($d, $tz)->endOfDay()->utc())))
            ->indicateUsing(function (array $data) use ($label) {
                $out = [];
                if ($data['from'] ?? null) {
                    $out[] = "$label ≥ {$data['from']}";
                }
                if ($data['until'] ?? null) {
                    $out[] = "$label ≤ {$data['until']}";
                }

                return $out;
            });
    }
}
