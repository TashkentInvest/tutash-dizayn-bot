<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Posts\PostResource;
use App\Models\Post;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Home page: the last posts and how they did (delivered, Tanishdim, seen — roughly). */
class PostsStats extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        return Post::query()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Botdagi postlar: statistika')
            ->description(PostResource::SEEN_HINT)
            ->query(fn () => Post::query()->latest('id')->limit(5))
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')->label('Sana')->dateTime('d.m.Y H:i')->timezone(config('app.display_timezone')),
                TextColumn::make('caption')->label('Post')->state(fn (Post $p) => $p->title())->wrap()
                    ->description(fn (Post $p) => Post::MEDIA[$p->media_type] ?? 'Matn'),
                TextColumn::make('total')->label('Oluvchilar')->alignEnd(),
                TextColumn::make('delivered')->label('Yetkazildi')->alignEnd()
                    ->formatStateUsing(fn ($state, Post $p) => $p->status === Post::SENDING ? "{$state} / {$p->total}…" : $state),
                TextColumn::make('ack')->label('Tanishdim')->alignEnd()->color('success')
                    ->state(fn (Post $p) => $p->ack_button ? PostResource::share($p->stats()['ack'], $p->delivered) : '—'),
                TextColumn::make('seen')->label('Ko‘rgan (taxminan)')->alignEnd()->weight('bold')
                    ->state(fn (Post $p) => PostResource::share($p->stats()['seen'], $p->delivered)),
            ])
            ->recordUrl(fn (Post $p) => PostResource::getUrl('view', ['record' => $p]));
    }
}
