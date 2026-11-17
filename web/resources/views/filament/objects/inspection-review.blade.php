{{-- In the review window: the object's concept page next to the evidence, so the admin can compare. --}}
@php($i = $getRecord())
<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:14px;align-items:start">
    <div>
        <p style="font-size:13px;opacity:.65;margin:0 0 6px">{{ $i->object?->shop_name }}
            @if ($i->object?->owner_name) · {{ $i->object->owner_name }} @endif
            @if ($i->object?->stir) · STIR {{ $i->object->stir }} @endif</p>
        <p style="font-size:13px;opacity:.65;margin:0 0 6px">Ko‘chaning konsepsiyasi (kerakli ko‘rinish):</p>
        @foreach (\App\Models\StreetFile::query()->where('street_id', $i->object?->street_id)->orderBy('sort_order')->get() as $f)
            <p style="margin:0 0 4px"><a href="{{ route('bot-file', $f) }}" target="_blank" style="color:#d97706">{{ $f->file_name }}</a></p>
        @endforeach
    </div>
    <div>@include('filament.objects.inspection-card', ['i' => $i])
        {{-- everything sent for this object before (nothing is deleted: new evidence is added to the old) --}}
        @php($earlier = \App\Models\Inspection::query()->with('staff')->where('object_id', $i->object_id)->where('id', '!=', $i->id)
            ->where('step', 'submitted')->orderByDesc('submitted_at')->orderByDesc('id')->get())
        @if ($earlier->isNotEmpty())
            <details style="margin-top:6px">
                <summary style="cursor:pointer;font-weight:600">Shu obyekt bo‘yicha oldingi dalillar ({{ $earlier->count() }})</summary>
                <div style="margin-top:8px">@foreach ($earlier as $e) @include('filament.objects.inspection-card', ['i' => $e]) @endforeach</div>
            </details>
        @endif
    </div>
</div>
