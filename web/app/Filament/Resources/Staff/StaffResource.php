<?php

namespace App\Filament\Resources\Staff;

use App\Filament\NavGroups;
use App\Filament\Resources\Staff\Pages\ManageStaff;
use App\Models\District;
use App\Models\Inspection;
use App\Models\Staff;
use App\Models\Street;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** District staff: who checks the businesses of which district. They sign in to the bot with this phone. */
class StaffResource extends Resource
{
    protected static ?string $model = Staff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::STAFF;

    protected static ?string $modelLabel = 'xodim';

    protected static ?string $pluralModelLabel = 'Xodimlar';

    protected static ?string $navigationLabel = 'Xodimlar';

    protected static ?string $slug = 'xodimlar';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('full_name')->label('F.I.Sh.')->required()->maxLength(150),
            TextInput::make('phone')->label('Telefon')->tel()->required()->placeholder('+998 90 123 45 67')
                ->helperText('Xodim botda shu raqamni “Raqamni yuborish” tugmasi bilan tasdiqlaydi.')
                // checked on the normalized number, the way it is stored: "90 123 45 67" and "+998901234567" are the same
                ->rules([fn (?Staff $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                    $phone = Phone::normalize($value);
                    if ($phone === null) {
                        $fail('Raqam noto‘g‘ri. Namuna: +998 90 123 45 67');
                    } elseif (Staff::query()->where('phone', $phone)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists()) {
                        $fail('Bu raqam boshqa xodimga biriktirilgan.');
                    }
                }])
                ->dehydrateStateUsing(fn ($state) => Phone::normalize($state)),
            Select::make('district_id')->label('Tuman')->required()->live()
                ->options(fn () => District::query()->orderBy('sort_order')->pluck('name', 'id')),
            Select::make('priority_streets')->label('Asosiy ko‘chalar')->multiple()->searchable()
                ->helperText('Mini App’da tepada turadi. Xodim tumanning boshqa ko‘chalarini ham ko‘radi.')
                ->options(fn (Get $get) => $get('district_id')
                    ? Street::query()->where('district_id', $get('district_id'))->orderBy('sort_order')->pluck('name', 'id')
                    : [])
                ->dehydrateStateUsing(fn ($state) => array_values(array_map('intval', (array) $state))),
            Textarea::make('streets_note')->label('Biriktirilgan ko‘chalar (izoh)')->rows(2)->columnSpanFull(),
            Toggle::make('is_active')->label('Faol')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('district')->withCount([
                'inspections as sent_count' => fn ($i) => $i->whereNotNull('review'),
                'inspections as approved_count' => fn ($i) => $i->where('review', Inspection::APPROVED),
            ]))
            ->defaultSort('district_id')
            ->columns([
                TextColumn::make('full_name')->label('F.I.Sh.')->searchable()->description(fn (Staff $s) => $s->streets_note),
                TextColumn::make('phone')->label('Telefon')->formatStateUsing(fn ($state) => Phone::pretty($state))->searchable()->copyable(),
                TextColumn::make('district.name')->label('Tuman')->sortable(),
                IconColumn::make('linked')->label('Botda tasdiqlangan')->boolean()->state(fn (Staff $s) => $s->user_id !== null)
                    ->tooltip(fn (Staff $s) => $s->user_id ? 'Telegram ID: '.$s->user_id : 'Hali botda /start bosib, raqamini tasdiqlamagan'),
                TextColumn::make('sent_count')->label('Yuborgan dalillar'),
                TextColumn::make('approved_count')->label('Tasdiqlangan'),
                ToggleColumn::make('is_active')->label('Faol'),
            ])
            ->filters([SelectFilter::make('district_id')->label('Tuman')->options(fn () => District::query()->orderBy('sort_order')->pluck('name', 'id'))])
            ->recordActions([
                EditAction::make(),
                Action::make('unlink')->label('Telegram bog‘lanishini uzish')->icon('heroicon-o-link-slash')->color('gray')
                    ->visible(fn (Staff $s) => $s->user_id !== null)->requiresConfirmation()
                    ->modalDescription('Xodim telefonini almashtirgan bo‘lsa: keyingi /start da raqamini qayta tasdiqlaydi.')
                    ->action(fn (Staff $s) => $s->forceFill(['user_id' => null])->save()),
                DeleteAction::make()->visible(fn (Staff $s) => ! $s->inspections()->exists())
                    ->modalDescription('Dalil yubormagan xodimgina o‘chiriladi. Ishlagan xodimni “Faol” belgisini o‘chirib qo‘ying.'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageStaff::route('/')];
    }
}
