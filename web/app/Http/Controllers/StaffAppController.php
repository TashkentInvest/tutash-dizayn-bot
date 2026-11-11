<?php

namespace App\Http\Controllers;

use App\Models\DesignObject;
use App\Models\Inspection;
use App\Models\Staff;
use App\Models\Street;
use App\Models\StreetFile;
use App\Services\Geocoder;
use App\Support\Latin;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;

/**
 * The district staff Mini App: streets of the staff member's district (the street's concept PDF, whole) -> businesses
 * the staff add themselves -> evidence wizard
 * (live photo -> where the staff member stands -> the object's place picked on a map -> live video -> comment). Every step is saved on the server, so a closed window resumes where
 * it stopped; repeating a step that is already done answers "already saved" instead of an error.
 */
class StaffAppController extends Controller
{
    private const MSG = [
        'not_yours' => ['Bu obyekt sizning tumaningizda emas.', 'Этот объект не в вашем районе.'],
        'in_review' => ['Bu obyekt bo‘yicha yuborilgan dalil tekshiruvda. Natijani kuting.', 'Доказательства по этому объекту на проверке. Дождитесь результата.'],
        'already_installed' => ['Bu obyektda dizayn-kod allaqachon qo‘yilgan.', 'На этом объекте дизайн-код уже установлен.'],
        'other_draft' => ['Sizda boshqa obyekt bo‘yicha tugallanmagan tekshiruv bor.', 'У вас есть незавершённая проверка по другому объекту.'],
        'wrong_step' => ['Avvalgi qadamni bajaring.', 'Сначала выполните предыдущий шаг.'],
        'accuracy' => ['Joylashuv aniq emas (±{n} m). Ochiq joyga chiqib, qayta urinib ko‘ring.', 'Местоположение неточное (±{n} м). Выйдите на открытое место и попробуйте снова.'],
        'too_far' => ['Siz obyektdan {n} m uzoqdasiz. Obyekt yoniga boring.', 'Вы в {n} м от объекта. Подойдите к объекту.'],
        'off_street' => ['Siz bu ko‘chadan {n} m uzoqdasiz. Obyekt joyida turib kiriting (ruxsat: {r} m).', 'Вы в {n} м от этой улицы. Вносите, стоя у объекта (допустимо: {r} м).'],
        'need_place' => ['Joylashuvingiz kerak: obyekt oldida turib qayta urinib ko‘ring.', 'Нужно ваше местоположение: попробуйте снова, стоя у объекта.'],
        'point_far' => ['Tanlangan nuqta siz turgan joydan {n} m uzoqda. Obyektni to‘g‘ri belgilang.', 'Выбранная точка в {n} м от вас. Отметьте объект точно.'],
        'photo_bad' => ['Rasm qabul qilinmadi. Qayta oling.', 'Фото не принято. Сделайте снова.'],
        'photo_dark' => ['Rasm juda qorong‘i. Qayta oling.', 'Фото слишком тёмное. Сделайте снова.'],
        'dup' => ['Bu fayl avval yuborilgan. Yangisini oling.', 'Этот файл уже отправлялся. Снимите новый.'],
        'video_short' => ['Video juda qisqa. 3–15 soniya yozing.', 'Видео слишком короткое. Запишите 3–15 секунд.'],
        'video_bad' => ['Video qabul qilinmadi. Qayta yozing.', 'Видео не принято. Запишите снова.'],
        'comment' => ['Izoh kamida {n} ta belgidan iborat bo‘lsin.', 'Комментарий должен быть не короче {n} символов.'],
        'saved' => ['Saqlandi.', 'Сохранено.'],
        'already' => ['Allaqachon saqlangan.', 'Уже сохранено.'],
        'sent' => ['Yuborildi. Admin tasdiqlagach, holat o‘zgaradi.', 'Отправлено. Статус изменится после подтверждения администратором.'],
    ];

    private function msg(Request $r, string $key, array $vars = []): string
    {
        $text = self::MSG[$key][$r->attributes->get('lang') === 'ru' ? 1 : 0];
        foreach ($vars as $k => $v) {
            $text = str_replace('{'.$k.'}', (string) $v, $text);
        }

        return $text;
    }

    private function fail(Request $r, string $key, int $status = 422, array $vars = [], array $extra = []): JsonResponse
    {
        return response()->json(['error' => $this->msg($r, $key, $vars)] + $extra, $status);
    }

    private function staff(Request $r): Staff
    {
        return $r->attributes->get('staff');
    }

    // ---------------------------------------------------------------- reading

    public function me(Request $r): JsonResponse
    {
        $staff = $this->staff($r);
        $streets = Street::query()->where('district_id', $staff->district_id)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'planned_objects']);
        $counts = DB::connection('bot')->table('objects')->where('is_active', true)
            ->whereIn('street_id', $streets->pluck('id'))
            ->selectRaw("street_id, COUNT(*) AS added, SUM(CASE WHEN status='removed' THEN 1 ELSE 0 END) AS removed, "
                ."SUM(CASE WHEN status='installed' THEN 1 ELSE 0 END) AS installed")
            ->groupBy('street_id')->get()->keyBy('street_id');
        $review = DB::connection('bot')->table('inspections')->join('objects', 'objects.id', '=', 'inspections.object_id')
            ->where('inspections.review', Inspection::PENDING)->whereIn('objects.street_id', $streets->pluck('id'))
            ->selectRaw('objects.street_id, COUNT(*) AS n')->groupBy('objects.street_id')->pluck('n', 'street_id');
        $priority = array_map('intval', $staff->priority_streets ?? []);

        $rows = $streets->map(fn ($s) => [
            'id' => $s->id, 'name' => $s->name, 'priority' => in_array($s->id, $priority, true),
            // total: from the official list (Excel); added: businesses the staff already entered
            'total' => (int) $s->planned_objects, 'added' => (int) ($counts[$s->id]->added ?? 0),
            'removed' => (int) ($counts[$s->id]->removed ?? 0),
            'installed' => (int) ($counts[$s->id]->installed ?? 0), 'review' => (int) ($review[$s->id] ?? 0),
        ])->filter(fn ($s) => $s['total'] > 0 || $s['added'] > 0)
            ->sortBy(fn ($s) => [$s['priority'] ? 0 : 1, $s['priority'] ? array_search($s['id'], $priority, true) : 0])->values();

        $draft = Inspection::query()->where('staff_id', $staff->id)->where('step', '!=', 'submitted')->latest('id')->first();

        return response()->json([
            'staff' => ['name' => $staff->full_name, 'district' => $staff->district?->name, 'streets_note' => $staff->streets_note],
            'lang' => $r->attributes->get('lang'),
            'totals' => [
                'total' => $rows->sum('total'), 'removed' => $rows->sum('removed'),
                'installed' => $rows->sum('installed'), 'review' => $rows->sum('review'),
            ],
            'streets' => $rows,
            'draft' => $draft ? ['id' => $draft->id, 'object_id' => $draft->object_id, 'step' => $draft->step] : null,
        ]);
    }

    public function objects(Request $r, Street $street): JsonResponse
    {
        if ((int) $street->district_id !== (int) $this->staff($r)->district_id) {
            return $this->fail($r, 'not_yours', 403);
        }
        $objects = DesignObject::query()->active()->where('street_id', $street->id)->with('pendingInspection')
            ->orderBy('shop_name')->orderBy('id')->get();
        // the street's concept PDF(s), whole: opened in the phone's browser through a signed link
        $files = StreetFile::query()->where('street_id', $street->id)->orderBy('sort_order')->orderBy('id')->get(['id', 'file_name', 'size']);

        return response()->json([
            'street' => ['id' => $street->id, 'name' => $street->name, 'total' => (int) $street->planned_objects],
            'files' => $files->map(fn (StreetFile $f) => [
                'name' => $f->file_name, 'mb' => max(1, (int) round($f->size / 1048576)),
                'url' => URL::temporarySignedRoute('tg.file', now()->addHours(12), ['file' => $f->id], absolute: false),
            ])->values(),
            'objects' => $objects->map(fn (DesignObject $o) => $this->brief($o))->values(),
        ]);
    }

    /**
     * "Find my place": the address where the staff member stands, the street of their district it is on (matched by name),
     * and the businesses already entered nearby, nearest first. The staff member picks one of them, or adds a new one here.
     */
    public function nearby(Request $r): JsonResponse
    {
        $data = $r->validate([
            'latitude' => ['required', 'numeric', 'between:37,46'],
            'longitude' => ['required', 'numeric', 'between:55,74.5'],
        ]);
        $lat = (float) $data['latitude'];
        $lon = (float) $data['longitude'];
        $staff = $this->staff($r);
        $geo = Geocoder::reverse($lat, $lon);
        $streets = Street::query()->where('district_id', $staff->district_id)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'start_lat', 'start_lon', 'end_lat', 'end_lon']);

        $objects = DesignObject::query()->active()->with(['pendingInspection', 'street:id,name'])
            ->whereIn('street_id', $streets->pluck('id'))
            ->where(fn ($q) => $q->whereNotNull('latitude')->orWhereNotNull('found_lat'))->get()
            ->map(function (DesignObject $o) use ($lat, $lon) {
                $o->setAttribute('distance', self::distance($o->latitude ?? $o->found_lat, $o->longitude ?? $o->found_lon, $lat, $lon));

                return $o;
            })
            ->filter(fn ($o) => $o->distance <= self::NEARBY_M)->sortBy('distance')->take(30);

        // the street: the nearest line of the official list within the radius, else the name the map gives this place,
        // else the street of the nearest business
        $radius = (int) config('telegram.street_radius_m');
        $near = $streets->map(fn ($s) => [$s, $s->distanceTo($lat, $lon)])->filter(fn ($x) => $x[1] !== null)->sortBy(fn ($x) => $x[1])->values();
        $street = ($near->first() && $near->first()[1] <= $radius ? $near->first()[0] : null)
            ?? self::matchStreet($streets, $geo['road']) ?? $objects->first()?->street;
        $order = $near->mapWithKeys(fn ($x, $i) => [$x[0]->id => $i])->all();
        $streets = $streets->sortBy(fn ($s) => $order[$s->id] ?? PHP_INT_MAX)->values();  // the closest streets first in the picker

        return response()->json([
            'address' => $geo['address'],
            'street' => $street ? ['id' => $street->id, 'name' => $street->name] : null,
            'streets' => $streets->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'objects' => $objects->map(fn (DesignObject $o) => $this->brief($o) + ['street' => $o->street?->name, 'distance' => (int) round($o->distance)])->values(),
            'radius' => self::NEARBY_M,
        ]);
    }

    /**
     * Is the staff member on this street? Within the radius of its line, or on a road the map names like it (a bent street
     * is not a straight line) and still near it. Returns null when yes, else the error to send.
     */
    private function offStreet(Request $r, Street $street, float $lat, float $lon): ?JsonResponse
    {
        $d = $street->distanceTo($lat, $lon);
        $radius = (int) config('telegram.street_radius_m');
        if ($d === null || $d <= $radius) {
            return null;  // no line in the list for this street: nothing to check against
        }
        if ($d <= 3000 && self::matchStreet(collect([$street]), Geocoder::reverse($lat, $lon)['road'])) {
            return null;
        }

        return $this->fail($r, 'off_street', 422, ['n' => (int) round($d), 'r' => $radius]);
    }

    private const NEARBY_M = 300;

    private static function matchStreet($streets, ?string $road)
    {
        $want = $road ? Latin::words($road) : [];
        if (! $want) {
            return null;
        }
        $best = null;
        $score = 0.0;
        foreach ($streets as $s) {
            $have = Latin::words($s->name);
            if (! $have) {
                continue;
            }
            $common = count(array_filter($have, fn ($w) => (bool) array_filter($want, fn ($x) => Latin::same($w, $x))));
            $sc = $common / max(count($have), count($want));
            if ($common && $sc > $score) {
                [$best, $score] = [$s, $sc];
            }
        }

        return $score >= 0.5 ? $best : null;
    }

    /** A business the staff member found on the street (shop / firm name required). */
    public function create(Request $r, Street $street): JsonResponse
    {
        if ((int) $street->district_id !== (int) $this->staff($r)->district_id) {
            return $this->fail($r, 'not_yours', 403);
        }
        $data = $this->validateDetails($r, true);
        $at = $r->validate([
            'latitude' => ['required', 'numeric', 'between:37,46'], 'longitude' => ['required', 'numeric', 'between:55,74.5'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ], ['latitude.required' => $this->msg($r, 'need_place'), 'longitude.required' => $this->msg($r, 'need_place')]);
        if ((float) ($at['accuracy'] ?? 0) > config('telegram.max_accuracy_m')) {
            return $this->fail($r, 'accuracy', 422, ['n' => (int) round((float) $at['accuracy'])]);
        }
        if ($resp = $this->offStreet($r, $street, (float) $at['latitude'], (float) $at['longitude'])) {
            return $resp;
        }

        return $this->locked(function () use ($r, $street, $data, $at) {
            // pressed twice / sent again: the same name on the same street is the same business
            $same = DesignObject::query()->active()->where('street_id', $street->id)
                ->whereRaw('LOWER(TRIM(shop_name)) = ?', [mb_strtolower(trim($data['shop_name']))])->first();
            if ($same) {
                return response()->json(['ok' => true, 'already' => true, 'object' => $this->brief($same)]);
            }
            $o = DesignObject::create(['street_id' => $street->id, 'created_by' => $this->staff($r)->id,
                'found_lat' => $at['latitude'] ?? null, 'found_lon' => $at['longitude'] ?? null] + $data);

            return response()->json(['ok' => true, 'object' => $this->brief($o->fresh())]);
        });
    }

    public function show(Request $r, DesignObject $object): JsonResponse
    {
        if ($resp = $this->guardObject($r, $object)) {
            return $resp;
        }
        $draft = Inspection::query()->where('object_id', $object->id)->where('staff_id', $this->staff($r)->id)
            ->where('step', '!=', 'submitted')->latest('id')->first();
        $history = $object->inspections()->whereNotNull('review')->latest('id')->limit(10)->get();

        return response()->json($this->brief($object) + [
            'street' => $object->street?->name,
            'owner_phone' => $object->owner_phone,
            'has_point' => $object->latitude !== null,
            'draft' => $draft ? $this->draftState($draft) : null,
            'history' => $history->map(fn (Inspection $i) => [
                'kind' => $i->kind, 'review' => $i->review, 'note' => $i->review_note,
                'at' => $i->submitted_at?->timezone(config('app.display_timezone'))->format('d.m.Y H:i'),
            ]),
        ]);
    }

    public function update(Request $r, DesignObject $object): JsonResponse
    {
        if ($resp = $this->guardObject($r, $object)) {
            return $resp;
        }
        $data = $this->validateDetails($r, false);
        $object->forceFill($data + ['updated_at' => now()->utc()->format('Y-m-d H:i:s')])->save();

        return response()->json(['ok' => true, 'message' => $this->msg($r, 'saved')]);
    }

    /** @return array<string, string|null> */
    private function validateDetails(Request $r, bool $creating): array
    {
        $ru = $r->attributes->get('lang') === 'ru';
        $data = $r->validate([
            'shop_name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:150'],
            'owner_name' => ['nullable', 'string', 'max:150'],
            'owner_phone' => ['nullable', 'string', 'max:30'],
            'stir' => ['nullable', 'regex:/^\d{9}$|^\d{14}$/'],
            'address' => ['nullable', 'string', 'max:200'],
        ], [
            'stir.regex' => $ru ? 'ИНН: 9 цифр (ПИНФЛ: 14).' : 'STIR: 9 ta raqam (JShShIR: 14).',
            'shop_name.required' => $ru ? 'Укажите название магазина / фирмы.' : 'Do‘kon yoki firma nomini kiriting.',
            'shop_name.min' => $ru ? 'Укажите название магазина / фирмы.' : 'Do‘kon yoki firma nomini kiriting.',
        ]);
        if (! empty($data['owner_phone'])) {
            $data['owner_phone'] = Phone::normalize($data['owner_phone']) ?? $data['owner_phone'];
        }

        return array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : (is_string($v) ? trim($v) : $v), $data);
    }

    // ---------------------------------------------------------------- the evidence wizard

    public function start(Request $r, DesignObject $object): JsonResponse
    {
        if ($resp = $this->guardObject($r, $object)) {
            return $resp;
        }
        $data = $r->validate(['kind' => ['required', 'in:removed,installed'], 'discard_other' => ['sometimes', 'boolean']]);
        $staff = $this->staff($r);

        return $this->locked(function () use ($r, $object, $staff, $data) {
            if ($object->inspections()->where('review', Inspection::PENDING)->exists()) {
                return $this->fail($r, 'in_review', 409);
            }
            if ($object->status === DesignObject::INSTALLED && $data['kind'] === DesignObject::REMOVED) {
                return $this->fail($r, 'already_installed', 409);
            }
            $drafts = Inspection::query()->where('staff_id', $staff->id)->where('step', '!=', 'submitted')->get();
            $same = $drafts->first(fn ($d) => (int) $d->object_id === (int) $object->id && $d->kind === $data['kind']);
            if ($same) {
                return response()->json(['ok' => true, 'already' => true, 'draft' => $this->draftState($same)]);
            }
            // a draft with a photo or video is work: never drop it silently, ask first
            $worth = $drafts->filter(fn ($d) => $d->photo || $d->video);
            if ($worth->isNotEmpty() && ! ($data['discard_other'] ?? false)) {
                $d = $worth->first();

                return $this->fail($r, 'other_draft', 409, [], ['other' => [
                    'object_id' => $d->object_id, 'label' => $d->object?->label(), 'street' => $d->object?->street?->name, 'step' => $d->step,
                ]]);
            }
            $drafts->each(fn ($d) => $this->discardDraft($d));
            $i = Inspection::create(['object_id' => $object->id, 'staff_id' => $staff->id, 'kind' => $data['kind'], 'step' => 'photo']);

            return response()->json(['ok' => true, 'draft' => $this->draftState($i->fresh())]);
        });
    }

    public function location(Request $r, Inspection $inspection): JsonResponse
    {
        $data = $r->validate([
            'latitude' => ['required', 'numeric', 'between:37,46'],
            'longitude' => ['required', 'numeric', 'between:55,74.5'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->step($r, $inspection, 'location', function (Inspection $i) use ($r, $data) {
            $acc = (float) ($data['accuracy'] ?? 0);
            if ($acc > config('telegram.max_accuracy_m')) {
                return $this->fail($r, 'accuracy', 422, ['n' => (int) round($acc)]);
            }
            $o = $i->object;
            // the staff member must be at the object: its approved place, else where it was added, else on its street
            $lat = $o->latitude ?? $o->found_lat;
            $lon = $o->longitude ?? $o->found_lon;
            if ($lat !== null) {
                $far = self::distance((float) $lat, (float) $lon, (float) $data['latitude'], (float) $data['longitude']);
                if ($far > config('telegram.max_distance_m')) {
                    return $this->fail($r, 'too_far', 422, ['n' => (int) round($far)]);
                }
            } elseif ($o->street && ($resp = $this->offStreet($r, $o->street, (float) $data['latitude'], (float) $data['longitude']))) {
                return $resp;
            }
            $i->forceFill(['latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'accuracy' => $acc ?: null,
                'step' => 'point'])->save();

            return null;
        });
    }

    /** The object's place, picked on the map: it must be near where the staff member stands. */
    public function point(Request $r, Inspection $inspection): JsonResponse
    {
        $data = $r->validate([
            'latitude' => ['required', 'numeric', 'between:37,46'],
            'longitude' => ['required', 'numeric', 'between:55,74.5'],
        ]);

        return $this->step($r, $inspection, 'point', function (Inspection $i) use ($r, $data) {
            $distance = self::distance((float) $i->latitude, (float) $i->longitude, (float) $data['latitude'], (float) $data['longitude']);
            if ($distance > config('telegram.max_distance_m')) {
                return $this->fail($r, 'point_far', 422, ['n' => (int) round($distance)]);
            }
            $i->forceFill(['obj_latitude' => $data['latitude'], 'obj_longitude' => $data['longitude'],
                'distance_m' => round($distance, 1), 'step' => 'video'])->save();

            return null;
        });
    }

    public function photo(Request $r, Inspection $inspection): JsonResponse
    {
        $r->validate(['photo' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.config('telegram.photo_max_kb')]],
            ['photo.*' => $this->msg($r, 'photo_bad')]);

        // the slow checks run before the write lock: one big upload must not hold up every other staff member
        $file = $r->file('photo');
        $dark = self::isDark($file);
        $hash = hash_file('sha256', $file->getRealPath());

        return $this->step($r, $inspection, 'photo', function (Inspection $i) use ($r, $file, $dark, $hash) {
            if ($dark) {
                return $this->fail($r, 'photo_dark');
            }
            if (Inspection::query()->where('photo_hash', $hash)->where('id', '!=', $i->id)->exists()) {
                return $this->fail($r, 'dup');
            }
            $i->forceFill(['photo' => $this->store($i, $file, 'photo'), 'photo_hash' => $hash, 'step' => 'location'])->save();

            return null;
        });
    }

    public function video(Request $r, Inspection $inspection): JsonResponse
    {
        $r->validate(['video' => ['required', 'file', 'mimetypes:video/webm,video/mp4,video/quicktime,video/x-matroska,application/octet-stream',
            'max:'.config('telegram.video_max_kb')]], ['video.*' => $this->msg($r, 'video_bad')]);

        $file = $r->file('video');
        $hash = hash_file('sha256', $file->getRealPath());  // before the write lock (a video is up to 60 MB)

        return $this->step($r, $inspection, 'video', function (Inspection $i) use ($r, $file, $hash) {
            if ($file->getSize() < config('telegram.video_min_bytes')) {
                return $this->fail($r, 'video_short');
            }
            if (Inspection::query()->where('video_hash', $hash)->where('id', '!=', $i->id)->exists()) {
                return $this->fail($r, 'dup');
            }
            $i->forceFill(['video' => $this->store($i, $file, 'video'), 'video_hash' => $hash, 'step' => 'comment'])->save();

            return null;
        });
    }

    public function submit(Request $r, Inspection $inspection): JsonResponse
    {
        $min = (int) config('telegram.comment_min');
        $comment = trim((string) $r->input('comment', ''));

        return $this->step($r, $inspection, 'comment', function (Inspection $i) use ($r, $comment, $min) {
            if (mb_strlen($comment) < $min) {
                return $this->fail($r, 'comment', 422, ['n' => $min]); // the photo and video stay: only the comment is asked again
            }
            $i->forceFill(['comment' => mb_substr($comment, 0, 2000), 'step' => 'submitted', 'review' => Inspection::PENDING,
                'submitted_at' => now()->utc()->format('Y-m-d H:i:s')])->save();

            return null;
        }, 'sent');
    }

    public function discard(Request $r, Inspection $inspection): JsonResponse
    {
        if ((int) $inspection->staff_id !== (int) $this->staff($r)->id) {
            return $this->fail($r, 'not_yours', 403);
        }

        return $this->locked(function () use ($inspection) {
            $i = Inspection::query()->find($inspection->id);
            if ($i && $i->isDraft()) {
                $this->discardDraft($i);
            }

            return response()->json(['ok' => true]);
        });
    }

    /** A street's concept PDF, whole; opened through a signed link from the Mini App (the browser cannot send initData). */
    public function file(StreetFile $file)
    {
        $root = realpath((string) config('filesystems.disks.bot_data.root'));
        $path = $root ? realpath($root.DIRECTORY_SEPARATOR.$file->path) : false;
        abort_unless($path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path), 404);
        $pdf = strtolower(pathinfo($file->file_name, PATHINFO_EXTENSION)) === 'pdf';

        return response()->file($path, [
            'Content-Type' => $pdf ? 'application/pdf' : 'application/octet-stream',
            'Content-Disposition' => ($pdf ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($file->file_name),
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Runs one wizard step under a write lock. A step already done answers OK ("already saved") so a lost response
     * that the page sends again moves on instead of failing; a step not reached yet is refused.
     */
    private function step(Request $r, Inspection $inspection, string $step, callable $do, string $okKey = 'saved'): JsonResponse
    {
        if ((int) $inspection->staff_id !== (int) $this->staff($r)->id) {
            return $this->fail($r, 'not_yours', 403);
        }

        return $this->locked(function () use ($r, $inspection, $step, $do, $okKey) {
            $i = Inspection::query()->findOrFail($inspection->id);
            $at = array_search($i->step, Inspection::STEPS, true);
            $want = array_search($step, Inspection::STEPS, true);
            if ($at > $want) {
                return response()->json(['ok' => true, 'already' => true, 'message' => $this->msg($r, 'already'), 'draft' => $this->draftState($i)]);
            }
            if ($at < $want) {
                return $this->fail($r, 'wrong_step', 409, [], ['draft' => $this->draftState($i)]);
            }
            if ($resp = $do($i)) {
                return $resp;
            }

            return response()->json(['ok' => true, 'message' => $this->msg($r, $okKey), 'draft' => $this->draftState($i->fresh())]);
        });
    }

    /** SQLite has no row locks: a first write takes the database write lock, so two presses run one after the other. */
    private function locked(callable $fn): JsonResponse
    {
        return DB::connection('bot')->transaction(function () use ($fn) {
            DB::connection('bot')->statement("INSERT INTO kv (key, value) VALUES ('__lock', 0) ON CONFLICT(key) DO UPDATE SET value = kv.value + 1");

            return $fn();
        }, 3);
    }

    private function guardObject(Request $r, DesignObject $object): ?JsonResponse
    {
        $districtId = Street::query()->whereKey($object->street_id)->value('district_id');

        return (! $object->is_active || (int) $districtId !== (int) $this->staff($r)->district_id) ? $this->fail($r, 'not_yours', 403) : null;
    }

    private function brief(DesignObject $o): array
    {
        return [
            'id' => $o->id, 'shop_name' => $o->shop_name, 'owner_name' => $o->owner_name, 'stir' => $o->stir, 'status' => $o->status,
            'address' => $o->address,
            'in_review' => $o->relationLoaded('pendingInspection') ? (bool) $o->pendingInspection : $o->inspections()->where('review', Inspection::PENDING)->exists(),
        ];
    }

    private function draftState(Inspection $i): array
    {
        return ['id' => $i->id, 'object_id' => $i->object_id, 'kind' => $i->kind, 'step' => $i->step,
            'has_photo' => (bool) $i->photo, 'has_video' => (bool) $i->video,
            // the map step starts at the object's known place, else where the staff member stands
            'me' => $i->latitude !== null ? [$i->latitude, $i->longitude] : null,
            'object_point' => $i->object?->latitude !== null ? [$i->object->latitude, $i->object->longitude] : null,
            'max_distance' => (int) config('telegram.max_distance_m')];
    }

    private function store(Inspection $i, UploadedFile $file, string $name): string
    {
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $rel = "inspections/{$i->id}/{$name}_".substr(bin2hex(random_bytes(4)), 0, 8).".$ext";
        $dir = rtrim((string) config('bot.uploads_path'), '/\\').'/inspections/'.$i->id;
        File::ensureDirectoryExists($dir, 02770);
        $file->move($dir, basename($rel));

        return $rel;
    }

    private function discardDraft(Inspection $i): void
    {
        File::deleteDirectory(rtrim((string) config('bot.uploads_path'), '/\\').'/inspections/'.$i->id);
        $i->delete();
    }

    /** A black or nearly black frame: the camera was covered or did not start. Needs GD; without it nothing is refused. */
    private static function isDark(UploadedFile $file): bool
    {
        if (! function_exists('imagecreatefromstring')) {
            return false;
        }
        $img = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $img) {
            return false;
        }
        $small = imagescale($img, 16, 16);
        $sum = 0;
        for ($x = 0; $x < 16; $x++) {
            for ($y = 0; $y < 16; $y++) {
                $c = imagecolorat($small, $x, $y);
                $sum += (($c >> 16) & 255) * 0.299 + (($c >> 8) & 255) * 0.587 + ($c & 255) * 0.114;
            }
        }

        return $sum / 256 < 12;
    }

    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
