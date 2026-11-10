<?php

namespace App\Filament\Pages;

use App\Filament\NavGroups;
use App\Models\District;
use App\Services\DesignCodeSvod as Svod;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** Design-code progress by district, and by street of a district picked in the table. Dashboard only (no Telegram report). */
class DesignCodeSvod extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::STAFF;

    protected static ?string $navigationLabel = 'Dizayn-kod svod';

    protected static ?string $title = 'Dizayn-kod: tumanlar kesimida svod';

    protected static ?string $slug = 'dizayn-kod-svod';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.design-code-svod';

    #[Url]
    public ?string $day = null;

    #[Url]
    public ?int $district = null;

    public function mount(): void
    {
        $this->day ??= now(config('app.display_timezone'))->toDateString();
    }

    public function pick(?int $district): void
    {
        $this->district = $district;
    }

    protected function getViewData(): array
    {
        $day = Carbon::parse($this->day ?: 'today', config('app.display_timezone'));

        return [
            'shownDay' => $day,
            'svod' => Svod::byDistrict($day),
            'streets' => $this->district ? Svod::byStreet($this->district, $day) : null,
            'districtName' => $this->district ? District::query()->whereKey($this->district)->value('name') : null,
            'waiting' => Svod::waiting(),
        ];
    }
}
