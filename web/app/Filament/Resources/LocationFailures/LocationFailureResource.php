<?php

namespace App\Filament\Resources\LocationFailures;

use App\Filament\NavGroups;
use App\Filament\Resources\LocationFailures\Pages\ListLocationFailures;
use App\Models\District;
use App\Models\LocationFailure;
use App\Models\Staff;
use App\Models\Street;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Xatolikka uchraganlar": every location the Mini App refused (too far from the street or object, weak GPS, a point picked
 * far away), with where the staff member stood and what it was compared with, on a map.
 */
class LocationFailureResource extends Resource
{
    protected static ?string $model = LocationFailure::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::STAFF;

    protected static ?string $modelLabel = 'xatolik';

    protected static ?string $pluralModelLabel = 'Xatolikka uchraganlar';

    protected static ?string $navigationLabel = 'Xatolikka uchraganlar';

    protected static ?string $slug = 'xatoliklar';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $n = LocationFailure::query()->where('created_at', '>=', now()->subDay())->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Oxirgi 24 soatda';
    }

    public static function table(Table $table): Table
    {
        $tz = config('app.display_timezone');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['staff', 'street.district', 'object']))
            ->defaultSort('created_at', 'desc')
            ->description('Mini App joylashuvni qabul qilmagan urinishlar: xodim turgan nuqta va u solishtirilgan joy. «Xaritada» — ikkalasi xaritada.')
            ->emptyStateHeading('Xatolik yo‘q')
            ->emptyStateDescription('Xodim joylashuvi rad etilganda shu yerda paydo bo‘ladi.')
            ->columns([
                TextColumn::make('created_at')->label('Vaqt')->dateTime('d.m.Y H:i')->timezone($tz)->sortable(),
                TextColumn::make('staff.full_name')->label('Xodim')->placeholder('—')->wrap(),
                TextColumn::make('kind')->label('Qayerda')->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => LocationFailure::KINDS[$state] ?? $state),
                TextColumn::make('district_name')->label('Tuman')->state(fn (LocationFailure $f) => $f->street?->district?->name)->placeholder('—'),
                TextColumn::make('place')->label('Ko‘cha / obyekt')->wrap()
                    ->state(fn (LocationFailure $f) => $f->street?->name ?? '—')
                    ->description(fn (LocationFailure $f) => $f->object?->label()),
                TextColumn::make('reason')->label('Sabab')->badge()->color(fn ($state) => $state === 'accuracy' ? 'warning' : 'danger')
                    ->formatStateUsing(fn ($state) => LocationFailure::REASONS[$state] ?? $state),
                TextColumn::make('distance_m')->label('Masofa')->sortable()->placeholder('—')->color('danger')
                    ->formatStateUsing(fn ($state) => $state >= 1000 ? number_format($state / 1000, 1, ',', ' ').' km' : (int) round($state).' m'),
                TextColumn::make('accuracy')->label('GPS aniqligi')->placeholder('—')
                    ->formatStateUsing(fn ($state) => '±'.(int) round($state).' m'),
            ])
            ->filters([
                SelectFilter::make('reason')->label('Sabab')->options(LocationFailure::REASONS),
                SelectFilter::make('kind')->label('Qayerda')->options(LocationFailure::KINDS),
                SelectFilter::make('staff_id')->label('Xodim')->searchable()
                    ->options(fn () => Staff::query()->orderBy('full_name')->pluck('full_name', 'id')),
                SelectFilter::make('district')->label('Tuman')
                    ->options(fn () => District::query()->orderBy('sort_order')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('street_id', Street::query()->where('district_id', $data['value'])->pluck('id')) : $query),
                Filter::make('day')->label('Sana')
                    ->schema([DatePicker::make('from')->label('Sanadan'), DatePicker::make('until')->label('Sanagacha')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', \Carbon\Carbon::parse($d, $tz)->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', \Carbon\Carbon::parse($d, $tz)->endOfDay()->utc()))),
            ])
            ->recordAction('map')
            ->recordActions([
                Action::make('map')->label('Xaritada')->icon('heroicon-o-eye')->color('gray')
                    ->modalHeading(fn (LocationFailure $f) => ($f->staff?->full_name ?? 'Xodim').' · '.(LocationFailure::REASONS[$f->reason] ?? $f->reason))
                    ->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel('Yopish')
                    ->schema([ViewEntry::make('map')->hiddenLabel()->view('filament.failures.map-frame')]),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLocationFailures::route('/')];
    }
}
