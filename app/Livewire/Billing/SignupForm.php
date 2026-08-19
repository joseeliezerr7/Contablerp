<?php

declare(strict_types=1);

namespace App\Livewire\Billing;

use App\Domains\Billing\Exceptions\BillingException;
use App\Domains\Billing\Models\Plan;
use App\Domains\Billing\Services\SignupService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Alta self-service, la puerta de entrada del SaaS.
 *
 * Pide lo mínimo para poder facturar: quién es, cómo se llama la empresa y su
 * RTN. Todo lo demás —sucursal, bodega, plan de cuentas, ejercicio fiscal— lo
 * crea el servicio detrás, porque pedirle eso a alguien que todavía no sabe si
 * le sirve el sistema es perderlo en la primera pantalla.
 */
#[Layout('components.layouts.guest', ['width' => 'max-w-3xl'])]
#[Title('Crear cuenta')]
class SignupForm extends Component
{
    #[Url(as: 'plan', except: '')]
    public string $planCode = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $legal_name = '';

    public string $trade_name = '';

    public string $tax_id = '';

    public string $phone = '';

    public bool $accepted = false;

    public function mount(): void
    {
        if ($this->planCode === '') {
            $this->planCode = (string) $this->plans()->first()?->code;
        }
    }

    protected function rules(): array
    {
        return [
            'planCode' => ['required', 'string', Rule::in($this->plans()->pluck('code')->all())],
            'name' => ['required', 'string', 'max:120'],
            // La unicidad se comprueba aquí para dar un mensaje de campo, y otra
            // vez dentro del servicio, que es donde de verdad tiene que fallar.
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'legal_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'tax_id' => ['required', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:40'],
            'accepted' => ['accepted'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'planCode' => 'plan',
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'password' => 'contraseña',
            'legal_name' => 'razón social',
            'trade_name' => 'nombre comercial',
            'tax_id' => 'RTN',
            'phone' => 'teléfono',
            'accepted' => 'aceptación de los términos',
        ];
    }

    protected function messages(): array
    {
        return [
            'accepted.accepted' => 'Debes aceptar los términos del servicio.',
        ];
    }

    public function register(SignupService $signup): void
    {
        $data = $this->validate();

        $plan = $this->plans()->firstWhere('code', $this->planCode);

        try {
            $result = $signup->register($data, $plan);
        } catch (BillingException $e) {
            $this->addError('email', $e->getMessage());

            return;
        }

        // Entra directo: acaba de escribir la contraseña, pedírsela otra vez en
        // la pantalla siguiente no protege de nada.
        Auth::login($result['user']);
        session()->regenerate();
        session()->flash('success', 'Tu cuenta está lista. Empieza por revisar tu catálogo de cuentas.');

        $this->redirectRoute('dashboard', navigate: false);
    }

    /**
     * @return Collection<int, Plan>
     */
    private function plans()
    {
        return once(fn () => Plan::query()->public()->orderBy('sort_order')->get());
    }

    public function render(): View
    {
        return view('livewire.billing.signup-form', [
            'plans' => $this->plans(),
        ]);
    }
}
