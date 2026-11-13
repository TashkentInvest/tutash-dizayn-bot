<?php

namespace App\Filament\Resources\BotUsers\Pages;

use App\Filament\Resources\BotUsers\BotUserResource;
use App\Models\BotUser;
use App\Services\BotUserReset;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewBotUser extends ViewRecord
{
    protected static string $resource = BotUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // a made-up name, someone else's phone: the person registers again at the next /start
            Action::make('reset')->label('Qayta ro‘yxatdan o‘tkazish')->icon('heroicon-o-arrow-path')->color('danger')
                ->visible(fn (BotUser $record) => $record->full_name || $record->phone || $record->consent_at || $record->lang)
                ->requiresConfirmation()
                ->modalHeading('Ma’lumotlarni o‘chirib, qayta ro‘yxatdan o‘tkazish')
                ->modalDescription(fn (BotUser $record) => 'F.I.Sh., telefon, rozilik, til, obyekt joyi va rasmlari o‘chiriladi'
                    .(($s = \App\Models\Staff::query()->where('user_id', $record->user_id)->value('full_name')) ? ", xodim «{$s}» bilan bog‘lanishi uziladi" : '')
                    .'. Keyingi /start da bot hammasini qaytadan so‘raydi. Yuklab olishlar va murojaatlar tarixi qoladi.')
                ->schema([Toggle::make('notify')->label('Botda xabar yuborish: “/start ni bosing va qaytadan ro‘yxatdan o‘ting”')->default(true)])
                ->modalSubmitActionLabel('O‘chirish')
                ->action(function (BotUser $record, array $data) {
                    $staff = BotUserReset::reset($record, (bool) ($data['notify'] ?? false));
                    $record->refresh();
                    Notification::make()->success()->title('Ma’lumotlar o‘chirildi')
                        ->body('Keyingi /start da qaytadan ro‘yxatdan o‘tadi.'.($staff ? " Xodim «{$staff}» bilan bog‘lanish uzildi." : ''))->send();
                }),
        ];
    }
}
