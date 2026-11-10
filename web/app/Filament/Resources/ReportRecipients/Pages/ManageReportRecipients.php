<?php

namespace App\Filament\Resources\ReportRecipients\Pages;

use App\Filament\Resources\ReportRecipients\ReportRecipientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageReportRecipients extends ManageRecords
{
    protected static string $resource = ReportRecipientResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Oluvchi qo‘shish')];
    }
}
