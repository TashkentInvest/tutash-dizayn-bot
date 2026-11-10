<div>
    <p style="font-weight:600;margin:0 0 8px">Botda shunday ko‘rinadi</p>
    @forelse ($images as $title => $src)
        <img src="{{ $src }}" alt="{{ $title }}" style="width:100%;border-radius:8px;border:1px solid rgba(127,127,127,.3);margin-bottom:12px;background:#fff">
    @empty
        <p style="opacity:.65">Rasm tanlanmagan.</p>
    @endforelse
</div>
