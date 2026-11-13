<?php

namespace App\Filament\Resources\BotUsers\Pages;

use App\Filament\Resources\BotUsers\BotUserResource;
use App\Services\Broadcast;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListBotUsers extends ListRecords
{
    protected static string $resource = BotUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // a message to many at once, e.g. "press /start and give your name and phone": sent by the bot through the outbox
            Action::make('broadcast')->label('Xabar yuborish')->icon('heroicon-o-megaphone')->color('primary')
                ->modalHeading('Bot foydalanuvchilariga xabar')
                ->modalDescription('Bot xabarni navbat bilan yuboradi (bir necha daqiqa). Botni bloklaganlarga yuborilmaydi. '
                    .'/start bosgan odamdan F.I.Sh., telefon raqami va rozilik so‘ralmaguncha bot hech narsa bermaydi.')
                ->modalSubmitActionLabel('Yuborish')
                ->fillForm(['who' => Broadcast::INCOMPLETE, 'text' => Broadcast::START_TEXT])
                ->schema([
                    Radio::make('who')->label('Kimlarga')->required()
                        ->options(fn () => collect(Broadcast::AUDIENCES)
                            ->map(fn ($label, $key) => $label.' — '.Broadcast::audience($key)->count().' kishi')->all()),
                    Textarea::make('text')->label('Matn')->required()->rows(6)->maxLength(3500),
                ])
                ->action(function (array $data) {
                    $n = Broadcast::send($data['who'], $data['text']);
                    Notification::make()->success()->title("Navbatga qo‘yildi: $n ta xabar")
                        ->body('Bot ularni bir necha daqiqada yuboradi.')->send();
                }),
        ];
    }
}
