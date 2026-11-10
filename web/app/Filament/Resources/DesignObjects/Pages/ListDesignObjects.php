<?php

namespace App\Filament\Resources\DesignObjects\Pages;

use App\Filament\Resources\DesignObjects\DesignObjectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDesignObjects extends ListRecords
{
    protected static string $resource = DesignObjectResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tadbirkor qo‘shish')];
    }
}
