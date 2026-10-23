<?php

namespace App\Filament\Resources\Downloads\Pages;

use App\Filament\Resources\Downloads\DownloadResource;
use App\Services\DownloadsExport;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListDownloads extends ListRecords
{
    protected static string $resource = DownloadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Excelga yuklab olish (.xlsx)')
                ->icon('heroicon-o-table-cells')
                ->color('success')
                ->tooltip('Jadvaldagi hozirgi filtr va qidiruv bo‘yicha')
                ->action(fn () => $this->exportToExcel()),
        ];
    }

    /** Exports exactly what the table shows now: the active filters, the search and the sort order. */
    public function exportToExcel(): ?BinaryFileResponse
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dl');
        $path = $tmp.'.xlsx';
        rename($tmp, $path);

        $rows = DownloadsExport::write($this->getFilteredSortedTableQuery(), $path);
        if ($rows === 0) {
            @unlink($path);
            Notification::make()->title('Yuklab olish uchun yozuv yo‘q')->body('Joriy filtrga mos yozuvlar topilmadi.')->warning()->send();

            return null;
        }

        return response()->download($path, 'yuklab-olishlar-'.now(config('app.display_timezone'))->format('Ymd-His').'.xlsx')
            ->deleteFileAfterSend();
    }
}
