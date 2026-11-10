<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Resources\Inspections\InspectionResource;
use App\Models\Feedback;
use App\Models\Inspection;
use Filament\Widgets\Widget;

/** What waits for an admin: unanswered citizens' requests and staff evidence to review (newest first). */
class PendingWork extends Widget
{
    protected static ?int $sort = 5;

    protected string $view = 'filament.widgets.pending-work';

    protected ?string $pollingInterval = '60s';

    protected function getViewData(): array
    {
        return [
            'feedback' => Feedback::query()->with('user')->whereNull('answer')
                ->orderByRaw("CASE level WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")->latest('created_at')->limit(5)->get(),
            'feedbackCount' => Feedback::query()->whereNull('answer')->count(),
            'feedbackUrl' => FeedbackResource::getUrl('index').'?'.http_build_query(['filters' => ['status' => ['value' => 'new']]]),
            'inspections' => Inspection::query()->with(['staff', 'object.street'])->where('review', Inspection::PENDING)
                ->oldest('submitted_at')->limit(5)->get(),
            'inspectionCount' => Inspection::query()->where('review', Inspection::PENDING)->count(),
            'inspectionUrl' => InspectionResource::getUrl('index'),
            'tz' => config('app.display_timezone'),
        ];
    }
}
