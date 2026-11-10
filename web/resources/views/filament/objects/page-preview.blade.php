{{-- The object's page: the picture, and the page itself inside the street PDF (browsers open a PDF at #page=N). --}}
@php($o = $getRecord())
<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px">
    <a href="{{ route('bot-object-image', $o) }}" target="_blank">
        <img src="{{ route('bot-object-image', $o) }}" alt="" style="width:100%;border-radius:8px;border:1px solid #e5e7eb">
    </a>
    @if ($o->file)
        <iframe src="{{ route('bot-file', $o->file_id) }}#page={{ $o->page }}" title="PDF" loading="lazy"
                style="width:100%;height:560px;border:1px solid #e5e7eb;border-radius:8px"></iframe>
    @endif
</div>
<p style="margin-top:8px;font-size:13px;color:#6b7280">
    {{ $o->file?->file_name }}, {{ $o->page }}-sahifa ·
    <a href="{{ route('bot-file', $o->file_id) }}#page={{ $o->page }}" target="_blank" style="color:#d97706">PDF’ni alohida oynada ochish</a>
</p>
