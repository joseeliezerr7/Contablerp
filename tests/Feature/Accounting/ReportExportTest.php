<?php

declare(strict_types=1);

use App\Domains\Accounting\Services\AccountingEngine;
use App\Domains\Identity\Data\PermissionCatalog;
use App\Domains\Reporting\DataTransfer\ReportDocument;
use App\Domains\Reporting\Services\ReportExporter;
use App\Livewire\Accounting\Reports\BalanceSheetReport;
use App\Livewire\Accounting\Reports\CashFlowReport;
use App\Livewire\Accounting\Reports\IncomeStatementReport;
use App\Livewire\Accounting\Reports\ReportCenter;
use App\Livewire\Accounting\Reports\TrialBalanceReport;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    [$this->company, $this->user] = accountingCompanyWithAccountant();
    $this->year = (int) now()->format('Y');

    postSampleYear(app(AccountingEngine::class), $this->year);
});

$reports = [
    'balance de comprobación' => TrialBalanceReport::class,
    'estado de resultados' => IncomeStatementReport::class,
    'balance general' => BalanceSheetReport::class,
    'flujo de efectivo' => CashFlowReport::class,
];

foreach ($reports as $name => $component) {
    it("muestra el {$name}", function () use ($component) {
        Livewire::test($component)
            ->set('from', "{$this->year}-01-01")
            ->set('to', "{$this->year}-12-31")
            ->assertOk();
    });

    it("exporta el {$name} a PDF", function () use ($component) {
        $response = Livewire::test($component)
            ->set('from', "{$this->year}-01-01")
            ->set('to', "{$this->year}-12-31")
            ->call('exportPdf')
            ->effects['download'] ?? null;

        expect($response)->not->toBeNull()
            ->and($response['name'])->toEndWith('.pdf');
    });

    it("exporta el {$name} a Excel", function () use ($component) {
        $response = Livewire::test($component)
            ->set('from', "{$this->year}-01-01")
            ->set('to', "{$this->year}-12-31")
            ->call('exportExcel')
            ->effects['download'] ?? null;

        expect($response)->not->toBeNull()
            ->and($response['name'])->toEndWith('.xlsx');
    });
}

/**
 * Devuelve el documento de un componente de reporte ya configurado.
 */
function documentOf(string $component, int $year): ReportDocument
{
    return Livewire::test($component)
        ->set('from', "{$year}-01-01")
        ->set('to', "{$year}-12-31")
        ->instance()
        ->document();
}

function streamedContents(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

it('genera un PDF válido, no solo un archivo con extensión pdf', function () {
    // Se ejecuta el streaming y se inspecciona el contenido: comprobar solo el
    // nombre del archivo dejaría pasar un PDF corrupto o vacío.
    $contents = streamedContents(
        app(ReportExporter::class)->pdf(documentOf(TrialBalanceReport::class, $this->year))
    );

    expect($contents)->toStartWith('%PDF-')
        ->and(strlen($contents))->toBeGreaterThan(1000)
        ->and($contents)->toContain('%%EOF');
});

it('genera un Excel válido, legible y con importes sumables', function () {
    $contents = streamedContents(
        app(ReportExporter::class)->excel(documentOf(TrialBalanceReport::class, $this->year))
    );

    // Un .xlsx es un ZIP: empieza con la firma PK.
    expect(substr($contents, 0, 2))->toBe('PK')
        ->and(strlen($contents))->toBeGreaterThan(1000);

    $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    file_put_contents($path, $contents);

    $sheet = IOFactory::load($path)->getActiveSheet();

    // El encabezado usa el nombre comercial, que es como la empresa se
    // identifica en sus documentos.
    expect($sheet->getCell('A1')->getValue())->toBe($this->company->displayName());

    // Los importes deben ser números, no texto: el contador espera poder sumar
    // la columna en Excel.
    $amounts = [];

    foreach ($sheet->getRowIterator() as $row) {
        $value = $sheet->getCell('D'.$row->getRowIndex())->getValue();

        if (is_numeric($value) && $value > 0) {
            $amounts[] = $value;
        }
    }

    expect($amounts)->not->toBeEmpty()
        ->and($amounts[0])->toBeFloat();

    unlink($path);
});

it('niega la exportación a quien no tiene el permiso', function () {
    actingAsUserOf($this->company, role: PermissionCatalog::SALESPERSON);

    Livewire::test(TrialBalanceReport::class)->assertForbidden();
});

it('muestra el centro de reportes con el estado del ejercicio', function () {
    Livewire::test(ReportCenter::class)
        ->assertOk()
        ->assertSee('Centro de reportes')
        ->assertSee('Balance de comprobación');
});

it('lista los impedimentos para cerrar el ejercicio', function () {
    Livewire::test(ReportCenter::class)
        ->assertOk()
        // Con los períodos abiertos, el cierre no está disponible todavía.
        ->assertSee('períodos abiertos');
});
