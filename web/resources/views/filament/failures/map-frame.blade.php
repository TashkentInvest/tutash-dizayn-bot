@php($f = $getRecord())
<div class="space-y-2 text-sm">
    <div class="flex flex-wrap gap-x-6 gap-y-1">
        <span><span style="color:#dc2626">●</span> Xodim {{ $f->reason === 'point_far' ? 'belgilagan nuqta' : 'turgan joy' }}
            @if ($f->accuracy) (±{{ (int) round($f->accuracy) }} m) @endif</span>
        @if ($f->target_lat !== null)
            <span><span style="color:#2563eb">●</span> {{ $f->reason === 'point_far' ? 'Xodim turgan joy' : 'Obyekt joyi (bazada)' }}</span>
        @endif
        @if ($f->street)
            <span><span style="color:#f59e0b">━</span> {{ $f->street->name }}</span>
        @endif
    </div>
    <iframe src="{{ route('location-failure-map', $f) }}" style="width:100%;height:65vh;border:0;border-radius:12px" ></iframe>
</div>
