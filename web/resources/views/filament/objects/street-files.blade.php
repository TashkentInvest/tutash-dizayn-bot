{{-- The street's concept PDF(s), whole: the first one inline, every one as a link. --}}
@php($files = \App\Models\StreetFile::query()->where('street_id', $getRecord()->street_id)->orderBy('sort_order')->orderBy('id')->get())
@forelse ($files as $f)
    <p style="margin:0 0 6px"><a href="{{ route('bot-file', $f) }}" target="_blank" style="color:#d97706">{{ $f->file_name }}</a>
        <span style="opacity:.65;font-size:13px">({{ max(1, round($f->size / 1048576)) }} MB)</span></p>
@empty
    <p style="opacity:.65;">Bu ko‘chaga PDF yuklanmagan.</p>
@endforelse
@if ($files->isNotEmpty())
    <iframe src="{{ route('bot-file', $files->first()) }}" title="PDF" loading="lazy"
            style="width:100%;height:560px;border:1px solid rgba(127,127,127,.3);border-radius:8px;margin-top:6px"></iframe>
@endif
