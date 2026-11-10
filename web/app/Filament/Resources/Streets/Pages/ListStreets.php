<?php

namespace App\Filament\Resources\Streets\Pages;

use App\Filament\Resources\Files\FileResource;
use App\Filament\Resources\Streets\StreetResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStreets extends ListRecords
{
    protected static string $resource = StreetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // the duplicate-file report is not in the menu any more: it opens from here
            Action::make('files')->label('Barcha fayllar / dublikatlar')->icon('heroicon-o-document-duplicate')->color('gray')
                ->url(FileResource::getUrl('index')),
            CreateAction::make(),
        ];
    }
}
