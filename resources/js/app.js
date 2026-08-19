/**
 * Alpine lo inyecta Livewire; aquí solo se registran los componentes propios.
 */
document.addEventListener('alpine:init', () => {
    /**
     * Estado del armazón de la página: hoy, solo el cajón lateral en móvil.
     */
    window.Alpine.data('appShell', () => ({
        sidebar: false,

        open() {
            this.sidebar = true
        },

        close() {
            if (! this.sidebar) {
                return
            }

            this.sidebar = false

            // El foco vuelve al botón que abrió el cajón. Se hace a mano en vez
            // de dejárselo a `x-trap`: su restauración apunta al fondo, que en
            // ese instante todavía está marcado como inerte, y un foco dirigido
            // a un subárbol inerte se descarta sin avisar. `$nextTick` espera a
            // que Alpine haya quitado el atributo.
            this.$nextTick(() => this.$refs.menuButton?.focus())
        },
    }))

    /**
     * Sección plegable del menú lateral.
     *
     * La sección donde está el usuario se abre siempre: un acordeón que
     * esconde la página actual deja a la gente sin saber dónde está. Las demás
     * recuerdan si se dejaron abiertas, porque cada navegación vuelve a montar
     * el menú —`wire:navigate` reemplaza el DOM— y sin guardarlo el menú se
     * cerraría solo a cada clic.
     */
    window.Alpine.data('navSection', (name, active) => ({
        open: false,

        init() {
            this.open = active || localStorage.getItem(this.key()) === '1'
        },

        toggle() {
            this.open = !this.open
            localStorage.setItem(this.key(), this.open ? '1' : '0')
        },

        key() {
            return `nav-section:${name}`
        },
    }))
})
