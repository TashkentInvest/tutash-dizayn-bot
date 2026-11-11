<div x-data="{ copied: null, copy(t, k) { navigator.clipboard.writeText(t).then(() => { this.copied = k; setTimeout(() => this.copied = null, 1500); }); } }"
     style="display:grid;gap:14px;font-size:14px">
    <div>
        <div style="font-weight:600;margin-bottom:6px">Havola</div>
        <div style="display:flex;gap:8px;align-items:center">
            <code style="flex:1;padding:8px 10px;border:1px solid rgba(127,127,127,.35);border-radius:8px;overflow-wrap:anywhere">{{ $link }}</code>
            <x-filament::button size="sm" color="gray" x-on:click="copy(@js($link), 'link')">
                <span x-text="copied === 'link' ? 'Nusxalandi ✓' : 'Nusxalash'"></span>
            </x-filament::button>
        </div>
    </div>
    <div>
        <div style="font-weight:600;margin-bottom:6px">Xodimga yuboriladigan matn (SMS / Telegram / WhatsApp)</div>
        <div style="white-space:pre-wrap;padding:10px;border:1px solid rgba(127,127,127,.35);border-radius:8px">{{ $text }}</div>
        <div style="margin-top:8px">
            <x-filament::button size="sm" x-on:click="copy(@js($text), 'text')">
                <span x-text="copied === 'text' ? 'Nusxalandi ✓' : 'Matnni nusxalash'"></span>
            </x-filament::button>
        </div>
    </div>
    <p style="opacity:.7;margin:0">Havola {{ $expires }} gacha amal qiladi va bir marta ishlaydi. Xodim uni ochib “Start” tugmasini bossa,
        telefon raqamidan qat’i nazar shu yozuvga bog‘lanadi va xodim menyusi ochiladi.</p>
</div>
