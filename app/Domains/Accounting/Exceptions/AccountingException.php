<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Exceptions;

use RuntimeException;

/**
 * Base de los errores de negocio del dominio contable. Se distingue de los
 * errores técnicos para poder mostrarlos al usuario tal cual: todos los
 * mensajes están redactados para un contador, no para un desarrollador.
 */
class AccountingException extends RuntimeException {}
