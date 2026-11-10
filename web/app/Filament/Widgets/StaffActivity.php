<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Staff\StaffResource;
use App\Models\Inspection;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** Who of the district staff works today: businesses entered and evidence sent, all in one query. */
class StaffActivity extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = ['md' => 1, 'lg' => 1, '2xl' => 2];

    protected static ?string $heading = 'Xodimlar: bugungi ish';

    protected ?string $pollingInterval = '120s';

    public function table(Table $table): Table
    {
        $today = CarbonImmutable::now(config('app.display_timezone'))->startOfDay()->utc();

        return $table
            ->query(fn () => Staff::query()->where('is_active', true)->with(['district', 'botUser'])
                ->withCount([
                    'createdObjects as objects_today' => fn (Builder $query) => $query->where('created_at', '>=', $today),
                    'inspections as evidence_today' => fn (Builder $query) => $query->where('submitted_at', '>=', $today),
                    'inspections as evidence_review' => fn (Builder $query) => $query->where('review', Inspection::PENDING),
                ]))
            ->defaultSort('objects_today', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('full_name')->label('Xodim')->description(fn (Staff $s) => $s->district?->name)
                    ->url(fn () => StaffResource::getUrl('index')),
                IconColumn::make('linked')->label('Botda')->boolean()->state(fn (Staff $s) => $s->user_id !== null),
                TextColumn::make('objects_today')->label('Tadbirkor (bugun)')->sortable()->alignCenter()
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
                TextColumn::make('evidence_today')->label('Dalil (bugun)')->sortable()->alignCenter()
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
                TextColumn::make('evidence_review')->label('Tekshiruvda')->alignCenter()
                    ->color(fn ($state) => $state ? 'warning' : 'gray'),
                TextColumn::make('botUser.last_seen')->label('Oxirgi faollik')->since()->placeholder('—'),
            ]);
    }
}
