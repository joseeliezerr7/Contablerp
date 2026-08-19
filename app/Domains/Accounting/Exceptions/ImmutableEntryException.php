<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Exceptions;

use App\Domains\Accounting\Models\JournalEntry;

/**
 * Una partida contabilizada no se toca. Si hay que corregirla, se genera una
 * reversión o un ajuste, y ambos quedan en el historial.
 */
final class ImmutableEntryException extends AccountingException
{
    public static function cannotEdit(JournalEntry $entry): self
    {
        return new self(sprintf(
            'La partida %s está %s y no puede modificarse. Genera una reversión o una partida de ajuste.',
            $entry->number,
            mb_strtolower($entry->status->label()),
        ));
    }

    public static function alreadyPosted(JournalEntry $entry): self
    {
        return new self("La partida {$entry->number} ya está contabilizada.");
    }

    public static function alreadyVoided(JournalEntry $entry): self
    {
        return new self("La partida {$entry->number} ya está anulada.");
    }

    public static function cannotVoidDraft(JournalEntry $entry): self
    {
        return new self(
            "La partida {$entry->number} es un borrador: elimínala en vez de anularla."
        );
    }
}
