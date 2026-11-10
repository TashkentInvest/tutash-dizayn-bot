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
                TextColumn::make('staff.full_name')->label('Xodim'),
                TextColumn::make('object.street.district.name')->label('Tuman'),
                TextColumn::make('object_label')->label('Obyekt')->wrap()
                    ->state(fn (Inspection $i) => $i->object?->street?->name.' · '.$i->object?->label())
                    ->url(fn (Inspection $i) => $i->object ? DesignObjectResource::getUrl('view', ['record' => $i->object]) : null),
                TextColumn::make('kind')->label('Nima qilindi')->badge()
                    ->formatStateUsing(fn ($state) => Inspection::KINDS[$state] ?? $state)
                    ->color(fn ($state) => $state === 'installed' ? 'success' : 'warning'),
                TextColumn::make('comment')->label('Izoh')->limit(60)->wrap()->color('danger')->weight('bold'),
                TextColumn::make('distance_m')->label('Xodim ↔ obyekt')->placeholder('—')->formatStateUsing(fn ($state) => (int) $state.' m')
                    ->tooltip('Xodim turgan joy bilan u xaritada belgilagan obyekt joyi orasidagi masofa. Tasdiqlansa, belgilangan joy obyekt joyi bo‘ladi.'),
                TextColumn::make('review')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state) => Inspection::REVIEWS[$state] ?? $state)
                    ->color(fn ($state) => Inspection::REVIEW_COLORS[$state] ?? 'gray')
                    ->description(fn (Inspection $i) => $i->review_note),
            ])
            ->filters([
                SelectFilter::make('review')->label('Holat')->options(Inspection::REVIEWS)->default(Inspection::PENDING),
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
                    ->visible(fn (Inspection $i) => $i->review === Inspection::PENDING)
                    ->modalHeading('Dalilni tasdiqlash')->modalWidth('5xl')
                    ->modalDescription('Tasdiqlansa, obyekt holati o‘zgaradi va xodimga bot orqali xabar boradi.')
                    ->schema([
                        ViewEntry::make('card')->hiddenLabel()->view('filament.objects.inspection-review'),
                        Textarea::make('note')->label('Izoh (ixtiyoriy)')->rows(2)->maxLength(500),
                    ])
                    ->modalSubmitActionLabel('Tasdiqlash')
                    ->action(fn (Inspection $i, array $data) => self::done(InspectionReview::approve($i, $data['note'] ?? null, auth()->user()?->email), 'Tasdiqlandi')),
                Action::make('reject')->label('Rad etish')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Inspection $i) => $i->review === Inspection::PENDING)
                    ->modalHeading('Dalilni rad etish')->modalWidth('5xl')
                    ->schema([
                        ViewEntry::make('card')->hiddenLabel()->view('filament.objects.inspection-review'),
                        Textarea::make('reason')->label('Sabab (xodimga yuboriladi)')->required()->minLength(5)->rows(3)->maxLength(500),
                    ])
                    ->modalSubmitActionLabel('Rad etish')
                    ->action(fn (Inspection $i, array $data) => self::done(InspectionReview::reject($i, $data['reason'], auth()->user()?->email), 'Rad etildi')),
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
