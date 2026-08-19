@props(['active' => false])

<a {{ $attributes->merge([
    'class' => 'block rounded-md px-3 py-2 transition '.($active
        ? 'bg-slate-800 font-medium text-white'
        : 'text-slate-300 hover:bg-slate-800 hover:text-white'),
]) }}>
    {{ $slot }}
</a>
