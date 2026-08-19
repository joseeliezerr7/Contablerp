{{--
    Marca de Cerquín: tres curvas de nivel concéntricas que dibujan una «C».
    Es el cerro visto desde arriba —el mismo mapa topográfico del panel de
    marca— y a la vez la inicial del nombre. La curva del medio va en esmeralda,
    el color que ya usa el sistema para lo que está bien y cuadrado.

    `variant`: «dark» (insignia oscura, para fondos claros) o «light»
    (insignia blanca, para el sidebar y el panel de marca oscuros).
--}}
@props(['variant' => 'dark'])

@php
    $badge = $variant === 'light' ? '#ffffff' : '#0f172a';
    $outer = $variant === 'light' ? '#0f172a' : '#f8fafc';
    $inner = $variant === 'light' ? '#64748b' : '#94a3b8';
@endphp

<svg {{ $attributes->merge(['class' => 'h-10 w-10']) }} viewBox="0 0 48 48" fill="none"
     role="img" aria-label="{{ config('app.name') }}">
    <rect width="48" height="48" rx="12" fill="{{ $badge }}"/>
    <g stroke-linecap="round" fill="none">
        <path d="M 31.5 13.4 A 13 13 0 1 0 31.5 34.6" stroke="{{ $outer }}" stroke-width="3"/>
        <path d="M 28.9 17.1 A 8.5 8.5 0 1 0 28.9 30.9" stroke="#10b981" stroke-width="2.4"/>
        <path d="M 26.3 20.8 A 4 4 0 1 0 26.3 27.2" stroke="{{ $inner }}" stroke-width="1.8"/>
    </g>
</svg>
