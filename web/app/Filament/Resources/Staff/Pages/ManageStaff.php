<?php

namespace App\Filament\Resources\Staff\Pages;

use App\Filament\Resources\Staff\StaffResource;
use App\Models\District;
use App\Models\Staff;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Staff by district: a tab per district (with how many work there) and "Hammasi", where the list is grouped by district.
 * "Xodim qo'shish" on a district's tab adds the person to that district.
 */
class ManageStaff extends ManageRecords
{
    protected static string $resource = StaffResource::class;

    public function getTabs(): array
    {
        $counts = Staff::query()->selectRaw('district_id, COUNT(*) AS n')->groupBy('district_id')->pluck('n', 'district_id');
        $tabs = ['all' => Tab::make('Hammasi')->badge($counts->sum())];
        foreach (District::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name']) as $d) {
            $tabs['d'.$d->id] = Tab::make(trim(str_replace(' тумани', '', $d->name)))
                ->badge((int) ($counts[$d->id] ?? 0))->badgeColor(($counts[$d->id] ?? 0) ? 'primary' : 'gray')
                ->modifyQueryUsing(fn ($query) => $query->where('district_id', $d->id));
        }

        return $tabs;
    }

    /** The district of the open tab, null on "Hammasi". */
    public function activeDistrictId(): ?int
    {
        return str_starts_with((string) $this->activeTab, 'd') ? (int) substr($this->activeTab, 1) : null;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Xodim qo‘shish')
            ->modalHeading(fn () => ($id = $this->activeDistrictId()) ? District::find($id)?->name.' — xodim qo‘shish' : 'Xodim qo‘shish')
            ->fillForm(fn () => ['district_id' => $this->activeDistrictId(), 'is_active' => true])];
    }
}
