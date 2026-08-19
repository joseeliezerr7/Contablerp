<?php

declare(strict_types=1);

namespace App\Domains\Accounting\Console;

use App\Domains\Accounting\Enums\JournalEntryStatus;
use App\Domains\Tenancy\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reconstruye account_balances desde journal_entry_lines.
 *
 * La tabla de saldos es una materialización por rendimiento; la fuente de
 * verdad son las líneas del diario. Este comando permite regenerarla tras una
 * importación, una migración de datos o si se sospecha de una inconsistencia,
 * y sirve además de verificación: si los saldos ya eran correctos, el
 * resultado es idéntico.
 */
class RebuildAccountBalances extends Command
{
    protected $signature = 'accounting:rebuild-balances
                            {--company= : ID de la empresa; por defecto todas}
                            {--check : Solo compara y reporta diferencias, sin escribir}';

    protected $description = 'Reconstruye los saldos por cuenta y período a partir del libro diario';

    public function handle(CompanyContext $context): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            $this->error('No se encontraron empresas.');

            return self::FAILURE;
        }

        $checkOnly = (bool) $this->option('check');
        $totalDifferences = 0;

        foreach ($companies as $company) {
            $differences = $context->runFor(
                $company,
                fn (): int => $this->rebuildFor($company, $checkOnly),
            );

            $totalDifferences += $differences;
        }

        if ($checkOnly) {
            if ($totalDifferences === 0) {
                $this->info('Los saldos coinciden con el libro diario.');

                return self::SUCCESS;
            }

            $this->warn("Se encontraron {$totalDifferences} diferencia(s). Ejecuta el comando sin --check para corregirlas.");

            return self::FAILURE;
        }

        $this->info('Saldos reconstruidos.');

        return self::SUCCESS;
    }

    private function rebuildFor(Company $company, bool $checkOnly): int
    {
        // Agregado directo sobre las líneas: es la definición misma del saldo.
        $expected = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $company->id)
            ->where('e.status', JournalEntryStatus::Posted->value)
            ->groupBy('l.account_id', 'e.accounting_period_id')
            ->selectRaw('l.account_id, e.accounting_period_id, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get();

        if ($checkOnly) {
            return $this->reportDifferences($company, $expected);
        }

        DB::transaction(function () use ($company, $expected): void {
            DB::table('account_balances')->where('company_id', $company->id)->delete();

            $rows = $expected->map(fn ($row) => [
                'company_id' => $company->id,
                'account_id' => $row->account_id,
                'accounting_period_id' => $row->accounting_period_id,
                'period_debit' => $row->debit,
                'period_credit' => $row->credit,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('account_balances')->insert($chunk);
            }
        });

        $this->line(sprintf(
            '  %s: %d saldo(s) reconstruido(s).',
            $company->displayName(),
            $expected->count(),
        ));

        return 0;
    }

    /**
     * @param  Collection<int, object>  $expected
     */
    private function reportDifferences(Company $company, $expected): int
    {
        $stored = DB::table('account_balances')
            ->where('company_id', $company->id)
            ->get()
            ->keyBy(fn ($row) => $row->account_id.':'.$row->accounting_period_id);

        $differences = [];

        foreach ($expected as $row) {
            $key = $row->account_id.':'.$row->accounting_period_id;
            $current = $stored->get($key);

            if ($current === null) {
                $differences[] = [$key, 'falta', (string) $row->debit, (string) $row->credit];

                continue;
            }

            if (bccomp((string) $current->period_debit, (string) $row->debit, 4) !== 0
                || bccomp((string) $current->period_credit, (string) $row->credit, 4) !== 0) {
                $differences[] = [
                    $key,
                    'distinto',
                    "{$current->period_debit} / {$current->period_credit}",
                    "{$row->debit} / {$row->credit}",
                ];
            }

            $stored->forget($key);
        }

        // Lo que queda almacenado sin respaldo en el diario también es una
        // diferencia: son saldos de partidas que ya no existen.
        foreach ($stored as $key => $orphan) {
            $differences[] = [$key, 'sobrante', "{$orphan->period_debit} / {$orphan->period_credit}", '—'];
        }

        if ($differences !== []) {
            $this->warn($company->displayName().':');
            $this->table(['cuenta:período', 'problema', 'almacenado', 'diario'], $differences);
        }

        return count($differences);
    }
}
