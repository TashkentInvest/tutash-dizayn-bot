<?php

namespace App\Filament\Resources\BotUsers\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BotUserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Shaxs')->columns(3)->schema([
                TextEntry::make('full_name')->label('F.I.SH.')->placeholder('—'),
                TextEntry::make('phone')->label('Telefon')->placeholder('—')->copyable(),
                TextEntry::make('username')->label('Username')->prefix('@')->placeholder('—')
                    ->url(fn ($record) => $record->username ? "https://t.me/{$record->username}" : null, shouldOpenInNewTab: true),
                TextEntry::make('user_id')->label('Telegram ID')->copyable(),
                TextEntry::make('language_code')->label('Til')->placeholder('—'),
                TextEntry::make('registered_at')->label('Ro‘yxatdan o‘tgan vaqt')->dateTime('d.m.Y H:i')->placeholder('Ro‘yxatdan o‘tmagan'),
            ]),
            Section::make('Obyekt')->columns(2)->schema([
                TextEntry::make('location')->label('Lokatsiya')->placeholder('—')
                    ->state(fn ($record) => $record->latitude === null ? null : "{$record->latitude}, {$record->longitude}")
                    ->url(fn ($record) => $record->map_url, shouldOpenInNewTab: true)
                    ->icon('heroicon-o-map-pin')->helperText('Xaritada ochish uchun bosing'),
                ImageEntry::make('photo_urls')->label('Obyekt rasmlari')
                    ->state(fn ($record) => $record->photos->map(fn ($p) => $p->url)->all())
                    ->imageHeight(260)->checkFileExistence(false)->columnSpanFull(),
            ]),
            Section::make('Faollik')->columns(3)->schema([
                TextEntry::make('first_seen')->label('Birinchi kirish')->dateTime('d.m.Y H:i'),
                TextEntry::make('last_seen')->label('Oxirgi faollik')->dateTime('d.m.Y H:i'),
                TextEntry::make('start_count')->label('/start soni'),
            ]),
        ]);
    }
}
