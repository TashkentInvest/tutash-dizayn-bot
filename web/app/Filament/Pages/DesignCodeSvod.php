<?php

namespace App\Filament\Pages;

use App\Filament\NavGroups;
use App\Models\District;
use App\Services\DesignCodeSvod as Svod;
use BackedEnum;
use Carbon\Carbon;
use App\Models\ReportRecipient;
use App\Services\SvodImage;
use App\Services\SvodReport;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/** Design-code progress by district, and by street of a district picked in the table; sent to Telegram as pictures. */
class DesignCodeSvod extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|\UnitEnum|null $navigationGroup = NavGroups::STAFF;

    protected static ?string $navigationLabel = 'Dizayn-kod svod';

    protected static ?string $title = 'Dizayn-kod: tumanlar kesimida svod';

    protected static ?string $slug = 'dizayn-kod-svod';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.design-code-svod';

    // a wide table: use the whole width of the screen
    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url]
    public ?string $day = null;

    #[Url]
    public ?int $district = null;

    public function mount(): void
    {
        $this->day ??= Svod::reportDay()->toDateString();  // from 18:00 that is already tomorrow
    }

    private function shownDay(): Carbon
    {
        return Carbon::parse($this->day ?: Svod::reportDay()->toDateString(), config('app.display_timezone'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')->label('Hisobotni botga yuborish')->icon('heroicon-o-paper-airplane')
                ->modalHeading(fn () => 'Dizayn-kod hisoboti — '.$this->shownDay()->format('d.m.Y'))
                ->modalWidth(Width::FourExtraLarge)
                ->modalSubmitActionLabel('Yuborish')
                ->schema([
                    CheckboxList::make('kinds')->label('Rasmlar')->options(SvodImage::KINDS)->default(array_keys(SvodImage::KINDS))
                        ->columns(2)->required()->live(),
                    CheckboxList::make('to')->label('Kimlarga (oldindan — “Hisobot oluvchilar” bo‘limida qo‘shiladi)')
                        ->options(fn () => ReportRecipient::query()->with('botUser')->get()
                            ->mapWithKeys(fn (ReportRecipient $r) => [$r->user_id => $r->title()])->sort()->all())
                        ->searchable()->bulkToggleable()->columns(2)->required()
                        ->noSearchResultsMessage('Topilmadi')
                        ->validationMessages(['required' => 'Kamida bitta oluvchini belgilang.']),
                    View::make('filament.pages.svod-preview')->viewData(fn (Get $get) => [
                        'images' => collect($get('kinds') ?? [])->filter(fn ($k) => isset(SvodImage::KINDS[$k]))
                            ->mapWithKeys(fn ($k) => [SvodImage::KINDS[$k] => 'data:image/png;base64,'.base64_encode(SvodImage::png($k, $this->shownDay()))])->all(),
                    ]),
                ])
                ->action(function (array $data) {
                    $n = SvodReport::send($data['kinds'] ?? [], $data['to'] ?? [], $this->shownDay());
                    Notification::make()->success()->title('Hisobot yuborilmoqda')
                        ->body(count($data['to'] ?? []).' kishiga, '.$n.' ta rasm. Bot bir necha soniyada yetkazadi.')->send();
                }),
        ];
    }

    public function pick(?int $district): void
    {
        $this->district = $district;
    }

    protected function getViewData(): array
    {
        $day = $this->shownDay();

        return [
            'shownDay' => $day,
            'isToday' => $day->isSameDay(Svod::reportDay()),
            'maxDay' => Svod::reportDay()->toDateString(),
            'svod' => Svod::byDistrict($day),
            'streets' => $this->district ? Svod::byStreet($this->district, $day) : null,
            'districtName' => $this->district ? District::query()->whereKey($this->district)->value('name') : null,
            'waiting' => Svod::waiting(),
        ];
    }
}
