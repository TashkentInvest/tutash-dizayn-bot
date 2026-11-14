<?php

namespace App\Filament\Resources\BotUsers\Pages;

use App\Filament\Resources\BotUsers\BotUserResource;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\BotUser;
use App\Models\District;
use App\Models\Staff;
use App\Services\BotUserReset;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewBotUser extends ViewRecord
{
    protected static string $resource = BotUserResource::class;

    public function getSubheading(): ?string
    {
        $s = Staff::query()->with('district')->where('user_id', $this->record->user_id)->first();

        return $s ? 'Tuman xodimi: '.$s->full_name.($s->district ? ' · '.$s->district->name : ' · tumanga biriktirilmagan') : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            // a staff member who joined the bot as a citizen: made staff here, linked to this Telegram account at once
            Action::make('staff')->label('Xodim qilib biriktirish')->icon('heroicon-o-identification')->color('success')
                ->visible(fn (BotUser $record) => ! Staff::query()->where('user_id', $record->user_id)->exists())
                ->modalHeading('Tuman xodimi qilib biriktirish')
                ->modalDescription('Shu Telegram akkaunt xodim bo‘ladi: /start bosganda xodim menyusi va Mini App ochiladi. '
                    .'Raqam xodimlar ro‘yxatida bo‘lsa, o‘sha yozuv shu akkauntga bog‘lanadi.')
                ->fillForm(fn (BotUser $record) => ['full_name' => $record->full_name, 'phone' => $record->phone ? Phone::pretty($record->phone) : null])
                ->schema([
                    Select::make('district_id')->label('Tuman')->required()
                        ->options(fn () => District::query()->orderBy('sort_order')->pluck('name', 'id')),
                    TextInput::make('full_name')->label('F.I.Sh.')->required()->maxLength(150),
                    TextInput::make('phone')->label('Telefon')->tel()->required()->placeholder('+998 90 123 45 67')
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            if (Phone::normalize($value) === null) {
                                $fail('Raqam noto‘g‘ri. Namuna: +998 90 123 45 67');
                            }
                        }),
                ])
                ->modalSubmitActionLabel('Biriktirish')
                ->action(function (BotUser $record, array $data) {
                    $phone = Phone::normalize($data['phone']);
                    $s = DB::connection('bot')->transaction(function () use ($data, $phone) {
                        $s = Staff::query()->where('phone', $phone)->first() ?? new Staff(['is_active' => true]);
                        $s->forceFill(['full_name' => $data['full_name'], 'phone' => $phone, 'district_id' => (int) $data['district_id'],
                            'is_active' => true] + ($s->exists && (int) $s->district_id !== (int) $data['district_id'] ? ['priority_streets' => null] : []))->save();

                        return $s;
                    });
                    StaffResource::linkToBot($s->fresh(), (int) $record->user_id);
                }),
            ActionGroup::make([
                // a made-up name, a typo: corrected here (the phone only through the bot's contact button, it is proof)
                Action::make('edit')->label('Ismni tahrirlash')->icon('heroicon-o-pencil-square')
                    ->fillForm(fn (BotUser $record) => ['full_name' => $record->full_name])
                    ->schema([TextInput::make('full_name')->label('F.I.Sh.')->required()->minLength(5)->maxLength(120)])
                    ->modalSubmitActionLabel('Saqlash')
                    ->action(function (BotUser $record, array $data) {
                        $record->forceFill(['full_name' => trim(preg_replace('/\s+/u', ' ', $data['full_name']))])->save();
                        Notification::make()->success()->title('Saqlandi')->send();
                    }),
                Action::make('unstaff')->label('Xodimlikdan chiqarish')->icon('heroicon-o-user-minus')->color('warning')
                    ->visible(fn (BotUser $record) => Staff::query()->where('user_id', $record->user_id)->exists())
                    ->requiresConfirmation()
                    ->modalDescription('Xodim yozuvi qoladi, faqat shu Telegram akkaunt bilan bog‘lanishi uziladi: bot uni fuqaro deb biladi.')
                    ->action(function (BotUser $record) {
                        Staff::query()->where('user_id', $record->user_id)->update(['user_id' => null]);
                        Notification::make()->success()->title('Bog‘lanish uzildi')->send();
                    }),
                // a made-up name, someone else's phone: the person registers again at the next /start
                Action::make('reset')->label('Qayta ro‘yxatdan o‘tkazish')->icon('heroicon-o-arrow-path')->color('danger')
                    ->visible(fn (BotUser $record) => $record->full_name || $record->phone || $record->consent_at || $record->lang)
                    ->requiresConfirmation()
                    ->modalHeading('Ma’lumotlarni o‘chirib, qayta ro‘yxatdan o‘tkazish')
                    ->modalDescription(fn (BotUser $record) => 'F.I.Sh., telefon, rozilik, til, obyekt joyi va rasmlari o‘chiriladi'
                        .(($s = Staff::query()->where('user_id', $record->user_id)->value('full_name')) ? ", xodim «{$s}» bilan bog‘lanishi uziladi" : '')
                        .'. Keyingi /start da bot hammasini qaytadan so‘raydi. Yuklab olishlar va murojaatlar tarixi qoladi.')
                    ->schema([Toggle::make('notify')->label('Botda xabar yuborish: “/start ni bosing va qaytadan ro‘yxatdan o‘ting”')->default(true)])
                    ->modalSubmitActionLabel('O‘chirish')
                    ->action(function (BotUser $record, array $data) {
                        $staff = BotUserReset::reset($record, (bool) ($data['notify'] ?? false));
                        $record->refresh();
                        Notification::make()->success()->title('Ma’lumotlar o‘chirildi')
                            ->body('Keyingi /start da qaytadan ro‘yxatdan o‘tadi.'.($staff ? " Xodim «{$staff}» bilan bog‘lanish uzildi." : ''))->send();
                    }),
            ])->label('Tahrirlash')->icon('heroicon-o-pencil-square')->button()->color('gray'),
        ];
    }
}
