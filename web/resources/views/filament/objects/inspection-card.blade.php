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
            {{-- a sideways photo is turned upright here: the file itself is turned, so it stays right everywhere --}}
            <div x-data="{ v: 0, busy: false, turn(dir) { if (this.busy) return; this.busy = true;
                    fetch('{{ route('bot-inspection-photo-rotate', [$i, '__D__']) }}'.replace('__D__', dir), { method: 'POST',
                        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } })
                    .then(r => { if (r.ok) this.v++; }).finally(() => this.busy = false); } }">
                <a :href="'{{ route('bot-inspection-media', [$i, 'photo']) }}?v=' + v" target="_blank"><img :src="'{{ route('bot-inspection-media', [$i, 'photo']) }}?v=' + v" src="{{ route('bot-inspection-media', [$i, 'photo']) }}" alt="" style="width:100%;border-radius:8px"></a>
                <div style="display:flex;gap:6px;margin-top:4px;font-size:13px">
                    <button type="button" @click="turn('left')" :disabled="busy" style="padding:2px 10px;border:1px solid rgba(127,127,127,.4);border-radius:6px">↺ Chapga</button>
                    <button type="button" @click="turn('right')" :disabled="busy" style="padding:2px 10px;border:1px solid rgba(127,127,127,.4);border-radius:6px">↻ O‘ngga</button>
                </div>
            </div>
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
