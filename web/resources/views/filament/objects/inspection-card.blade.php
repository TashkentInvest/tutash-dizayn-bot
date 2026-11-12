@php($tz = config('app.display_timezone'))
<div style="border:1px solid rgba(127,127,127,.3);border-radius:10px;padding:12px;margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
        <b>{{ \App\Models\Inspection::KINDS[$i->kind] ?? $i->kind }}</b>
        <span>{{ \App\Models\Inspection::reviews()[$i->review] ?? '' }}
            @if ($i->reviewed_by) · {{ $i->reviewed_by }} @endif</span>
    </div>
    <div style="font-size:13px;opacity:.65;margin:4px 0 8px">
        {{ $i->staff?->full_name }} · {{ $i->submitted_at?->timezone($tz)->format('d.m.Y H:i') }}
        @if ($i->objectMapUrl()) · <a href="{{ $i->objectMapUrl() }}" target="_blank" style="color:#d97706">obyekt joyi (xodim belgilagan)</a>@endif
        @if ($i->mapUrl()) · <a href="{{ $i->mapUrl() }}" target="_blank" style="color:#d97706">xodim turgan joy</a>@endif
        @if ($i->accuracy) · aniqlik ±{{ (int) $i->accuracy }} m @endif
        @if ($i->distance_m !== null) · xodim obyektdan {{ (int) $i->distance_m }} m @endif
    </div>
    <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px">
        @if ($i->photo)
            <a href="{{ route('bot-inspection-media', [$i, 'photo']) }}" target="_blank"><img src="{{ route('bot-inspection-media', [$i, 'photo']) }}" alt="" style="width:100%;border-radius:8px"></a>
        @endif
        @if ($i->video)
            <video src="{{ route('bot-inspection-media', [$i, 'video']) }}" controls preload="metadata" style="width:100%;border-radius:8px;background:#000"></video>
        @endif
    </div>
    @if ($i->comment)
        <p style="margin:10px 0 0;font-weight:600;white-space:pre-wrap">{{ $i->comment }}</p>
    @endif
    @if ($i->review_note)
        <p style="margin:6px 0 0;font-size:13px">Admin izohi: {{ $i->review_note }}</p>
    @endif
</div>
