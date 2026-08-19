@props(['label', 'for' => null, 'error' => null, 'hint' => null])

<div {{ $attributes }}>
    <label @if ($for) for="{{ $for }}" @endif class="mb-1 block text-sm font-medium text-slate-700">
        {{ $label }}
    </label>

    {{ $slot }}

    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif

    @error($error)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
