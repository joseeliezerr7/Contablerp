<?php

declare(strict_types=1);

namespace App\Livewire\Fiscal;

use App\Domains\Fiscal\Enums\AuthorizationStatus;
use App\Domains\Fiscal\Enums\FiscalDocumentType;
use App\Domains\Fiscal\Exceptions\FiscalException;
use App\Domains\Fiscal\Models\FiscalAuthorization;
use App\Domains\Fiscal\Models\FiscalPoint;
use App\Domains\Fiscal\Services\FiscalAuthorizationService;
use App\Domains\Tenancy\Models\Branch;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Puntos de emisión y autorizaciones del SAR.
 *
 * Es la pantalla que decide si la empresa puede facturar o no, así que lo
 * primero que se ve no es una tabla: es el aviso de cuánto le queda al CAI en
 * correlativos y en días. Enterarse de que se agotó al intentar cobrarle a un
 * cliente es la peor forma de enterarse.
 */
#[Title('Régimen de facturación')]
class FiscalPointIndex extends Component
{
    // Punto de emisión
    public ?int $editingPoint = null;

    public bool $showingPointForm = false;

    public ?int $branch_id = null;

    public string $establishment_code = '';

    public string $emission_point_code = '';

    public string $name = '';

    public bool $is_active = true;

    // Autorización
    public ?int $authorizingPoint = null;

    public ?int $editingAuthorization = null;

    public string $document_type = 'invoice';

    public string $document_type_code = '01';

    public string $cai = '';

    public string $range_from = '1';

    public string $range_to = '';

    public string $issued_on = '';

    public string $limit_date = '';

    public string $notes = '';

    public ?int $retiring = null;

    public function mount(): void
    {
        $this->issued_on = now()->toDateString();
        $this->limit_date = now()->addYear()->toDateString();
    }

    /*
    |--------------------------------------------------------------------------
    | Punto de emisión
    |--------------------------------------------------------------------------
    */

    public function newPoint(): void
    {
        $this->authorize('create', FiscalPoint::class);

        $this->resetPointForm();
        $this->branch_id = Branch::query()->active()->value('id');
        $this->showingPointForm = true;
    }

    public function editPoint(int $id): void
    {
        $point = FiscalPoint::query()->findOrFail($id);
        $this->authorize('update', $point);

        $this->editingPoint = $point->id;
        $this->branch_id = $point->branch_id;
        $this->establishment_code = $point->establishment_code;
        $this->emission_point_code = $point->emission_point_code;
        $this->name = $point->name;
        $this->is_active = $point->is_active;
        $this->showingPointForm = true;
    }

    public function savePoint(): void
    {
        $data = $this->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            // Tres dígitos exactos: los ceros a la izquierda son parte del
            // código que asignó el SAR, no un adorno.
            'establishment_code' => ['required', 'digits:3'],
            'emission_point_code' => ['required', 'digits:3'],
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['boolean'],
        ], attributes: [
            'branch_id' => 'sucursal',
            'establishment_code' => 'código de establecimiento',
            'emission_point_code' => 'código de punto de emisión',
            'name' => 'nombre',
        ]);

        $duplicate = FiscalPoint::query()
            ->where('establishment_code', $data['establishment_code'])
            ->where('emission_point_code', $data['emission_point_code'])
            ->when($this->editingPoint !== null, fn ($q) => $q->whereKeyNot($this->editingPoint))
            ->exists();

        if ($duplicate) {
            $this->addError('emission_point_code', 'Ya existe un punto de emisión con ese establecimiento y punto.');

            return;
        }

        if ($this->editingPoint !== null) {
            $point = FiscalPoint::query()->findOrFail($this->editingPoint);
            $this->authorize('update', $point);
            $point->update($data);
        } else {
            $this->authorize('create', FiscalPoint::class);
            FiscalPoint::create($data);
        }

        session()->flash('success', 'Punto de emisión guardado.');
        $this->resetPointForm();
    }

    public function resetPointForm(): void
    {
        $this->reset([
            'editingPoint', 'showingPointForm', 'branch_id',
            'establishment_code', 'emission_point_code', 'name',
        ]);
        $this->is_active = true;
        $this->resetValidation();
    }

    /*
    |--------------------------------------------------------------------------
    | Autorización
    |--------------------------------------------------------------------------
    */

    public function newAuthorization(int $pointId): void
    {
        $this->authorize('create', FiscalAuthorization::class);

        $this->resetAuthorizationForm();
        $this->authorizingPoint = $pointId;
    }

    public function editAuthorization(int $id): void
    {
        $authorization = FiscalAuthorization::query()->findOrFail($id);
        $this->authorize('update', $authorization);

        $this->editingAuthorization = $authorization->id;
        $this->authorizingPoint = $authorization->fiscal_point_id;
        $this->document_type = $authorization->document_type->value;
        $this->document_type_code = $authorization->document_type_code;
        $this->cai = $authorization->cai;
        $this->range_from = (string) $authorization->range_from;
        $this->range_to = (string) $authorization->range_to;
        $this->issued_on = $authorization->issued_on->toDateString();
        $this->limit_date = $authorization->limit_date->toDateString();
        $this->notes = (string) $authorization->notes;
    }

    /**
     * Al cambiar el tipo se sugiere su código habitual, pero se puede corregir:
     * manda lo que diga la resolución del SAR, no lo que crea el sistema.
     */
    public function updatedDocumentType(string $value): void
    {
        $this->document_type_code = FiscalDocumentType::from($value)->suggestedCode();
    }

    public function saveAuthorization(FiscalAuthorizationService $service): void
    {
        $data = $this->validate([
            'document_type' => ['required', Rule::in(FiscalDocumentType::values())],
            'document_type_code' => ['required', 'digits:2'],
            'cai' => ['required', 'string', 'max:40'],
            'range_from' => ['required', 'integer', 'min:1'],
            'range_to' => ['required', 'integer', 'min:1', 'gte:range_from'],
            'issued_on' => ['required', 'date'],
            // La fecha límite no puede ser anterior a la emisión: sería una
            // autorización nacida vencida.
            'limit_date' => ['required', 'date', 'after_or_equal:issued_on'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], attributes: [
            'document_type' => 'tipo de documento',
            'document_type_code' => 'código del tipo',
            'cai' => 'CAI',
            'range_from' => 'correlativo inicial',
            'range_to' => 'correlativo final',
            'issued_on' => 'fecha de autorización',
            'limit_date' => 'fecha límite de emisión',
        ]);

        try {
            if ($this->editingAuthorization !== null) {
                $authorization = FiscalAuthorization::query()->findOrFail($this->editingAuthorization);
                $this->authorize('update', $authorization);
                $service->update($authorization, $data);
            } else {
                $this->authorize('create', FiscalAuthorization::class);
                $point = FiscalPoint::query()->findOrFail($this->authorizingPoint);
                $service->register($point, $data);
            }

            session()->flash('success', 'Autorización registrada.');
            $this->resetAuthorizationForm();
        } catch (FiscalException $e) {
            $this->addError('cai', $e->getMessage());
        }
    }

    public function confirmRetire(int $id): void
    {
        $this->retiring = $id;
    }

    /**
     * Da por terminada la autorización vigente. Se usa cuando el SAR emite una
     * nueva y hay que dejar de usar la anterior aunque le queden correlativos.
     */
    public function retire(FiscalAuthorizationService $service): void
    {
        $authorization = FiscalAuthorization::query()->findOrFail($this->retiring);
        $this->authorize('replace', $authorization);

        $service->retire($authorization, AuthorizationStatus::Replaced);

        session()->flash('success', 'La autorización quedó fuera de uso.');
        $this->retiring = null;
    }

    public function resetAuthorizationForm(): void
    {
        $this->reset(['authorizingPoint', 'editingAuthorization', 'cai', 'range_to', 'notes']);
        $this->document_type = 'invoice';
        $this->document_type_code = '01';
        $this->range_from = '1';
        $this->issued_on = now()->toDateString();
        $this->limit_date = now()->addYear()->toDateString();
        $this->resetValidation();
    }

    public function render(FiscalAuthorizationService $service): View
    {
        // La guarda de lectura va aquí y no solo en las acciones: sin ella,
        // cualquiera con sesión abierta veía los CAI de la empresa aunque no
        // pudiera tocarlos.
        $this->authorize('viewAny', FiscalPoint::class);

        $points = FiscalPoint::query()
            ->with(['branch', 'authorizations' => fn ($q) => $q->orderByDesc('status')->orderByDesc('id')])
            ->orderBy('establishment_code')
            ->orderBy('emission_point_code')
            ->get();

        return view('livewire.fiscal.fiscal-point-index', [
            'points' => $points,
            'branches' => Branch::query()->active()->orderBy('code')->get(),
            'types' => FiscalDocumentType::cases(),
            // Lo primero de la pantalla: a qué CAI hay que ponerle atención.
            'alerts' => $service->needingRenewal(),
        ]);
    }
}
