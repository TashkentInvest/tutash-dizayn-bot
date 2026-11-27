<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Resources\Posts\PostResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPost extends ViewRecord
{
    protected static string $resource = PostResource::class;

    public function getTitle(): string
    {
        return $this->record->title();
    }

    protected function getHeaderActions(): array
    {
        return [PostResource::recallAction(), PostResource::forgetAction()];
    }
}
