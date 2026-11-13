<?php

namespace App\Filament\Resources\ReportRecipients;

use App\Filament\NavGroups;
use App\Filament\Resources\ReportRecipients\Pages\ManageReportRecipients;
use App\Models\BotUser;
use App\Models\ReportRecipient;
use App\Models\Staff;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\ToggleColumn;
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

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::SETTINGS;

    protected static ?string $modelLabel = 'hisobot oluvchi';

    protected static ?string $pluralModelLabel = 'Hisobot oluvchilar';

    protected static ?string $navigationLabel = 'Hisobotlar: kimga, qachon';

    protected static ?string $slug = 'hisobot-oluvchilar';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')->label('Bot foydalanuvchisi')->required()->searchable()
                ->helperText('Ro‘yxatdan tanlang yoki ism, telefon, @username bo‘yicha qidiring. Avval xodimlar, keyin botga oxirgi kirganlar. '
                    .'Botda /start bosgan odamgina ro‘yxatda bo‘ladi.')
                // the list is there as soon as it opens: no need to know what to type
                ->options(fn () => self::candidates(null))
                ->getSearchResultsUsing(fn (string $search) => self::candidates($search))
                ->getOptionLabelUsing(fn ($value) => ($u = BotUser::find($value)) ? self::who($u) : (string) $value)
                ->disabledOn('edit'),
            TextInput::make('label')->label('Lavozimi / izoh')->placeholder('masalan: rahbar, admin, tuman hokimligi')->maxLength(80),
            \Filament\Schemas\Components\Fieldset::make('Qaysi hisobotlarni oladi')->columns(1)->schema([
                Toggle::make('hourly_report')->label('Soatlik holat — har soat, 09:00–17:00')->default(false),
                Toggle::make('daily_report')->label('Kunlik svod — 18:00 da ikki rasm (svod va bajarilish)')->default(true),
                Toggle::make('attendance_report')->label('Kunlik davomat — 18:00 da xodimlar qachon va qancha ishlagani')->default(true),
            ]),
        ]);
    }

    /** @return array<int, string> bot users who can be added: staff first, then who was in the bot last; or a search */
    private static function candidates(?string $search): array
    {
        $staff = Staff::query()->whereNotNull('user_id')->with('district')->get()->keyBy('user_id');
        $q = BotUser::query()->whereNotIn('user_id', ReportRecipient::query()->select('user_id'));
        if ($search !== null && trim($search) !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $q->where(fn ($w) => $w->whereLike('full_name', "%$search%")->orWhereLike('first_name', "%$search%")
                ->orWhereLike('username', '%'.ltrim($search, '@').'%')
                ->when(strlen($digits) >= 3, fn ($w) => $w->orWhereLike('phone', "%$digits%"))
                ->orWhereIn('user_id', Staff::query()->whereNotNull('user_id')->whereLike('full_name', "%$search%")->select('user_id')));
        }
        $ids = $staff->keys()->map(fn ($id) => (int) $id)->all();
        $q->orderByRaw($ids ? 'CASE WHEN user_id IN ('.implode(',', $ids).') THEN 0 ELSE 1 END' : '0')->orderByDesc('last_seen');

        return $q->limit(100)->get()->mapWithKeys(function (BotUser $u) use ($staff) {
            $s = $staff->get($u->user_id);

            return [$u->user_id => ($s ? '👷 '.$s->full_name.' ('.trim(str_replace(' тумани', '', (string) $s->district?->name)).') · ' : '').self::who($u)];
        })->all();
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
                ToggleColumn::make('hourly_report')->label('Soatlik'),
                ToggleColumn::make('daily_report')->label('Kunlik svod'),
                ToggleColumn::make('attendance_report')->label('Davomat'),
                TextColumn::make('created_at')->label('Qo‘shilgan')->dateTime('d.m.Y H:i')->timezone(config('app.display_timezone')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageReportRecipients::route('/')];
    }
}
