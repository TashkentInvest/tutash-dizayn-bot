<?php

namespace App\Filament\Resources\Staff;

use App\Filament\NavGroups;
use App\Filament\Resources\Staff\Pages\ManageStaff;
use App\Models\BotUser;
use App\Models\District;
use App\Models\Inspection;
use App\Models\Staff;
use App\Models\Street;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** District staff: who checks the businesses of which district. They sign in to the bot with this phone. */
class StaffResource extends Resource
{
    protected static ?string $model = Staff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::STAFF;

    protected static ?string $modelLabel = 'xodim';

    protected static ?string $pluralModelLabel = 'Xodimlar';

    protected static ?string $navigationLabel = 'Xodimlar';

    // the menu's "Xodimlar" is the district list (Pages\DistrictStaff); this full list (bot link, invite, streets) opens from there
    protected static ?string $slug = 'xodimlar/royxat';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            self::nameField(),
            self::phoneField(),
            Select::make('district_id')->label('Tuman')->live()->placeholder('Biriktirilmagan')
                ->options(fn () => District::query()->orderBy('sort_order')->pluck('name', 'id')),
            Select::make('priority_streets')->label('Biriktirilgan ko‘chalar')->multiple()->searchable()
                ->helperText('Xodim Mini App’da faqat shu ko‘chalarni ko‘radi va faqat ularda ishlaydi. Bo‘sh qoldirilsa, tumanning hamma ko‘chalari.')
                ->options(fn (Get $get) => $get('district_id')
                    ? Street::query()->where('district_id', $get('district_id'))->orderBy('sort_order')->pluck('name', 'id')
                    : [])
                ->dehydrateStateUsing(fn ($state) => array_values(array_map('intval', (array) $state))),
            Textarea::make('streets_note')->label('Izoh')->rows(2)->columnSpanFull(),
            Toggle::make('is_active')->label('Faol')->default(true),
        ]);
    }

    public static function nameField(): TextInput
    {
        return TextInput::make('full_name')->label('F.I.Sh.')->required()->maxLength(150);
    }

    /** The phone, checked as it is stored: "90 123 45 67" and "+998901234567" are the same number. */
    public static function phoneField(): TextInput
    {
        return TextInput::make('phone')->label('Telefon')->tel()->required()->placeholder('+998 90 123 45 67')
            ->helperText('Xodim botda shu raqamni “Raqamni yuborish” tugmasi bilan tasdiqlaydi.')
            ->rules([fn (?Staff $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                $phone = Phone::normalize($value);
                if ($phone === null) {
                    $fail('Raqam noto‘g‘ri. Namuna: +998 90 123 45 67');
                } elseif (Staff::query()->where('phone', $phone)->when($record instanceof Staff, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                    $fail('Bu raqam boshqa xodimga biriktirilgan.');
                }
            }])
            ->dehydrateStateUsing(fn ($state) => Phone::normalize($state));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('district')->withCount([
                'inspections as sent_count' => fn ($i) => $i->whereNotNull('review'),
                'inspections as approved_count' => fn ($i) => $i->where('review', Inspection::APPROVED),
            ]))
            ->defaultSort('full_name')
            // the district once, as the heading of its people (in the order of the district list), not on every row
            ->defaultGroup(Group::make('district_id')->label('Tuman')->titlePrefixedWithLabel(false)->collapsible()
                ->getTitleFromRecordUsing(fn (Staff $s) => $s->district?->name ?? 'Hech qaysi tumanga biriktirilmagan')
                ->orderQueryUsing(fn ($query, string $direction) => $query->orderBy(
                    District::query()->select('sort_order')->whereColumn('districts.id', 'staff.district_id'), $direction)))
            ->groupingSettingsHidden()
            ->paginated([25, 50, 'all'])->defaultPaginationPageOption(50)  // all the districts on one page
            ->columns([
                TextColumn::make('full_name')->label('F.I.Sh.')->searchable()->weight('medium')
                    ->description(fn (Staff $s) => self::streetNames($s) ?: $s->streets_note),
                TextColumn::make('phone')->label('Telefon')->formatStateUsing(fn ($state) => Phone::pretty($state))->searchable()->copyable(),
                TextColumn::make('bot_state')->label('Botda')->badge()
                    ->state(fn (Staff $s) => $s->botState())
                    ->formatStateUsing(fn (string $state) => ['linked' => 'Tasdiqlangan', 'waiting' => 'Raqami botda bor, tasdiqlamagan', 'absent' => 'Bu raqam botda yo‘q'][$state])
                    ->color(fn (string $state) => ['linked' => 'success', 'waiting' => 'warning', 'absent' => 'gray'][$state])
                    ->tooltip(fn (Staff $s) => match ($s->botState()) {
                        'linked' => 'Telegram ID: '.$s->user_id,
                        'waiting' => 'Shu raqam bilan botga kirgan. /start bosib, raqamni “Raqamni yuborish” tugmasi bilan yuborsin — yoki “Botga bog‘lash”.',
                        default => 'Botda bu raqamni yuborgan odam yo‘q. Botga kirgan bo‘lsa ham raqam qadamida to‘xtagan yoki Telegram’i boshqa raqamda bo‘lishi mumkin: '
                            .'/start bosib, “Raqamni yuborish” tugmasini bossin — yoki “Botga bog‘lash” bilan uni o‘zingiz bog‘lang.',
                    }),
                TextColumn::make('sent_count')->label('Yuborgan dalillar'),
                TextColumn::make('approved_count')->label('Tasdiqlangan'),
                // one click used to switch a person off mid-task: now it asks first and says what happens
                IconColumn::make('is_active')->label('Faol')->boolean()
                    ->action(Action::make('toggleActive')->requiresConfirmation()
                        ->modalHeading(fn (Staff $s) => $s->is_active ? 'Xodimni nofaol qilish' : 'Xodimni faol qilish')
                        ->modalDescription(fn (Staff $s) => $s->is_active
                            ? 'Xodim Mini App’dan foydalana olmaydi; boshlangan, yuborilmagan dalili ham to‘xtaydi.'
                            : 'Xodim yana Mini App’da ishlay oladi.')
                        ->action(fn (Staff $s) => $s->update(['is_active' => ! $s->is_active]))),
            ])
            ->recordActions([
                // an admin links the staff member to a bot account by hand (the person must have pressed /start in the bot)
                Action::make('link')->label('Botga bog‘lash')->icon('heroicon-o-link')->color('success')
                    ->visible(fn (Staff $s) => $s->user_id === null)
                    ->modalHeading(fn (Staff $s) => $s->full_name.' — botga bog‘lash')
                    ->modalDescription('Odatda xodim botda raqamini “Raqamni yuborish” tugmasi bilan o‘zi tasdiqlaydi. Bu yerda admin qo‘lda bog‘laydi: '
                        .'xodim avval botda /start bosgan bo‘lishi kerak. Bog‘langach, botda xodim menyusi va Mini App ochiladi.')
                    ->modalSubmitActionLabel('Bog‘lash')
                    ->schema([
                        Select::make('user_id')->label('Botdagi akkaunti')->required()->searchable()
                            ->helperText('Ism, telefon yoki @username bo‘yicha qidiring. ★ — xodimning raqami bilan kirgan, ≈ — ismi o‘xshash (boshqa raqam bilan kirgan bo‘lishi mumkin).')
                            ->options(fn (Staff $record) => self::botAccounts($record, null))
                            ->getSearchResultsUsing(fn (Staff $record, string $search) => self::botAccounts($record, $search))
                            ->getOptionLabelUsing(fn ($value) => ($u = BotUser::find($value)) ? self::account($u) : (string) $value),
                    ])
                    ->action(fn (Staff $s, array $data) => self::linkToBot($s, (int) $data['user_id'])),
                // not in the bot under this number (or not found in the list above): a personal link to send them
                Action::make('invite')->label('Taklif havolasi')->icon('heroicon-o-envelope')->color('info')
                    ->visible(fn (Staff $s) => $s->user_id === null)
                    ->modalHeading(fn (Staff $s) => $s->full_name.' — taklif havolasi')
                    ->modalContent(function (Staff $s) {
                        $inv = $s->inviteLink();
                        $text = 'Assalomu alaykum! Siz '.($s->district?->name ?? 'tuman').' xodimi sifatida Dizayn-kod botiga qo‘shildingiz. '
                            ."Quyidagi havolani oching va «Start» tugmasini bosing:\n".$inv['link'];

                        return view('filament.staff-invite', ['link' => $inv['link'], 'text' => $text, 'expires' => $inv['expires']->format('d.m.Y H:i')]);
                    })
                    ->modalSubmitAction(false)->modalCancelActionLabel('Yopish')
                    ->extraModalFooterActions(fn (Action $action) => [
                        Action::make('renew')->label('Yangi havola (eskisi ishlamaydi)')->color('gray')->requiresConfirmation()
                            ->action(fn (Staff $record) => $record->inviteLink(true)),
                    ]),
                EditAction::make(),
                Action::make('unlink')->label('Telegram bog‘lanishini uzish')->icon('heroicon-o-link-slash')->color('gray')
                    ->visible(fn (Staff $s) => $s->user_id !== null)->requiresConfirmation()
                    ->modalDescription('Xodim telefonini almashtirgan bo‘lsa: keyingi /start da raqamini qayta tasdiqlaydi.')
                    ->action(fn (Staff $s) => $s->forceFill(['user_id' => null])->save()),
                DeleteAction::make()->visible(fn (Staff $s) => ! $s->inspections()->exists())
                    ->modalDescription('Dalil yubormagan xodimgina o‘chiriladi. Ishlagan xodimni “Faol” belgisini o‘chirib qo‘ying.'),
            ]);
    }

    /** The streets assigned to the person (what they see in the Mini App), in Latin. */
    private static function streetNames(Staff $s): string
    {
        $ids = $s->streetIds();

        return $ids === null ? '' : Street::query()->whereIn('id', $ids)->orderBy('sort_order')->orderBy('name')->pluck('name')
            ->map(fn ($n) => \App\Support\Latin::of($n))->implode(', ');
    }

    /** Links the staff member to this bot account (it moves from anyone who had it) and tells them in the bot. */
    public static function linkToBot(Staff $s, int $uid): void
    {
        DB::connection('bot')->transaction(function () use ($s, $uid) {
            Staff::query()->where('user_id', $uid)->whereKeyNot($s->getKey())->update(['user_id' => null]);
            $s->forceFill(['user_id' => $uid])->save();
            DB::connection('bot')->table('outbox')->insert(['user_id' => $uid, 'created_at' => now()->utc()->format('Y-m-d H:i:s'),
                'text' => '✅ Siz '.($s->district?->name ?? 'tuman').' xodimi sifatida biriktirildingiz. Xodim menyusini ochish uchun /start ni bosing.']);
        });
        Notification::make()->success()->title('Bog‘landi')->body($s->full_name.': botda xabar yuboriladi, /start bosgach xodim menyusi ochiladi.')->send();
    }

    /** Bot accounts to link: the one with this staff member's phone first, then similar names, then who was in the bot last. */
    public static function botAccounts(Staff $staff, ?string $search): array
    {
        $q = BotUser::query()->whereNotIn('user_id', Staff::query()->whereNotNull('user_id')->select('user_id'));
        if ($search !== null && trim($search) !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $q->where(fn ($w) => $w->whereLike('full_name', "%$search%")->orWhereLike('first_name', "%$search%")
                ->orWhereLike('username', '%'.ltrim($search, '@').'%')
                ->when(strlen($digits) >= 4, fn ($w) => $w->orWhereLike('phone', "%$digits%")));
        } else {
            // the same number first, then people whose name contains the staff member's (many joined with another number)
            $name = '%'.mb_strtolower(trim((string) $staff->full_name)).'%';
            $q->orderByRaw(
                "CASE WHEN phone = ? THEN 0 WHEN LOWER(COALESCE(full_name, '') || ' ' || COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')) LIKE ? THEN 1 ELSE 2 END",
                [$staff->phone, $name])->orderByDesc('last_seen');
        }
        $similar = fn (BotUser $u) => str_contains(mb_strtolower($u->full_name.' '.$u->first_name.' '.$u->last_name), mb_strtolower(trim((string) $staff->full_name)));

        return $q->limit(30)->get()->mapWithKeys(fn (BotUser $u) => [$u->user_id => self::account($u, $u->phone === $staff->phone, $similar($u))])->all();
    }

    public static function account(BotUser $u, bool $samePhone = false, bool $sameName = false): string
    {
        // when they were last in the bot: "the one who pressed /start a minute ago" is easy to find
        $seen = $u->last_seen ? ' · '.$u->last_seen->copy()->setTimezone(config('app.display_timezone'))->format('d.m H:i') : '';

        return trim(($samePhone ? '★ ' : ($sameName ? '≈ ' : '')).($u->full_name ?: $u->first_name ?: 'ID '.$u->user_id)
            .($u->phone ? ' · '.Phone::pretty($u->phone) : '').($u->username ? ' · @'.$u->username : '').$seen);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStaff::route('/')];
    }
}
