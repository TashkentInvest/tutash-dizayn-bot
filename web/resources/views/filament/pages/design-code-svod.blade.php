<x-filament-panels::page>
    @php
        $cols = [
            'total' => 'Jami obyekt', 'pending' => 'Kutilmoqda', 'removed' => 'Yechildi (jarayonda)', 'installed' => 'Qo‘yildi (qilindi)',
            'review' => 'Tekshiruvda', 'day_removed' => 'Shu kuni yechildi', 'day_installed' => 'Shu kuni qo‘yildi',
        ];
        $color = ['removed' => '#b45309', 'installed' => '#15803d', 'review' => '#2563eb', 'day_removed' => '#b45309', 'day_installed' => '#15803d'];
        $cell = 'padding:8px 10px;border:1px solid #d1d5db;text-align:center';
        $head = $cell.';background:#e6f0dc;font-weight:600';
        $sum = $cell.';background:#dbe9f6;font-weight:700';
    @endphp

    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
        <label style="font-size:14px">Kun:
            <input type="date" wire:model.live="day" max="{{ now(config('app.display_timezone'))->toDateString() }}"
                   style="margin-left:6px;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px">
        </label>
        <span style="font-size:13px;color:#6b7280">“Shu kuni” ustunlari: tanlangan kunda yuborilib, tasdiqlangan dalillar.</span>
        @if ($waiting)
            <a href="{{ \App\Filament\Resources\Inspections\InspectionResource::getUrl() }}" style="margin-left:auto;color:#d97706;font-weight:600">⏳ {{ $waiting }} ta dalil tasdiq kutmoqda</a>
        @endif
    </div>

    @foreach (array_filter([['Tumanlar kesimida — '.$shownDay->format('d.m.Y'), $svod, true], $streets ? [$districtName.' — ko‘chalar kesimida', $streets, false] : null]) as [$title, $data, $clickable])
        <div style="overflow-x:auto">
            <h3 style="font-weight:600;margin:6px 0 8px">{{ $title }}</h3>
            <table style="border-collapse:collapse;width:100%;font-size:14px">
                <thead><tr>
                    <th style="{{ $head }}">№</th><th style="{{ $head }};text-align:left">{{ $clickable ? 'Tuman' : 'Ko‘cha' }}</th>
                    @foreach ($cols as $label)<th style="{{ $head }}">{{ $label }}</th>@endforeach
                    <th style="{{ $head }}">Bajarilish</th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td style="{{ $sum }}"></td><td style="{{ $sum }};text-align:left">{{ $data['total']['name'] }}</td>
                        @foreach ($cols as $k => $label)<td style="{{ $sum }};color:{{ $color[$k] ?? 'inherit' }}">{{ $data['total'][$k] }}</td>@endforeach
                        <td style="{{ $sum }}">{{ \App\Services\DesignCodeSvod::pct($data['total']['pct']) }}</td>
                    </tr>
                    @foreach ($data['rows'] as $n => $r)
                        <tr @if ($clickable) wire:click="pick({{ $r['id'] }})" style="cursor:pointer;{{ $district === $r['id'] ? 'background:#fef3c7' : '' }}" title="Ko‘chalar kesimini ko‘rish" @endif>
                            <td style="{{ $cell }}">{{ $n + 1 }}</td>
                            <td style="{{ $cell }};text-align:left">
                                @if ($clickable){{ $r['name'] }}
                                @else<a href="{{ \App\Filament\Resources\DesignObjects\DesignObjectResource::getUrl('index', ['filters' => ['street_id' => ['value' => $r['id']]]]) }}" style="color:#d97706">{{ $r['name'] }}</a>@endif
                            </td>
                            @foreach ($cols as $k => $label)<td style="{{ $cell }};color:{{ $color[$k] ?? 'inherit' }}">{{ $r[$k] }}</td>@endforeach
                            <td style="{{ $cell }}">{{ \App\Services\DesignCodeSvod::pct($r['pct']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($clickable)<p style="font-size:13px;color:#6b7280;margin-top:6px">Tuman qatorini bossangiz, uning ko‘chalari kesimi pastda chiqadi.</p>@endif
        </div>
    @endforeach
</x-filament-panels::page>
