@props(['label', 'name', 'active' => false])

@php $panelId = 'nav-section-'.$name; @endphp

{{--
    Sección plegable del menú. El encabezado es un botón, no un rótulo: al
    hacer clic despliega o repliega sus opciones.

    El `aria-label` repite el texto visible a propósito: el nombre accesible
    debería salir del contenido, pero el botón lleva dentro un `span` y un
    icono, y no todos los lectores lo componen igual. Declararlo cuesta nada y
    no deja lugar a dudas. `aria-expanded` dice si está abierto y
    `aria-controls` señala qué panel gobierna.
--}}
<div x-data="navSection('{{ $name }}', {{ $active ? 'true' : 'false' }})">
    <button type="button" @click="toggle()"
            aria-label="{{ $label }}"
            :aria-expanded="open ? 'true' : 'false'"
            aria-controls="{{ $panelId }}"
            class="flex w-full items-center justify-between rounded-md px-3 py-2 text-xs font-semibold tracking-wider uppercase transition
                   {{ $active ? 'text-white' : 'text-slate-400' }} hover:bg-slate-800 hover:text-white">
        <span>{{ $label }}</span>
        <svg class="h-4 w-4 shrink-0 transition-transform duration-200" :class="open && 'rotate-180'"
             viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
        </svg>
    </button>

    {{-- `x-cloak` solo en las secciones que no son la actual: evita ver todo
         desplegado durante el instante previo a que Alpine arranque, sin
         provocar un parpadeo justo en la sección que el usuario está mirando. --}}
    <div id="{{ $panelId }}" x-show="open" x-collapse @unless ($active) x-cloak @endunless
         class="mt-1 space-y-1">
        {{ $slot }}
    </div>
</div>
