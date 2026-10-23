<?php

namespace App\Filament\Resources\Streets\Pages;

use App\Filament\Resources\Streets\StreetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStreets extends ListRecords
{
    protected static string $resource = StreetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
