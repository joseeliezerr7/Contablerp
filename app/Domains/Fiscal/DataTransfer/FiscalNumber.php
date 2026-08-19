<?php

declare(strict_types=1);

namespace App\Domains\Fiscal\DataTransfer;

use App\Domains\Fiscal\Models\FiscalAuthorization;

/**
 * Un correlativo fiscal ya reservado, con todo lo que hay que congelar en el
 * documento.
 *
 * Se devuelve como un objeto y no como una cadena porque el número solo no
 * sirve: la factura tiene que guardar además el CAI, el rango y la fecha límite
 * con los que se emitió. Devolver solo el texto obligaría a cada quien a volver
 * a leer la autorización, y para entonces podría haber cambiado.
 */
final readonly class FiscalNumber
{
    public function __construct(
        public FiscalAuthorization $authorization,
        public int $sequence,
        public string $number,
    ) {}

    /**
     * Los atributos que se copian tal cual en la cabecera del documento.
     *
     * @return array<string, mixed>
     */
    public function documentAttributes(): array
    {
        return [
            'fiscal_authorization_id' => $this->authorization->id,
            'number' => $this->number,
            'cai' => $this->authorization->cai,
            'fiscal_range_from' => $this->authorization->range_from,
            'fiscal_range_to' => $this->authorization->range_to,
            'fiscal_limit_date' => $this->authorization->limit_date->toDateString(),
            'fiscal_sequence' => $this->sequence,
        ];
    }
}
