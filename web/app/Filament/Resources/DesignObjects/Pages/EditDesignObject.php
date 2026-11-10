<?php

namespace App\Filament\Resources\DesignObjects\Pages;

use App\Filament\Resources\DesignObjects\DesignObjectResource;
use Filament\Resources\Pages\EditRecord;

class EditDesignObject extends EditRecord
{
    protected static string $resource = DesignObjectResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $data + ['updated_at' => now()->utc()->format('Y-m-d H:i:s')];
    }
}
