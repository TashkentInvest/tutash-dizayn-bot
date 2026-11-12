<?php

namespace App\Filament\Pages;

use App\Filament\NavGroups;
use App\Filament\Resources\Staff\StaffResource;
use App\Models\District;
use App\Models\Staff;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * "Xodimlar": the 12 districts and who works in each. A click on a district opens one list of people to tick: the ticked
 * ones work in this district (someone from another district moves here), the unticked ones are taken off it. A new person
 * can be added right in that list. Bot link, invite link and assigned streets: the full list (StaffResource).
 */
class DistrictStaff extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::STAFF;

    protected static ?string $navigationLabel = 'Xodimlar';

    protected static ?string $title = 'Xodimlar';

    protected static ?string $slug = 'xodimlar';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.district-staff';

    public function getSubheading(): ?string
    {
        $free = Staff::query()->whereNull('district_id')->orderBy('full_name')->pluck('full_name');

        return 'Tumanni bosing va unga xodimlarni biriktiring.'
            .($free->isNotEmpty() ? ' Hech qaysi tumanga biriktirilmagan: '.$free->implode(', ').'.' : '');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('all')->label('Bot va ko‘chalar')->icon('heroicon-o-list-bullet')->color('gray')
            ->url(StaffResource::getUrl('index'))];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(District::query()->with(['staff' => fn ($q) => $q->orderBy('full_name')])->withCount('staff'))
            ->defaultSort('sort_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Tuman')->weight('bold'),
                TextColumn::make('people')->label('Xodimlar')->badge()->placeholder('Xodim biriktirilmagan')
                    ->state(fn (District $d) => $d->staff->map(fn (Staff $s) => $s->full_name)->all())
                    // green: works in the bot, grey: not linked to the bot yet, red: switched off
                    ->color(fn (string $state, District $d) => match (true) {
                        ! ($s = $d->staff->firstWhere('full_name', $state)) => 'gray',
                        ! $s->is_active => 'danger',
                        $s->user_id !== null => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('staff_count')->label('Soni')->alignCenter(),
            ])
            // the whole row opens the list to tick
            ->recordAction('assign')
            ->recordActions([
                Action::make('assign')->label('Xodimlarni biriktirish')->icon('heroicon-o-user-plus')->color('primary')
                    ->modalHeading(fn (District $d) => $d->name.': xodimlar')
                    ->modalSubmitActionLabel('Saqlash')
                    ->fillForm(fn (District $d) => ['staff' => $d->staff()->pluck('id')->map(fn ($id) => (int) $id)->all()])
                    ->schema([
                        Select::make('staff')->label('Xodimlar')->multiple()->searchable()->preload()
                            ->options(fn () => self::people())
                            ->helperText('Boshqa tumandagi xodimni tanlasangiz, u shu tumanga o‘tadi. Ro‘yxatdan olib tashlangan xodim '
                                .'hech qaysi tumanga biriktirilmagan bo‘lib qoladi (bot bilan bog‘lanishi va dalillari saqlanadi). '
                                .'Yangi xodim: ro‘yxat oxiridagi “+” tugmasi.')
                            ->createOptionForm([StaffResource::nameField(), StaffResource::phoneField()])
                            ->createOptionModalHeading('Yangi xodim')
                            ->createOptionUsing(fn (array $data) => Staff::create($data + ['is_active' => true])->getKey()),
                    ])
                    ->action(fn (District $d, array $data) => self::assign($d, array_map('intval', (array) ($data['staff'] ?? [])))),
            ]);
    }

    /** @return array<int, string> every staff member, with the district they are in now */
    private static function people(): array
    {
        return Staff::query()->with('district')->orderBy('full_name')->get()->mapWithKeys(fn (Staff $s) => [
            $s->id => $s->full_name.' · '.Phone::pretty($s->phone)
                .' ('.($s->district ? trim(str_replace(' тумани', '', $s->district->name)) : 'biriktirilmagan').')',
        ])->all();
    }

    /** The ticked people work in this district from now on; the others who were here are taken off it. */
    public static function assign(District $d, array $ids): void
    {
        DB::connection('bot')->transaction(function () use ($d, $ids) {
            // a change of district drops the streets assigned in the old one (they belong to that district)
            Staff::query()->where('district_id', $d->id)->whereNotIn('id', $ids)->update(['district_id' => null, 'priority_streets' => null]);
            Staff::query()->whereIn('id', $ids)->where(fn ($q) => $q->whereNull('district_id')->orWhere('district_id', '!=', $d->id))
                ->update(['district_id' => $d->id, 'priority_streets' => null]);
        });
        Notification::make()->success()->title('Saqlandi')->body($d->name.': '.count($ids).' xodim')->send();
    }
}
