<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Pages\DistrictStaff;
use App\Filament\Resources\Staff\StaffResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

/** Every staff member with the bot link, invite link, assigned streets: opened from the district list. */
class ManageStaff extends ManageRecords
{
    protected static string $resource = StaffResource::class;

    protected static ?string $title = 'Xodimlar: bot va ko‘chalar';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('districts')->label('Tumanlar ro‘yxati')->icon('heroicon-o-arrow-left')->color('gray')->url(DistrictStaff::getUrl()),
            CreateAction::make()->label('Xodim qo‘shish')->fillForm(['is_active' => true]),
        ];
    }
}
