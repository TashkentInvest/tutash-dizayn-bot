<x-filament-widgets::widget>
    <x-filament::section heading="Kutilayotgan ishlar">
        <div style="display:grid;gap:18px">
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span style="font-weight:600">Javobsiz murojaatlar</span>
                    <x-filament::link :href="$feedbackUrl" size="sm">Hammasi ({{ $feedbackCount }}) →</x-filament::link>
                </div>
                @forelse ($feedback as $f)
                    <a href="{{ $feedbackUrl }}" class="fi-pending-row">
                        <x-filament::badge :color="\App\Models\Feedback::LEVEL_COLORS[$f->level] ?? 'gray'" size="sm">{{ \App\Models\Feedback::LEVELS[$f->level] ?? $f->level }}</x-filament::badge>
                        <span class="fi-pending-text">{{ \Illuminate\Support\Str::limit($f->text, 70) }}</span>
                        <span class="fi-pending-meta">{{ $f->user?->full_name ?? '—' }} · {{ $f->created_at?->timezone($tz)->format('d.m H:i') }}</span>
                    </a>
                @empty
                    <p class="fi-pending-empty">Hammasiga javob berilgan ✓</p>
                @endforelse
            </div>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span style="font-weight:600">Tasdiq kutayotgan dalillar</span>
                    <x-filament::link :href="$inspectionUrl" size="sm">Hammasi ({{ $inspectionCount }}) →</x-filament::link>
                </div>
                @forelse ($inspections as $i)
                    <a href="{{ $inspectionUrl }}" class="fi-pending-row">
                        <x-filament::badge :color="$i->kind === 'installed' ? 'success' : 'warning'" size="sm">{{ \App\Support\Labels::get('status.'.$i->kind) }}</x-filament::badge>
                        <span class="fi-pending-text">{{ $i->object?->shop_name }} · {{ $i->object?->street?->name }}</span>
                        <span class="fi-pending-meta">{{ $i->staff?->full_name }} · {{ $i->submitted_at?->timezone($tz)->format('d.m H:i') }}</span>
                    </a>
                @empty
                    <p class="fi-pending-empty">Tasdiq kutayotgan dalil yo‘q ✓</p>
                @endforelse
            </div>
        </div>
    </x-filament::section>
    <style>
        .fi-pending-row { display: grid; grid-template-columns: auto 1fr; gap: 2px 10px; align-items: center; padding: 8px 10px; border-radius: 8px; }
        .fi-pending-row:hover { background: rgba(0, 0, 0, .04); }
        .dark .fi-pending-row:hover { background: rgba(255, 255, 255, .05); }
        .fi-pending-text { font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .fi-pending-meta { grid-column: 2; font-size: 12px; opacity: .6; }
        .fi-pending-empty { font-size: 14px; opacity: .6; padding: 4px 10px; }
    </style>
</x-filament-widgets::widget>
