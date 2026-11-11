<?php

namespace App\Filament\Resources\ReportRecipients\Pages;

use App\Filament\Resources\ReportRecipients\ReportRecipientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use App\Services\ScheduledReports;

class ManageReportRecipients extends ManageRecords
{
    protected static string $resource = ReportRecipientResource::class;

    public function getSubheading(): ?string
    {
        return 'Bot hisobotlarni o‘zi yuboradi: soatlik holat 09:00–17:00 da har soat, kunlik svod va davomat 18:00 da. '
            .'Oluvchi botda /start bosgan bo‘lishi kerak.';
    }

    protected function getHeaderActions(): array
    {
        return [
            // try a report now, to everyone who gets it (or to nobody when no one does)
            \Filament\Actions\Action::make('sendNow')->label('Hozir yuborib ko‘rish')->icon('heroicon-o-paper-airplane')->color('gray')
                ->schema([\Filament\Forms\Components\Select::make('type')->label('Hisobot')->options(ScheduledReports::TYPES)->required()->native(false)])
                ->modalDescription('Tanlangan hisobot uni oladigan hamma oluvchiga hozir yuboriladi.')
                ->action(function (array $data) {
                    $n = ScheduledReports::send($data['type']);
                    \Filament\Notifications\Notification::make()->title($n ? "$n ta xabar yuborilmoqda" : 'Bu hisobotni oladigan oluvchi yo‘q')
                        ->{$n ? 'success' : 'warning'}()->send();
                }),
            CreateAction::make()->label('Oluvchi qo‘shish'),
        ];
    }
}
