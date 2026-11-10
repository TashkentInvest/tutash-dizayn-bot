{{-- Evidence staff sent for this object, newest first: photo, video, place, comment and the review result. --}}
@php($items = $getRecord()->inspections()->with('staff')->whereNotNull('review')->latest('id')->get())
@forelse ($items as $i)
    @include('filament.objects.inspection-card', ['i' => $i])
@empty
    <p style="opacity:.65;">Hali dalil yuborilmagan.</p>
@endforelse
