<?php

namespace App\Filament\Resources\DesignObjects\Pages;

use App\Filament\Resources\DesignObjects\DesignObjectResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDesignObject extends ViewRecord
{
    protected static string $resource = DesignObjectResource::class;

    public function getTitle(): string
    {
        return $this->record->street?->name.' · '.$this->record->shop_name;
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
