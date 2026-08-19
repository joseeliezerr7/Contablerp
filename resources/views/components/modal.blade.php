@props(['title' => '', 'onClose' => 'cancel'])

<div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8"
     wire:keydown.escape="{{ $onClose }}">
    <div class="w-full max-w-2xl rounded-xl bg-white shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
            <h2 class="text-sm font-semibold">{{ $title }}</h2>
            <button type="button" wire:click="{{ $onClose }}"
                    class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/>
                </svg>
            </button>
        </div>

        {{ $slot }}
    </div>
</div>
