<?php

declare(strict_types=1);

namespace App\Livewire\Accounting;

use App\Domains\Accounting\Enums\AccountMappingKey;
use App\Domains\Accounting\Exceptions\InvalidAccountException;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountMapping;
use App\Domains\Accounting\Services\AccountMappingService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Dónde cae cada asiento.
 *
 * El alta de una empresa deja estas claves apuntando al catálogo hondureño, y
 * el 90 % de los clientes no las tocará nunca. Existe la pantalla para el que sí
 * necesita hacerlo —un plan de cuentas heredado, una cuenta de ventas partida
 * por línea de negocio— y, sobre todo, para **ver** a dónde va cada cosa sin
 * tener que leer el código del motor contable.
 *
 * ## Por qué esto no se toca a la ligera
 *
 * Reasignar una clave redirige todo lo que se contabilice de aquí en adelante.
 * No reescribe nada de lo ya contabilizado —los asientos guardan la cuenta
 * concreta, no la clave—, y eso es lo correcto: la historia no cambia porque
 * hoy se decida otra cosa. Pero significa que el mayor de la cuenta vieja se
 * queda con lo de antes y el de la nueva arranca desde hoy, y quien lo cambia
 * tiene que saberlo. Por eso la pantalla lo dice en vez de suponerlo.
 */
#[Title('Cuentas por módulo')]
class AccountMappingIndex extends Component
{
    /**
     * Cuenta elegida por clave, **indexada por el nombre del caso del enum**
     * (`SalesRevenue`) y no por su valor (`sales.revenue`).
     *
     * El valor lleva un punto, y Livewire interpreta `selected.sales.revenue`
     * como un array anidado: la selección se guardaría en
     * `selected['sales']['revenue']` y el componente leería `selected['sales.revenue']`,
     * que nunca cambia. El formulario parecía funcionar y no guardaba nada.
     *
     * Se guarda clave por clave, no todo de una vez: cada una es una decisión
     * independiente y guardarlas juntas obligaría a revisar veintiséis
     * selectores para cambiar uno.
     *
     * @var array<string, int|null>
     */
    public array $selected = [];

    public string $search = '';

    public function mount(AccountMappingService $mappings): void
    {
        $this->authorize('viewAny', AccountMapping::class);

        $configured = $mappings->all();

        foreach (AccountMappingKey::cases() as $key) {
            $this->selected[$key->name] = $configured->get($key->value)?->account_id;
        }
    }

    public function assign(string $name, AccountMappingService $mappings): void
    {
        $this->authorize('update', AccountMapping::class);

        $mappingKey = $this->keyNamed($name);

        if ($mappingKey === null) {
            return;
        }

        $accountId = $this->selected[$name] ?? null;

        if ($accountId === null || $accountId === '') {
            $this->addError('selected.'.$name, 'Elegí una cuenta.');

            return;
        }

        try {
            $account = Account::query()->findOrFail((int) $accountId);
            $mappings->assign($mappingKey, $account);
        } catch (InvalidAccountException $e) {
            $this->addError('selected.'.$name, $e->getMessage());

            return;
        }

        $this->resetErrorBag('selected.'.$name);

        session()->flash('success', sprintf(
            '«%s» ahora se contabiliza en %s — %s.',
            $mappingKey->label(),
            $account->code,
            $account->name,
        ));
    }

    public function render(AccountMappingService $mappings): View
    {
        $this->authorize('viewAny', AccountMapping::class);

        return view('livewire.accounting.account-mapping-index', [
            'groups' => $this->groups(),
            'accounts' => $this->postableAccounts(),
            'missing' => $mappings->missing(),
        ]);
    }

    private function keyNamed(string $name): ?AccountMappingKey
    {
        foreach (AccountMappingKey::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Claves agrupadas por módulo, filtradas por la búsqueda.
     *
     * @return Collection<string, Collection<int, AccountMappingKey>>
     */
    private function groups(): Collection
    {
        $term = mb_strtolower(trim($this->search));

        return collect(AccountMappingKey::cases())
            ->filter(fn (AccountMappingKey $key) => $term === ''
                || str_contains(mb_strtolower($key->label()), $term)
                || str_contains(mb_strtolower($key->module()), $term)
                || str_contains($key->value, $term))
            ->groupBy(fn (AccountMappingKey $key) => $key->module());
    }

    /**
     * Solo cuentas de detalle: una cuenta de resumen no admite movimientos, y
     * ofrecerla aquí sería ofrecer un error que el servicio va a rechazar.
     *
     * @return Collection<int, Account>
     */
    private function postableAccounts(): Collection
    {
        return Account::query()
            ->where('is_postable', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}
