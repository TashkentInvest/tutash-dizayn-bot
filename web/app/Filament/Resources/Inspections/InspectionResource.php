<?php

namespace App\Filament\Resources\Inspections;

use App\Filament\NavGroups;
use App\Filament\Resources\DesignObjects\DesignObjectResource;
use App\Filament\Resources\Inspections\Pages\ListInspections;
use App\Models\DesignObject;
use App\Models\District;
use App\Models\Inspection;
use App\Services\InspectionReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Evidence from district staff waiting for a decision. Approving changes the object's status (and sets its place on the
 * map the first time); rejecting asks the staff member to send it again. Either way the bot tells the staff member.
 */
class InspectionResource extends Resource
{
    protected static ?string $model = Inspection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::STAFF;

    protected static ?string $modelLabel = 'tekshiruv';

    protected static ?string $pluralModelLabel = 'Tekshiruvlar (tasdiqlash)';

    protected static ?string $navigationLabel = 'Tekshiruvlar (tasdiqlash)';

    protected static ?string $slug = 'tekshiruvlar';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Inspection::query()->where('review', Inspection::PENDING)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('review')->with(['object.street.district', 'staff']))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('submitted_at')->label('Yuborilgan')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('staff.full_name')->label('Xodim')->searchable(),
                TextColumn::make('object.street.district.name')->label('Tuman'),
                TextColumn::make('object_label')->label('Obyekt')->wrap()
                    ->state(fn (Inspection $i) => $i->object?->street?->name.' · '.$i->object?->label())
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('object',
                        fn ($o) => $o->whereLike('shop_name', "%$search%")->orWhereLike('stir', "%$search%")->orWhereLike('owner_name', "%$search%")))
                    ->url(fn (Inspection $i) => $i->object ? DesignObjectResource::getUrl('view', ['record' => $i->object]) : null),
                TextColumn::make('kind')->label('Nima qilindi')->badge()
                    ->formatStateUsing(fn ($state) => Inspection::KINDS[$state] ?? $state)
                    ->color(fn ($state) => $state === 'installed' ? 'success' : 'warning'),
                TextColumn::make('comment')->label('Izoh')->limit(60)->wrap(),
                TextColumn::make('distance_m')->label('Xodim ↔ obyekt')->placeholder('—')->formatStateUsing(fn ($state) => (int) $state.' m')
                    ->tooltip('Xodim turgan joy bilan u xaritada belgilagan obyekt joyi orasidagi masofa. Tasdiqlansa, belgilangan joy obyekt joyi bo‘ladi.'),
                TextColumn::make('review')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state) => Inspection::reviews()[$state] ?? $state)
                    ->color(fn ($state) => Inspection::REVIEW_COLORS[$state] ?? 'gray')
                    ->description(fn (Inspection $i) => $i->review_note),
            ])
            ->filters([
                // the stages are the tabs above (ListInspections); this narrows inside one
                SelectFilter::make('review')->label('Holat')->options(Inspection::reviews())->multiple(),
                SelectFilter::make('kind')->label('Nima qilindi')->options(Inspection::KINDS),
                SelectFilter::make('district')->label('Tuman')
                    ->options(fn () => District::query()->orderBy('sort_order')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('object', fn ($o) => $o->inDistrict((int) $data['value'])) : $query),
                SelectFilter::make('staff_id')->label('Xodim')->options(fn () => \App\Models\Staff::query()->orderBy('full_name')->pluck('full_name', 'id')),
            ])
            ->recordActions([
                Action::make('view')->label('Ko‘rish')->icon('heroicon-o-eye')->color('gray')
                    ->modalHeading(fn (Inspection $i) => $i->object?->street?->name.' · '.$i->object?->label())
                    ->modalSubmitAction(false)->modalCancelActionLabel('Yopish')->modalWidth('5xl')
                    ->schema([ViewEntry::make('card')->hiddenLabel()->view('filament.objects.inspection-review')]),
                // one window: nothing ticked = full approval; something to fix ticked = "Jarayonli tasdiqlash" (installed only)
                Action::make('approve')->label('Tasdiqlash')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Inspection $i) => in_array($i->review, Inspection::LOCKED, true))
                    ->modalHeading('Dalilni tasdiqlash')->modalWidth('5xl')
                    ->modalDescription(fn (Inspection $i) => match (true) {
                        $i->kind !== DesignObject::INSTALLED => 'Tasdiqlansa, obyekt «Jarayonda»ga o‘tadi va xodimga bot orqali xabar boradi.',
                        $i->review === Inspection::CONFIRMING => 'To‘liq tasdiqlanadi: obyekt «Dizayn-kodga keltirildi» bo‘ladi. Xodimga bot orqali xabar boradi.',
                        default => 'Hech narsa belgilanmasa — to‘liq tasdiqlanadi: obyekt «Dizayn-kodga keltirildi» bo‘ladi. '
                            .'Kamchilik belgilansa — jarayonli tasdiqlanadi: obyekt «Jarayonda»ga o‘tadi, xodimga nimani tuzatish kerakligi yuboriladi, '
                            .'u tuzatib yangi dalil yuboradi (avvalgilari saqlanadi).',
                    })
                    ->schema([
                        ViewEntry::make('card')->hiddenLabel()->view('filament.objects.inspection-review'),
                        CheckboxList::make('reasons')->label('Kamchilik bo‘lsa belgilang (jarayonli tasdiqlash)')
                            ->options(array_map(fn ($r) => $r[0], Inspection::PROCESS_REASONS))
                            ->visible(fn (Inspection $record) => $record->kind === DesignObject::INSTALLED && in_array($record->review, Inspection::OPEN, true)),
                        Textarea::make('note')->label('Izoh (ixtiyoriy, xodimga ham boradi)')->rows(2)->maxLength(500),
                    ])
                    ->modalSubmitActionLabel('Tasdiqlash')
                    ->action(function (Inspection $i, array $data) {
                        $by = auth()->user()?->email;
                        if (! empty($data['reasons'])) {
                            return self::done(InspectionReview::process($i, $data['reasons'], $data['note'] ?? null, $by), 'Jarayonli tasdiqlandi');
                        }
                        self::done(InspectionReview::approve($i, $data['note'] ?? null, $by), 'Tasdiqlandi');
                    }),
                // already "Jarayonli tasdiqlandi": tick or change what is to fix (the older ones have no reasons yet)
                Action::make('reasons')->label('Sababni tahrirlash')->icon('heroicon-o-pencil-square')->color('warning')
                    ->visible(fn (Inspection $i) => $i->review === Inspection::CONFIRMING)
                    ->modalHeading('Nimani tuzatish kerak')->modalWidth('5xl')
                    ->fillForm(function (Inspection $i) {
                        [$reasons, $note] = InspectionReview::readReasons($i->review_note);

                        return ['reasons' => $reasons, 'note' => $note, 'notify' => true];
                    })
                    ->schema([
                        ViewEntry::make('card')->hiddenLabel()->view('filament.objects.inspection-review'),
                        CheckboxList::make('reasons')->label('Nimani tuzatish kerak')->required()
                            ->options(array_map(fn ($r) => $r[0], Inspection::PROCESS_REASONS)),
                        Textarea::make('note')->label('Qo‘shimcha izoh (ixtiyoriy)')->rows(2)->maxLength(500),
                        Toggle::make('notify')->label('Xodimga botda qayta yuborish'),
                    ])
                    ->modalSubmitActionLabel('Saqlash')
                    ->action(fn (Inspection $i, array $data) => InspectionReview::editReasons($i, $data['reasons'] ?? [], $data['note'] ?? null,
                        auth()->user()?->email, (bool) ($data['notify'] ?? false))
                        ? Notification::make()->success()->title(($data['notify'] ?? false) ? 'Saqlandi, xodimga yuboriladi' : 'Saqlandi')->send()
                        : Notification::make()->warning()->title('Saqlanmadi: dalil endi jarayonda emas')->send()),
                Action::make('reject')->label('Rad etish')->icon('heroicon-o-x-circle')->color('danger')
                    // a mistaken approval can be taken back too: the object's status then goes back
                    ->visible(fn (Inspection $i) => in_array($i->review, [...Inspection::LOCKED, Inspection::APPROVED], true))
                    ->modalHeading(fn (Inspection $i) => $i->review === Inspection::APPROVED ? 'Tasdiqlangan dalilni rad etish' : 'Dalilni rad etish')
                    ->modalDescription(fn (Inspection $i) => $i->review === Inspection::APPROVED
                        ? 'Tasdiq bekor qilinadi: obyekt holati qolgan tasdiqlangan dalillarga qarab qaytariladi (boshqasi bo‘lmasa, “Boshlanmagan”). Xodimga sababi bilan xabar boradi.' : null)
                    ->schema([
                        ViewEntry::make('card')->hiddenLabel()->view('filament.objects.inspection-review'),
                        Textarea::make('reason')->label('Sabab (xodimga yuboriladi)')->required()->minLength(5)->rows(3)->maxLength(500),
                    ])
                    ->modalSubmitActionLabel('Rad etish')
                    ->action(fn (Inspection $i, array $data) => self::done(InspectionReview::reject($i, $data['reason'], auth()->user()?->email), 'Rad etildi')),
                // not approved yet, not rejected either: held with a note, the object is not done; may stay so for days
                Action::make('hold')->label('Moderator jarayoniga')->icon('heroicon-o-pause-circle')->color('info')
                    ->visible(fn (Inspection $i) => $i->review === Inspection::PENDING)
                    ->modalHeading('Moderator jarayoniga o‘tkazish')->modalWidth('5xl')
                    ->modalDescription('Dalil tasdiqlanmaydi va obyekt tugallangan deb belgilanmaydi. Dalil izoh bilan kerakli muddat '
                        .'shu holatda turadi, xodim bu obyektga yangi dalil yubora olmaydi. Keyin «Xodimdan so‘rash», «Tasdiqlash» yoki «Rad etish».')
                    ->schema([
                        ViewEntry::make('card')->hiddenLabel()->view('filament.objects.inspection-review'),
                        Textarea::make('note')->label('Izoh (nima uchun, nima kutilyapti) — xodimga ham boradi')->required()->minLength(5)->rows(3)->maxLength(500),
                    ])
                    ->modalSubmitActionLabel('Moderator jarayoniga')
                    ->action(fn (Inspection $i, array $data) => self::done(InspectionReview::hold($i, $data['note'], auth()->user()?->email), 'Moderator jarayonida')),
                Action::make('ask')->label('Xodimdan so‘rash')->icon('heroicon-o-chat-bubble-left-ellipsis')->color('gray')
                    ->visible(fn (Inspection $i) => $i->review === Inspection::MODERATION)
                    ->modalHeading(fn (Inspection $i) => 'Xodimdan so‘rash: '.$i->object?->label())
                    ->modalDescription('Xodimga botda obyekt nomi, ko‘chasi va xaritadagi joyi bilan birga yuboriladi.')
                    ->fillForm(fn (Inspection $i) => ['question' => 'Shu obyekt joylashuvidagi muammo nima bo‘ldi? Hal bo‘lgan bo‘lsa, yozing.'
                        .($i->review_note ? "\n(Izoh: {$i->review_note})" : '')])
                    ->schema([Textarea::make('question')->label('Savol')->required()->minLength(5)->rows(4)->maxLength(1000)])
                    ->modalSubmitActionLabel('Yuborish')
                    ->action(fn (Inspection $i, array $data) => InspectionReview::ask($i, $data['question'])
                        ? Notification::make()->success()->title('Xodimga yuborildi')->send()
                        : Notification::make()->warning()->title('Yuborilmadi: xodim botga bog‘lanmagan yoki dalil endi moderator jarayonida emas')->send()),
            ]);
    }

    private static function done(bool $ok, string $title): void
    {
        $ok ? Notification::make()->title($title.', xodimga xabar yuboriladi')->success()->send()
            : Notification::make()->title('Bu dalil allaqachon ko‘rib chiqilgan')->warning()->send();
    }

    public static function getPages(): array
    {
        return ['index' => ListInspections::route('/')];
    }
}
