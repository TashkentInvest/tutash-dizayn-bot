<?php

namespace App\Filament\Resources\Streets\Pages;

use App\Filament\Resources\Streets\StreetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStreet extends EditRecord
{
    protected static string $resource = StreetResource::class;

    protected function getHeaderActions(): array
    {
        // a street with businesses cannot be deleted (foreign key): switch it off for the bot instead
        return [DeleteAction::make()->hidden(fn ($record) => $record->objects()->exists())];
    }
}
