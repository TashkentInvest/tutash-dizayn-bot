<?php

namespace App\Filament\Resources\BotUsers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DownloadsRelationManager extends RelationManager
{
    protected static string $relationship = 'downloads';

    protected static ?string $title = 'Yuklab olingan fayllar';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sent_at', 'desc')
            ->columns([
                TextColumn::make('sent_at')->label('Vaqt')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('district')->label('Tuman')->searchable(),
                TextColumn::make('street')->label('Ko‘cha')->searchable(),
                TextColumn::make('file_name')->label('Fayl')->limit(50),
            ]);
    }
}
