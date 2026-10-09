<?php

use App\Enums\ImportType;
use App\Enums\ReconciliationStatus;
use App\Enums\UserRole;
use App\Models\BankAccount;
use App\Models\Reconciliation;
use App\Services\Imports\ImportManager;
use App\Services\Reconciliation\ReconciliationEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

function reconciledJuly(): Reconciliation
{
    $account = BankAccount::factory()->create(['fund_cluster' => '101-MOOE']);
    $account->signatories()->createMany([
        ['block' => 'prepared_by', 'name' => 'Joe Ann D. Nisnisan', 'designation' => 'AO IV/FA', 'sort_order' => 1],
        ['block' => 'certified_correct', 'name' => 'Fernando M. Manlaran', 'designation' => 'Admin Asst. III', 'sort_order' => 2],
    ]);

    $reconciliation = Reconciliation::factory()->for($account)->create([
        'status' => ReconciliationStatus::Draft,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'statement_label' => 'As of July 31, 2026',
        'unadjusted_bank_balance' => 1_010_000,
        'unadjusted_book_balance' => 1_000_000,
    ]);

    $manager = app(ImportManager::class);
    foreach ([[ImportType::Rci, 'rci.xlsx'], [ImportType::BankStatement, 'bank_statement.csv']] as [$type, $file]) {
        $upload = new UploadedFile(base_path("tests/fixtures/{$file}"), $file, null, null, true);
        $manager->commit($manager->parseUpload($reconciliation, $type, $upload, null));
    }
    app(ReconciliationEngine::class)->run($reconciliation->fresh());

    return $reconciliation->fresh();
}

beforeEach(fn () => Storage::fake('local'));

it('exports the BRS as a valid xlsx', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = reconciledJuly();

    $response = $this->get("/api/v1/reconciliations/{$reconciliation->id}/export/brs.xlsx");

    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect(substr($response->streamedContent(), 0, 2))->toBe('PK'); // zip magic
});

it('exports Schedule 1 as a valid xlsx', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = reconciledJuly();

    $response = $this->get("/api/v1/reconciliations/{$reconciliation->id}/export/schedule-1.xlsx");

    $response->assertOk();
    expect(substr($response->streamedContent(), 0, 2))->toBe('PK');
});

it('exports the BRS as a PDF', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = reconciledJuly();

    $response = $this->get("/api/v1/reconciliations/{$reconciliation->id}/export/brs.pdf");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('lets the reviewer and disbursing officer export too', function () {
    $reconciliation = reconciledJuly();

    foreach ([UserRole::BudgetOfficer, UserRole::DisbursingOfficer] as $role) {
        actingAsRole($role);
        $this->get("/api/v1/reconciliations/{$reconciliation->id}/export/brs.xlsx")->assertOk();
    }
});

it('exports the Report of Checks Issued as a valid xlsx', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = reconciledJuly();

    $response = $this->get("/api/v1/reconciliations/{$reconciliation->id}/export/rci.xlsx");

    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect(substr($response->streamedContent(), 0, 2))->toBe('PK');
});

it('exports each list of the Matching tab with the same records the screen shows', function () {
    actingAsRole(UserRole::FinancialAnalyst);
    $reconciliation = reconciledJuly();

    // The tab's own counts, straight from the board the screen reads.
    $board = $this->getJson("/api/v1/reconciliations/{$reconciliation->id}/matches")->assertOk();
    $checks = collect($board->json('checks'));
    $expected = [
        'cleared' => $checks->where('status', 'cleared')->count(),
        'outstanding' => $checks->whereIn('status', ['outstanding', 'stale'])->count(),
        'flags' => count($board->json('flags')),
    ];

    foreach ($expected as $category => $count) {
        $response = $this->get("/api/v1/reconciliations/{$reconciliation->id}/export/matching.xlsx?category={$category}");

        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'match').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        // Records start on row 8; "TOTAL" closes the check lists, flags have none.
        $listed = collect(range(8, $sheet->getHighestRow()))->filter(fn ($r) => is_numeric($sheet->getCell("A{$r}")->getValue()))->count();
        expect($listed)->toBe($count, "{$category} rows in the Excel file");
    }

    $this->getJson("/api/v1/reconciliations/{$reconciliation->id}/export/matching.xlsx")->assertStatus(422);
    $this->getJson("/api/v1/reconciliations/{$reconciliation->id}/export/matching.xlsx?category=nope")->assertStatus(422);
    $this->assertDatabaseHas('audit_logs', ['action' => 'exported', 'description' => 'Downloaded the Cleared Checks list (Excel) of reconciliation #'.$reconciliation->id]);
});

it('rejects an export for a guest', function () {
    $reconciliation = reconciledJuly();

    $this->getJson("/api/v1/reconciliations/{$reconciliation->id}/export/brs.xlsx")->assertUnauthorized();
});
