<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Filament\Actions\PostAction;
use App\Filament\Resources\Posts\PostResource;
use Filament\Resources\Pages\ListRecords;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    public function getSubheading(): ?string
    {
        return PostResource::SEEN_HINT;
    }

    protected function getHeaderActions(): array
    {
        return [PostAction::make()];
    }
}
