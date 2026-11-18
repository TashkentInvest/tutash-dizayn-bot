<?php

namespace App\Filament\Resources\Inspections\Pages;

use App\Filament\Resources\Inspections\InspectionResource;
use App\Models\Inspection;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListInspections extends ListRecords
{
    protected static string $resource = InspectionResource::class;

    /** One tab per stage: what waits for the moderator first, "Jarayonda" (accepted with something to fix) on its own. */
    public function getTabs(): array
    {
        $tab = fn (string $label, array $reviews, string $color) => Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('review', $reviews))
            ->badge(fn () => Inspection::query()->whereIn('review', $reviews)->count() ?: null)->badgeColor($color);

        return [
            'open' => $tab('Tekshiruv kutmoqda', Inspection::OPEN, 'warning'),
            'process' => $tab(Inspection::reviews()[Inspection::CONFIRMING], [Inspection::CONFIRMING], 'primary'),
            'approved' => Tab::make(Inspection::reviews()[Inspection::APPROVED])
                ->modifyQueryUsing(fn (Builder $query) => $query->where('review', Inspection::APPROVED)),
            'rejected' => Tab::make(Inspection::reviews()[Inspection::REJECTED])
                ->modifyQueryUsing(fn (Builder $query) => $query->where('review', Inspection::REJECTED)),
            'all' => Tab::make('Hammasi')->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('review')),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'open';
    }
}
