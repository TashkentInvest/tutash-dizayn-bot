<?php

namespace App\Filament\Resources\ReportRecipients;

use App\Filament\NavGroups;
use App\Filament\Resources\ReportRecipients\Pages\ManageReportRecipients;
use App\Models\BotUser;
use App\Models\ReportRecipient;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * People who receive the svod pictures in Telegram. Only someone who has pressed /start in the bot can be messaged,
 * so a recipient is picked from the bot's users (by name, phone or @username).
 */
class ReportRecipientResource extends Resource
{
    protected static ?string $model = ReportRecipient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::STAFF;

    protected static ?string $modelLabel = 'hisobot oluvchi';

    protected static ?string $pluralModelLabel = 'Hisobot oluvchilar';

    protected static ?string $navigationLabel = 'Hisobot oluvchilar';

    protected static ?string $slug = 'hisobot-oluvchilar';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')->label('Bot foydalanuvchisi')->required()->searchable()
                ->helperText('Botda /start bosgan odamgina tanlanadi: ism, telefon yoki @username bo‘yicha qidiring.')
                ->getSearchResultsUsing(fn (string $search) => BotUser::query()
                    ->where(fn ($q) => $q->whereLike('full_name', "%$search%")->orWhereLike('first_name', "%$search%")
                        ->orWhereLike('username', '%'.ltrim($search, '@').'%')
                        ->orWhereLike('phone', '%'.(preg_replace('/\D/', '', $search) ?: $search).'%'))
                    ->whereNotIn('user_id', ReportRecipient::query()->select('user_id'))
                    ->limit(30)->get()->mapWithKeys(fn (BotUser $u) => [$u->user_id => self::who($u)])->all())
                ->getOptionLabelUsing(fn ($value) => ($u = BotUser::find($value)) ? self::who($u) : (string) $value)
                ->disabledOn('edit'),
            TextInput::make('label')->label('Lavozimi / izoh')->placeholder('masalan: rahbar, admin, tuman hokimligi')->maxLength(80),
        ]);
    }

    private static function who(BotUser $u): string
    {
        return trim(($u->full_name ?: $u->first_name ?: 'ID '.$u->user_id).($u->phone ? ' · '.Phone::pretty($u->phone) : '').($u->username ? ' · @'.$u->username : ''));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('botUser'))
            ->defaultSort('created_at')
            ->emptyStateHeading('Hali hech kim qo‘shilmagan')
            ->emptyStateDescription('Svod hisobotini Telegramda oladiganlarni qo‘shing.')
            ->columns([
                TextColumn::make('name')->label('F.I.Sh.')->state(fn (ReportRecipient $r) => $r->botUser?->full_name ?: $r->botUser?->first_name ?: '—'),
                TextColumn::make('phone')->label('Telefon')->state(fn (ReportRecipient $r) => $r->botUser?->phone ? Phone::pretty($r->botUser->phone) : '—'),
                TextColumn::make('label')->label('Lavozimi')->placeholder('—'),
                TextColumn::make('created_at')->label('Qo‘shilgan')->dateTime('d.m.Y H:i')->timezone(config('app.display_timezone')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageReportRecipients::route('/')];
    }
}
