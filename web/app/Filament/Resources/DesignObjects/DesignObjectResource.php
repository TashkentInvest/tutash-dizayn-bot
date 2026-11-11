<?php

namespace App\Filament\Resources\DesignObjects;

use App\Filament\NavGroups;
use App\Filament\Resources\DesignObjects\Pages\CreateDesignObject;
use App\Filament\Resources\DesignObjects\Pages\EditDesignObject;
use App\Filament\Resources\DesignObjects\Pages\ListDesignObjects;
use App\Filament\Resources\DesignObjects\Pages\ViewDesignObject;
use App\Models\DesignObject;
use App\Models\District;
use App\Models\Street;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Businesses on the streets (added by district staff) and how far each is with the design code. */
class DesignObjectResource extends Resource
{
    protected static ?string $model = DesignObject::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::STAFF;

    protected static ?string $modelLabel = 'obyekt';

    protected static ?string $pluralModelLabel = 'Obyektlar (tadbirkorlar)';

    protected static ?string $navigationLabel = 'Obyektlar (tadbirkorlar)';

    protected static ?string $slug = 'obyektlar';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('street_id')->label('Ko‘cha')->required()->searchable()
                ->options(fn () => Street::query()->with('district')->orderBy('district_id')->orderBy('name')->get()
                    ->mapWithKeys(fn ($s) => [$s->id => $s->district?->name.' · '.$s->name])),
            TextInput::make('shop_name')->label('Do‘kon / firma nomi')->required()->maxLength(150),
            TextInput::make('address')->label('Manzil')->maxLength(200),
            TextInput::make('owner_name')->label('Tadbirkor F.I.Sh.')->maxLength(150),
            TextInput::make('owner_phone')->label('Tadbirkor telefoni')->tel()->maxLength(30)
                ->dehydrateStateUsing(fn ($state) => filled($state) ? (Phone::normalize($state) ?? $state) : null),
            TextInput::make('stir')->label('STIR')->regex('/^\d{9}$|^\d{14}$/')->validationMessages(['regex' => 'STIR: 9 ta raqam (JShShIR: 14).']),
            Select::make('status')->label('Holat')->options(DesignObject::STATUSES)->required()
                ->helperText('Odatda holat xodim dalili tasdiqlanganda o‘zgaradi. Bu yerda faqat xatoni tuzatish uchun.'),
            Toggle::make('is_active')->label('Hisobga olinsin')->default(true)
                ->helperText('Xato yoki takror kiritilgan bo‘lsa, belgini o‘chiring: svod va xodim ro‘yxatidan chiqadi.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('street.district.name')->label('Tuman'),
                TextEntry::make('street.name')->label('Ko‘cha'),
                TextEntry::make('creator.full_name')->label('Kiritgan xodim')->placeholder('admin'),
                TextEntry::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state) => DesignObject::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => DesignObject::STATUS_COLORS[$state] ?? 'gray'),
                TextEntry::make('shop_name')->label('Do‘kon / firma')->placeholder('—'),
                TextEntry::make('address')->label('Manzil')->placeholder('—'),
                TextEntry::make('owner_name')->label('Tadbirkor')->placeholder('—'),
                TextEntry::make('owner_phone')->label('Telefon')->placeholder('—')->formatStateUsing(fn ($state) => Phone::pretty($state)),
                TextEntry::make('stir')->label('STIR')->placeholder('—')->copyable(),
                TextEntry::make('point')->label('Joylashuv')->placeholder('Hali tasdiqlangan dalil yo‘q')
                    ->state(fn (DesignObject $o) => $o->latitude ? "{$o->latitude}, {$o->longitude}" : null)
                    ->url(fn (DesignObject $o) => $o->latitude ? "https://maps.google.com/?q={$o->latitude},{$o->longitude}" : null, shouldOpenInNewTab: true),
            ]),
            Section::make('Ko‘chaning konsepsiyasi (PDF)')->schema([
                ViewEntry::make('street_files')->hiddenLabel()->view('filament.objects.street-files'),
            ]),
            Section::make('Xodim dalillari')->schema([
                ViewEntry::make('inspections')->hiddenLabel()->view('filament.objects.inspections'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['street.district', 'pendingInspection', 'creator']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('street.district.name')->label('Tuman'),
                TextColumn::make('street.name')->label('Ko‘cha')->wrap(),
                TextColumn::make('id')->label('№')->sortable(),
                TextColumn::make('shop_name')->label('Do‘kon / firma')->placeholder('—')->searchable()
                    ->description(fn (DesignObject $o) => trim(implode(' · ', array_filter([$o->address, $o->owner_name, $o->stir ? 'STIR '.$o->stir : null]))) ?: null),
                TextColumn::make('status')->label('Holat')->badge()
                    ->formatStateUsing(fn ($state) => DesignObject::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => DesignObject::STATUS_COLORS[$state] ?? 'gray')
                    ->description(fn (DesignObject $o) => $o->pendingInspection ? '⏳ dalil tekshiruvda' : null),
            ])
            ->filters([
                SelectFilter::make('district')->label('Tuman')
                    ->options(fn () => District::query()->orderBy('sort_order')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->inDistrict((int) $data['value']) : $query),
                SelectFilter::make('street_id')->label('Ko‘cha')->searchable()
                    ->options(fn () => Street::query()->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('status')->label('Holat')->options(DesignObject::STATUSES),
                Filter::make('in_review')->label('Dalili tekshiruvda')->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('pendingInspection')),
                TernaryFilter::make('is_active')->label('Hisobga olinadi')->default(true),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDesignObjects::route('/'),
            'create' => CreateDesignObject::route('/create'),
            'view' => ViewDesignObject::route('/{record}'),
            'edit' => EditDesignObject::route('/{record}/edit'),
        ];
    }
}
