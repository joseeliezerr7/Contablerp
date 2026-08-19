<?php

declare(strict_types=1);

namespace App\Livewire\Identity;

use App\Domains\Identity\Data\AuditNarrator;
use App\Domains\Identity\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Quién hizo qué y cuándo.
 *
 * La bitácora se escribe desde la Fase 2 pero hasta ahora solo se podía leer con
 * un SELECT. Es la pantalla que le pide el contador externo cuando pregunta
 * «¿quién anuló esta factura?», y la que responde sola cuando falta efectivo en
 * la caja.
 *
 * ## El aislamiento aquí es manual
 *
 * `AuditLog` es el único modelo del sistema sin el scope global de empresa: un
 * auditor tiene que poder ver eventos de una empresa ya eliminada, y el scope se
 * los esconderría. El precio es que **olvidar `forCompany()` en una consulta
 * filtra la bitácora de todos los clientes**, así que el filtro va en un solo
 * método —`baseQuery()`— y hay una prueba que lo vigila.
 */
#[Title('Bitácora')]
class AuditIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'modulo', except: '')]
    public string $moduleFilter = '';

    #[Url(as: 'evento', except: '')]
    public string $eventFilter = '';

    #[Url(as: 'usuario', except: '')]
    public string $userFilter = '';

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $to = '';

    /** Renglón abierto en el detalle. */
    public ?int $showingId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', AuditLog::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'moduleFilter', 'eventFilter', 'userFilter', 'from', 'to'], strict: true)) {
            $this->resetPage();
        }
    }

    public function show(int $id): void
    {
        $log = $this->baseQuery()->findOrFail($id);

        $this->authorize('view', $log);

        $this->showingId = $log->id;
    }

    public function close(): void
    {
        $this->showingId = null;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'moduleFilter', 'eventFilter', 'userFilter', 'from', 'to']);
        $this->resetPage();
    }

    public function render(AuditNarrator $narrator): View
    {
        $this->authorize('viewAny', AuditLog::class);

        return view('livewire.identity.audit-index', [
            'logs' => $this->logs(),
            'detail' => $this->detail(),
            'narrator' => $narrator,
            'modules' => $this->modulesInUse($narrator),
            'events' => $this->eventsInUse($narrator),
            'people' => $this->peopleInUse(),
        ]);
    }

    /**
     * Punto único de entrada a la tabla: nadie consulta `AuditLog` sin pasar por
     * aquí, porque aquí está el filtro de empresa.
     *
     * @return Builder<AuditLog>
     */
    private function baseQuery(): Builder
    {
        return AuditLog::query()->forCompany(app(CompanyContext::class)->idOrFail());
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    private function logs(): LengthAwarePaginator
    {
        return $this->baseQuery()
            ->with(['user:id,name', 'auditable'])
            ->when($this->moduleFilter !== '', fn ($q) => $q->where('module', $this->moduleFilter))
            ->when($this->eventFilter !== '', fn ($q) => $q->where('event', $this->eventFilter))
            ->when($this->userFilter !== '', fn ($q) => $q->where('user_id', (int) $this->userFilter))
            ->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when($this->to !== '', fn ($q) => $q->where('created_at', '<=', $this->to.' 23:59:59'))
            ->when($this->search !== '', fn ($q) => $q->where(
                fn ($inner) => $inner->where('reason', 'like', '%'.$this->search.'%')
                    ->orWhere('auditable_type', 'like', '%'.$this->search.'%')
                    ->orWhere('ip_address', 'like', '%'.$this->search.'%')
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(30);
    }

    private function detail(): ?AuditLog
    {
        if ($this->showingId === null) {
            return null;
        }

        return $this->baseQuery()->with(['user:id,name', 'auditable'])->find($this->showingId);
    }

    /**
     * Solo los módulos y eventos que esta empresa realmente tiene. Un selector
     * con las 22 opciones posibles obliga a adivinar; uno con las 4 que hay
     * responde la pregunta antes de filtrar.
     *
     * Ambas listas se ordenan por la etiqueta en español, no por el valor
     * guardado: ordenar por `event` deja «Quitó el acceso a» de primero porque
     * en la base dice `access_revoked`.
     *
     * @return array<string, string> módulo crudo => etiqueta
     */
    private function modulesInUse(AuditNarrator $narrator): array
    {
        return $this->baseQuery()
            ->whereNotNull('module')
            ->distinct()
            ->pluck('module')
            ->mapWithKeys(fn (string $module) => [$module => $narrator->module($module)])
            ->sort()
            ->all();
    }

    /**
     * @return array<string, string> evento crudo => etiqueta
     */
    private function eventsInUse(AuditNarrator $narrator): array
    {
        return $this->baseQuery()
            ->distinct()
            ->pluck('event')
            ->mapWithKeys(fn (string $event) => [$event => ucfirst($narrator->event($event))])
            ->sort()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function peopleInUse(): array
    {
        $ids = $this->baseQuery()->whereNotNull('user_id')->distinct()->pluck('user_id');

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
