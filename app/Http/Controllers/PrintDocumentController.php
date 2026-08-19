<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Fiscal\Services\DocumentPrinter;
use App\Domains\Sales\Models\CreditNote;
use App\Domains\Sales\Models\Sale;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Impresión de documentos fiscales.
 *
 * Un controlador y no un componente Livewire: la descarga de un PDF es una
 * petición HTTP normal, y hacerla pasar por el ciclo de Livewire solo añade
 * problemas de serialización.
 *
 * Los modelos se buscan por id dentro del método en vez de por route-model
 * binding: el binding corre en `SubstituteBindings`, **antes** que el middleware
 * `company`, así que en ese momento el scope de empresa todavía no está activo.
 * Es la misma convención que siguen los componentes Livewire del sistema.
 */
class PrintDocumentController extends Controller
{
    public function __construct(private readonly DocumentPrinter $printer) {}

    public function invoice(int $sale): StreamedResponse
    {
        $document = Sale::query()->findOrFail($sale);

        $this->authorize('print', $document);

        return $this->printer->invoice($document);
    }

    public function creditNote(int $creditNote): StreamedResponse
    {
        $document = CreditNote::query()->findOrFail($creditNote);

        $this->authorize('print', $document);

        return $this->printer->creditNote($document);
    }
}
