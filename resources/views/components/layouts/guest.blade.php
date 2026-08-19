@props(['title' => null, 'width' => 'max-w-sm', 'split' => false])

{{--
    Cascarón de las pantallas sin sesión.

    Con `split` trae el panel de marca al lado del formulario: es la primera
    pantalla que ve un cliente nuevo, y ahí sí conviene decirle qué hace el
    sistema. El registro público no lo usa —su formulario es ancho y el panel lo
    apretaría—, así que la variante es opcional y el layout sigue sirviendo a las
    dos pantallas.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-900 antialiased">
@if ($split)
    <div class="flex min-h-full flex-col lg:flex-row">
        {{-- Panel de marca. Se oculta en móvil: en 375px de ancho, el
             formulario es lo único que importa. --}}
        <aside class="relative hidden overflow-hidden bg-slate-900 text-white lg:flex lg:w-[46%] lg:flex-col lg:justify-between lg:p-12">
            {{--
                Curvas de nivel, como las de un mapa topográfico. Cerquín es la
                sierra donde Lempira resistió, así que el fondo dice de dónde
                viene el nombre sin necesidad de explicarlo.

                Va en SVG dentro del propio HTML: no es un archivo que subir, no
                pesa, no se pixela en una pantalla grande y no hay que ir a
                buscarle licencia a ninguna foto.
            --}}
            <svg class="pointer-events-none absolute inset-0 h-full w-full" viewBox="0 0 400 600"
                 preserveAspectRatio="xMidYMid slice" fill="none" aria-hidden="true">
                {{-- De arriba abajo: apenas insinuadas detrás del titular, más
                     marcadas al pie, como se ve un cerro de lejos. --}}
                <g stroke="currentColor" stroke-width="1.1" class="text-white" fill="none">
                    @for ($i = 0; $i < 13; $i++)
                        @php $offset = -170 + $i * 36; @endphp
                        <path d="M -40 {{ 300 + $offset }}
                                 C 40 {{ 286 + $offset }}, 78 {{ 214 + $offset }}, 146 {{ 208 + $offset }}
                                 S 250 {{ 268 + $offset }}, 300 {{ 236 + $offset }}
                                 S 396 {{ 190 + $offset }}, 440 {{ 214 + $offset }}"
                              opacity="{{ round(0.05 + $i * 0.016, 3) }}"/>
                    @endfor
                </g>
            </svg>

            <div class="relative">
                <div class="flex items-center gap-3">
                    <x-app-logo variant="light" class="h-10 w-10"/>
                    <span class="text-lg font-semibold">{{ config('app.name') }}</span>
                </div>

                <h2 class="mt-12 text-3xl leading-tight font-semibold tracking-tight">
                    Facturá tranquilo.<br>La contabilidad va sola.
                </h2>
                <p class="mt-4 max-w-md text-sm leading-relaxed text-slate-300">
                    Cada factura que emitís deja su asiento cuadrado, con el CAI, el ISV
                    y el correlativo como los pide el SAR. Vos vendés; los libros se
                    llevan solos.
                </p>

                {{-- En segunda persona y en concreto: lo que hace la persona con
                     el sistema, no lo que el sistema «ofrece». --}}
                <ul class="mt-10 space-y-3.5 text-sm text-slate-200">
                    @foreach ([
                        'Facturás con tu CAI, y te avisa antes de que se te acaben los números',
                        'Cobrás en el mostrador sin soltar el teclado',
                        'Sabés qué hay en cada bodega y cuánto te costó',
                        'Cuadrás el banco, cerrás la caja y depreciás tus activos',
                        'Llevás varias empresas sin que se te mezclen los datos',
                    ] as $feature)
                        <li class="flex items-start gap-3">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-7.5 7.5a1 1 0 01-1.4 0L3.3 9.7a1 1 0 011.4-1.4l3.3 3.3 6.8-6.8a1 1 0 011.4 0z" clip-rule="evenodd"/>
                            </svg>
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>
            </div>

            <p class="relative max-w-md text-xs leading-relaxed text-slate-400">
                Una factura emitida no se borra. Se anula, y queda escrito quién lo hizo
                y por qué. Cuando llegue una auditoría, eso es lo que le van a pedir.
            </p>
        </aside>

        {{-- Formulario --}}
        <main class="flex flex-1 flex-col justify-center px-4 py-12 sm:px-8">
            <div class="mx-auto w-full {{ $width }}">
                {{-- Marca compacta, solo donde el panel no se ve --}}
                <div class="mb-8 text-center lg:hidden">
                    <x-app-logo class="mx-auto mb-3 h-12 w-12"/>
                    <h1 class="text-xl font-semibold tracking-tight">{{ config('app.name') }}</h1>
                    <p class="mt-1 text-sm text-slate-500">Contabilidad y facturación SAR para Honduras</p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    {{ $slot }}
                </div>
            </div>
        </main>
    </div>
@else
    <div class="flex min-h-full flex-col justify-center px-4 py-12">
        <div class="mx-auto w-full {{ $width }}">
            <div class="mb-8 text-center">
                <x-app-logo class="mx-auto mb-3 h-12 w-12"/>
                <h1 class="text-xl font-semibold tracking-tight">{{ config('app.name') }}</h1>
                <p class="mt-1 text-sm text-slate-500">Contabilidad y facturación SAR para Honduras</p>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                {{ $slot }}
            </div>
        </div>
    </div>
@endif
</body>
</html>
