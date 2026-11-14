<?php

namespace App\Filament\Resources\Inspections;

use App\Filament\NavGroups;
use App\Filament\Resources\DesignObjects\DesignObjectResource;
use App\Filament\Resources\Inspections\Pages\ListInspections;
use App\Models\District;
use App\Models\Inspection;
use App\Services\InspectionReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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
                // what still needs the moderator: waiting, and held ("Moderator jarayonida")
                SelectFilter::make('review')->label('Holat')->options(Inspection::reviews())->multiple()->default(Inspection::OPEN),
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
                Action::make('approve')->label('Tasdiqlash')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Inspection $i) => in_array($i->review, Inspection::OPEN, true))
                    ->modalHeading('Dalilni tasdiqlash')->modalWidth('5xl')
                    ->modalDescription('Tasdiqlansa, obyekt holati o‘zgaradi va xodimga bot orqali xabar boradi.')
                    ->schema([
                        ViewEntry::make('card')->hiddenLabel()->view('filament.objects.inspection-review'),
                        Textarea::make('note')->label('Izoh (ixtiyoriy)')->rows(2)->maxLength(500),
                    ])
                    ->modalSubmitActionLabel('Tasdiqlash')
                    ->action(fn (Inspection $i, array $data) => self::done(InspectionReview::approve($i, $data['note'] ?? null, auth()->user()?->email), 'Tasdiqlandi')),
                Action::make('reject')->label('Rad etish')->icon('heroicon-o-x-circle')->color('danger')
                    // a mistaken approval can be taken back too: the object's status then goes back
                    ->visible(fn (Inspection $i) => in_array($i->review, [...Inspection::OPEN, Inspection::APPROVED], true))
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
