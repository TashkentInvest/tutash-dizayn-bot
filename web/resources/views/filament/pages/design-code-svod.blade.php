<x-filament-panels::page>
    @php
        // Yechildi / Qo'yildi = all time; "Bugun" = the report day (yesterday 18:00 - today 18:00); Jarayonda = waiting for review
        $dayWord = $isToday ? 'Bugun' : $shownDay->format('d.m');
        $L = \App\Support\Labels::class;  // the words the bot and the Mini App use too (shared/labels.json)
        $cols = [
            // Jarayonda = the old sign is off, the design code is not on yet (objects "Yechildi"); Qo'yildi leaves it
            'total' => $L::get('svod.total'), 'pending' => $L::get('svod.left'), 'removed' => $L::get('status.review'),
            'installed' => $L::get('status.installed'), 'day_removed' => $dayWord.' '.mb_strtolower($L::get('status.removed')),
            'day_installed' => $dayWord.' '.mb_strtolower($L::get('status.installed')),
            'day_sent' => $dayWord.' yuborilgan dalil', 'day_added' => $dayWord.' qo‘shilgan obyekt', 'review' => $L::get('svod.awaiting'),
        ];
        // colour = meaning: amber in progress, green done, blue waiting for review
        $tone = ['removed' => 'amber', 'installed' => 'green', 'review' => 'blue', 'day_removed' => 'amber', 'day_installed' => 'green', 'day_sent' => 'blue', 'day_added' => 'blue'];
    @endphp

    {{-- one stylesheet for both themes: Filament puts .dark on <html> in dark mode --}}
    <style>
        .svod { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 14px; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
        .svod th, .svod td { padding: 9px 12px; text-align: center; border-bottom: 1px solid #e5e7eb; white-space: nowrap; }
        .svod th + th, .svod td + td { border-left: 1px solid #f1f5f9; }
        .svod thead th { background: #f0fdf4; color: #14532d; font-weight: 600; font-size: 13px; line-height: 1.25; white-space: normal; }
        .svod .name { text-align: left; }
        .svod .sum td { background: #eff6ff; color: #0f172a; font-weight: 700; }
        .svod tbody tr:last-child td { border-bottom: 0; }
        .svod tr.pick { cursor: pointer; }
        .svod tr.pick:hover td { background: #f8fafc; }
        .svod tr.sel td { background: #fef3c7; }
        .svod .zero { color: #9ca3af; }
        .svod .amber { color: #b45309; } .svod .green { color: #15803d; } .svod .blue { color: #1d4ed8; }
        .svod .pct { font-weight: 600; }
        .svod a { color: #b45309; }
        .svod-date { margin-left: 6px; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 8px; background: transparent; color: inherit; color-scheme: light; }
        .svod-hint { font-size: 13px; color: #6b7280; }

        .dark .svod { border-color: rgba(255, 255, 255, .1); }
        .dark .svod th, .dark .svod td { border-bottom-color: rgba(255, 255, 255, .08); }
        .dark .svod th + th, .dark .svod td + td { border-left-color: rgba(255, 255, 255, .05); }
        .dark .svod thead th { background: rgba(34, 197, 94, .12); color: #bbf7d0; }
        .dark .svod .sum td { background: rgba(59, 130, 246, .16); color: #f1f5f9; }
        .dark .svod tr.pick:hover td { background: rgba(255, 255, 255, .04); }
        .dark .svod tr.sel td { background: rgba(245, 158, 11, .16); }
        .dark .svod .zero { color: #6b7280; }
        .dark .svod .amber { color: #fbbf24; } .dark .svod .green { color: #4ade80; } .dark .svod .blue { color: #60a5fa; }
        .dark .svod a { color: #fbbf24; }
        .dark .svod-date { border-color: rgba(255, 255, 255, .2); color-scheme: dark; }
        .dark .svod-hint { color: #9ca3af; }
    </style>

    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <label style="font-size:14px">Kun:
            <input type="date" class="svod-date" wire:model.live="day" max="{{ $maxDay }}">
        </label>
        <span class="svod-hint">“{{ $dayWord }}” ustunlari: {{ $shownDay->copy()->subDay()->format('d.m') }} soat 18:00 dan {{ $shownDay->format('d.m') }} soat 18:00 gacha yuborilib, tasdiqlangan dalillar (har kuni 18:00 da yangilanadi). Jarayonda — eski peshtaxtasi yechilgan, dizayn-kod hali qo‘yilmagan obyektlar; qo‘yilgach «Qo‘yildi»ga o‘tadi. Qolgan = Jami − Jarayonda − Qo‘yildi (hali boshlanmagan). Tasdiq kutmoqda — moderator ko‘rmagan dalillar.</span>
        @if ($waiting)
            <a href="{{ \App\Filament\Resources\Inspections\InspectionResource::getUrl() }}" style="margin-left:auto;color:#d97706;font-weight:600">⏳ {{ $waiting }} ta dalil tasdiq kutmoqda</a>
        @endif
    </div>

    @foreach (array_filter([['Tumanlar kesimida — '.$shownDay->format('d.m.Y'), $svod, true], $streets ? [$districtName.' — ko‘chalar kesimida', $streets, false] : null]) as [$title, $data, $clickable])
        <div style="overflow-x:auto">
            <h3 style="font-weight:600;margin:6px 0 10px">{{ $title }}</h3>
            <table class="svod">
                <thead><tr>
                    <th style="width:44px">№</th><th class="name">{{ $clickable ? 'Tuman' : 'Ko‘cha' }}</th>
                    @foreach ($cols as $label)<th>{{ $label }}</th>@endforeach
                    <th>Bajarilish</th>
                </tr></thead>
                <tbody>
                    <tr class="sum">
                        <td></td><td class="name">{{ $data['total']['name'] }}</td>
                        @foreach ($cols as $k => $label)<td class="{{ $tone[$k] ?? '' }}">{{ $data['total'][$k] }}</td>@endforeach
                        <td class="pct">{{ \App\Services\DesignCodeSvod::pct($data['total']['pct']) }}</td>
                    </tr>
                    @foreach ($data['rows'] as $n => $r)
                        <tr @if ($clickable) wire:click="pick({{ $r['id'] }})" class="pick {{ $district === $r['id'] ? 'sel' : '' }}" title="Ko‘chalar kesimini ko‘rish" @endif>
                            <td>{{ $n + 1 }}</td>
                            <td class="name">
                                @if ($clickable){{ $r['name'] }}
                                @else<a href="{{ \App\Filament\Resources\DesignObjects\DesignObjectResource::getUrl('index', ['filters' => ['street_id' => ['value' => $r['id']]]]) }}">{{ $r['name'] }}</a>@endif
                            </td>
                            {{-- a 0 is greyed out, so the numbers that matter stand out --}}
                            @foreach ($cols as $k => $label)<td class="{{ $r[$k] ? ($tone[$k] ?? '') : 'zero' }}">{{ $r[$k] }}</td>@endforeach
                            <td class="pct {{ $r['pct'] ? '' : 'zero' }}">{{ \App\Services\DesignCodeSvod::pct($r['pct']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($clickable)<p class="svod-hint" style="margin-top:6px">Tuman qatorini bossangiz, uning ko‘chalari kesimi pastda chiqadi.</p>@endif
        </div>
    @endforeach
</x-filament-panels::page>
