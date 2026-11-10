{{-- In the review window: the object's concept page next to the evidence, so the admin can compare. --}}
@php($i = $getRecord())
<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr);gap:14px;align-items:start">
    <div>
        <p style="font-size:13px;color:#6b7280;margin:0 0 6px">Konsepsiya (kerakli ko‘rinish)</p>
        @if ($i->object)
            <a href="{{ route('bot-object-image', $i->object) }}" target="_blank"><img src="{{ route('bot-object-image', $i->object) }}" alt="" style="width:100%;border-radius:8px;border:1px solid #e5e7eb"></a>
        @endif
    </div>
    <div>@include('filament.objects.inspection-card', ['i' => $i])</div>
</div>
