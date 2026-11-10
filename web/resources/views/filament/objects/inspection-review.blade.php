{{-- In the review window: the object's concept page next to the evidence, so the admin can compare. --}}
@php($i = $getRecord())
<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:14px;align-items:start">
    <div>
        <p style="font-size:13px;color:#6b7280;margin:0 0 6px">{{ $i->object?->shop_name }}
            @if ($i->object?->owner_name) · {{ $i->object->owner_name }} @endif
            @if ($i->object?->stir) · STIR {{ $i->object->stir }} @endif</p>
        <p style="font-size:13px;color:#6b7280;margin:0 0 6px">Ko‘chaning konsepsiyasi (kerakli ko‘rinish):</p>
        @foreach (\App\Models\StreetFile::query()->where('street_id', $i->object?->street_id)->orderBy('sort_order')->get() as $f)
            <p style="margin:0 0 4px"><a href="{{ route('bot-file', $f) }}" target="_blank" style="color:#d97706">{{ $f->file_name }}</a></p>
        @endforeach
    </div>
    <div>@include('filament.objects.inspection-card', ['i' => $i])</div>
</div>
