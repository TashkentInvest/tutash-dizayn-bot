<?php

namespace App\Filament\Resources\Posts\RelationManagers;

use App\Models\PostDelivery;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Who the post went to: delivered or not, when they pressed "Tanishdim", whether they used the bot after it came. */
class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Oluvchilar';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function who(PostDelivery $d): string
    {
        $u = $d->botUser;

        return $d->staff?->full_name ?? (trim((string) $u?->full_name) ?: (trim(($u?->first_name ?? '').' '.($u?->last_name ?? '')) ?: (string) $d->user_id));
    }

    public function table(Table $table): Table
    {
        $tz = config('app.display_timezone');
        // NB: Filament passes these closures' arguments by name: it must be $query (another name gets nothing)
        $l = null;
        $leaders = fn () =>\Illuminate\Support\Facades\DB::connection('bot')->table('report_recipients')->pluck('user_id')->flip();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['botUser', 'staff.district']))
            ->recordTitleAttribute('user_id')
            ->columns([
                TextColumn::make('user_id')->label('Kim')->formatStateUsing(fn ($state, PostDelivery $record) => self::who($record))
                    ->description(fn (PostDelivery $record) => $record->botUser?->phone)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($query) => $query
                        ->whereIn('user_id', fn ($sub) => $sub->select('user_id')->from('users')
                            ->where('full_name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                        ->orWhereIn('user_id', fn ($sub) => $sub->select('user_id')->from('staff')->where('full_name', 'like', "%{$search}%")))),
                TextColumn::make('group')->label('Bo‘lim')->badge()->color('gray')
                    ->state(function (PostDelivery $record) use ($leaders, &$l) {
                        $l ??= $leaders();  // read once per page

                        return $record->staff ? 'xodim'.($record->staff->district ? ' · '.trim(str_replace('тумани', '', $record->staff->district->name)) : '')
                            : (isset($l[$record->user_id]) ? 'rahbar' : 'aholi');
                    }),
                TextColumn::make('status')->label('Yetkazildi')->badge()
                    ->formatStateUsing(fn ($state, PostDelivery $record) => $record->recalled_at ? 'Ha · botdan o‘chirildi'
                        : ([PostDelivery::SENT => 'Ha', PostDelivery::FAILED => 'Yo‘q', PostDelivery::PENDING => 'Navbatda'][$state] ?? $state))
                    ->color(fn ($state, PostDelivery $record) => $record->recalled_at ? 'gray' : ([PostDelivery::SENT => 'success', PostDelivery::FAILED => 'danger'][$state] ?? 'warning'))
                    ->tooltip(fn (PostDelivery $record) => $record->error ?: $record->recall_error),
                TextColumn::make('ack_at')->label('Tanishdim')->dateTime('d.m H:i')->timezone($tz)->placeholder('—')->color('success')->sortable(),
                TextColumn::make('botUser.last_seen')->label('Oxirgi faollik')->dateTime('d.m H:i')->timezone($tz)->placeholder('—')
                    ->color(fn (PostDelivery $record) => $record->sent_at && $record->botUser?->last_seen?->greaterThan($record->sent_at) ? 'success' : null)
                    ->tooltip('Yashil: post kelgandan keyin botdan foydalangan'),
            ])
            ->filters([
                SelectFilter::make('view')->label('Holat')->options([
                    'ack' => 'Tanishdim bosgan', 'seen' => 'Ko‘rgan (taxminan)', 'unseen' => 'Ko‘rmagan bo‘lishi mumkin', 'failed' => 'Yetkazilmagan',
                ])->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                    'ack' => $query->whereNotNull('ack_at'),
                    'seen' => $query->where('status', PostDelivery::SENT)
                        ->where(fn ($query) => $query->whereNotNull('ack_at')->orWhere(PostDelivery::usedBotAfter(...))),
                    'unseen' => $query->where('status', PostDelivery::SENT)->whereNull('ack_at')->whereNot(PostDelivery::usedBotAfter(...)),
                    'failed' => $query->where('status', PostDelivery::FAILED),
                    default => $query,
                }),
            ])
            ->poll('15s')
            ->defaultSort('ack_at', 'desc');
    }
}
