<?php

namespace App\Filament\Actions;

use App\Models\AuditLog;
use App\Models\Post;
use App\Models\PostDelivery;
use App\Services\MediaProbe;
use App\Services\PostAudience;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * "Post yuborish" (home page and "Postlar"): a picture or video and a text to the people ticked. The bot sends it
 * (bot/posts.py) at Telegram's pace; the numbers are on "Postlar" and on the home page.
 */
class PostAction
{
    public const DISK = 'bot_uploads';

    /** groups -> districts -> the people themselves (all ticked; the admin unticks who should not get it) */
    public static function audience(): array
    {
        $options = fn (Get $get) => PostAudience::options($get('groups') ?? [], $get('districts') ?? []);
        $refresh = fn (Get $get, Set $set) => $set('people', array_keys($options($get)));

        return [
            CheckboxList::make('groups')->label('Bo‘limlar')->options(PostAudience::groups())
                ->default(array_keys(PostAudience::GROUPS))->columns(3)->required()->live()
                ->helperText('Aholi standart holatda belgilanmagan va tuman bo‘yicha saralanmaydi: belgilansa, botdagi barcha fuqarolarga boradi.')
                ->validationMessages(['required' => 'Kamida bitta bo‘limni belgilang.'])
                ->afterStateUpdated($refresh),
            Select::make('districts')->label('Tumanlar')->multiple()->native(false)->placeholder('Barcha tumanlar')
                ->options(fn () => DB::connection('bot')->table('districts')->orderBy('sort_order')->pluck('name', 'id'))
                ->helperText('Faqat tuman xodimlariga ta’sir qiladi.')->live()->afterStateUpdated($refresh),
            CheckboxList::make('people')
                ->label(fn (Get $get) => 'Kimlarga — belgilangan: '.count($get('people') ?? []).' ta')
                ->options($options)
                ->default(fn () => array_keys(PostAudience::options(array_keys(PostAudience::GROUPS))))
                ->searchable()->bulkToggleable()->columns(2)->required()->live()
                ->searchPrompt('Ism yoki tuman bo‘yicha qidirish')->noSearchResultsMessage('Topilmadi')
                ->validationMessages(['required' => 'Kamida bitta oluvchini belgilang.']),
        ];
    }

    public static function make(): Action
    {
        return Action::make('post')->label('Post yuborish')->icon('heroicon-o-photo')->color('primary')
            ->modalHeading('Botda post')
            ->modalDescription('Rasm yoki video va matn tanlanganlarga botda boradi. Post ostida «✅ Tanishdim» tugmasi bo‘lsa, kim tanishganini ko‘rasiz.')
            ->modalWidth(Width::ThreeExtraLarge)->modalSubmitActionLabel('Yuborish')
            ->schema([
                FileUpload::make('media')->label('Rasm yoki video (ixtiyoriy)')
                    ->disk(self::DISK)->directory('posts')->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime'])
                    ->maxSize(50 * 1024)->live()
                    ->helperText('JPG, PNG yoki WEBP rasm, MP4 yoki MOV video, 50 MB gacha (Telegram cheklovi).'),
                Textarea::make('caption')->label('Matn')->rows(6)
                    ->required(fn (Get $get) => blank($get('media')))
                    ->maxLength(fn (Get $get) => blank($get('media')) ? 4000 : 1000)
                    ->helperText(fn (Get $get) => blank($get('media'))
                        ? 'Faqat matn: 4000 belgigacha. Qanday yozsangiz, shunday boradi.'
                        : 'Rasm yoki video ostidagi matn: 1000 belgigacha (Telegram cheklovi). Qanday yozsangiz, shunday boradi.')
                    ->validationMessages(['required' => 'Matn yozing yoki rasm/video qo‘shing.']),
                ...self::audience(),
                Toggle::make('ack_button')->label('«✅ Tanishdim» tugmasi')->default(true)
                    ->helperText('Telegram shaxsiy chatda kim ko‘rganini botga bermaydi: tugmani bosganlar aniq sanaladi.'),
            ])
            ->action(function (array $data) {
                Notification::make()->success()->title('Post yuborilmoqda: '.self::create($data)->total.' ta oluvchi')
                    ->body('Natija «Postlar» sahifasida va bosh sahifada ko‘rinadi.')->send();
            });
    }

    /** Saves the post and one pending delivery per person ticked (still reachable): the bot picks it up. */
    public static function create(array $data): Post
    {
        $path = $data['media'] ?? null;
        $path = is_array($path) ? (array_values($path)[0] ?? null) : $path;
        $mime = $path ? (string) Storage::disk(self::DISK)->mimeType($path) : '';
        $type = $path ? (str_starts_with($mime, 'video/') ? 'video' : 'photo') : null;
        $probe = $type === 'video' ? MediaProbe::video(Storage::disk(self::DISK)->path($path)) : [];
        // only people still reachable, whatever the form sent
        $ids = PostAudience::recipients(array_keys(PostAudience::groups()))
            ->whereIn('user_id', array_map('intval', $data['people'] ?? []))->pluck('user_id');

        $post = DB::connection('bot')->transaction(function () use ($data, $path, $type, $probe, $ids) {
            $post = Post::create([
                'caption' => filled($data['caption'] ?? null) ? trim($data['caption']) : null,
                'media_type' => $type, 'media_path' => $path,
                'media_width' => $probe['width'] ?? null, 'media_height' => $probe['height'] ?? null, 'media_duration' => $probe['duration'] ?? null,
                'audience' => ['groups' => array_values($data['groups'] ?? []), 'districts' => array_map('intval', $data['districts'] ?? [])],
                'ack_button' => (bool) ($data['ack_button'] ?? true), 'status' => Post::SENDING, 'total' => $ids->count(),
                'created_by' => auth()->id(), 'created_at' => now(),
            ]);
            foreach ($ids->chunk(500) as $chunk) {
                PostDelivery::query()->insert($chunk->map(fn ($id) => ['post_id' => $post->id, 'user_id' => $id, 'status' => PostDelivery::PENDING])->values()->all());
            }

            return $post;
        });
        AuditLog::record('post.created', ['post' => $post->id, 'recipients' => $post->total, 'media' => $type]);

        return $post;
    }
}
