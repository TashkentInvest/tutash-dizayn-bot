<?php

namespace App\Filament\Resources\BotUsers;

use App\Filament\Resources\BotUsers\Pages\ListBotUsers;
use App\Filament\Resources\BotUsers\Pages\ViewBotUser;
use App\Filament\Resources\BotUsers\RelationManagers\DownloadsRelationManager;
use App\Filament\Resources\BotUsers\Schemas\BotUserInfolist;
use App\Filament\Resources\BotUsers\Tables\BotUsersTable;
use App\Models\BotUser;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BotUserResource extends Resource
{
    protected static ?string $model = BotUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $modelLabel = 'foydalanuvchi';

    protected static ?string $pluralModelLabel = 'Foydalanuvchilar';

    protected static ?int $navigationSort = 1;

    // The bot owns this data: the dashboard only reads it.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->withCount(['downloads', 'photos']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BotUserInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BotUsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [DownloadsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBotUsers::route('/'),
            'view' => ViewBotUser::route('/{record}'),
        ];
    }
}
