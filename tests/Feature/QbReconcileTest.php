<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\QbReconcile;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** QuickBooks check: each transaction number of the file against the final forms of the system. */
class QbReconcileTest extends TestCase
{
    private function doc(string $num, float $amount, string $cur = 'SYP', string $state = 'done', string $date = '2026-11-05', string $form = 'ptr'): Document
    {
        return Document::create(['form_code' => $form, 'state' => $state, 'number' => $num,
            'date' => $date, 'currency' => $cur, 'amount' => $amount, 'user_id' => $this->admin()->id, 'data' => []]);
    }

    /** A "Transaction List by Date" export as QuickBooks makes it: title rows, header, data, total row. */
    private function csv(array $lines): UploadedFile
    {
        $rows = ["Violet Organization", "Transaction List by Date", "November 1-30, 2026", "",
            "Date,Transaction Type,No.,Name,Currency,Foreign Amount,Amount"];
        foreach ($lines as $l) {
            $rows[] = implode(',', array_map(fn ($v) => str_contains((string) $v, ',') ? '"' . $v . '"' : $v, $l));
        }
        $rows[] = 'TOTAL,,,,,,"999,999.00"';
        $path = tempnam(sys_get_temp_dir(), 'qb') . '.csv';
        file_put_contents($path, implode("\n", $rows));
        return new UploadedFile($path, 'qb-november.csv', 'text/csv', null, true);
    }

    public function test_every_status_is_found(): void
    {
        $this->doc('T2600001', 197884);                       // same in QuickBooks
        $this->doc('T2600002', 50000);                        // QuickBooks has 52,000
        $this->doc('T2600003', 1000, 'USD');                  // not in QuickBooks
        $this->doc('T2600004', 300, 'USD', 'cancel');         // cancelled but in QuickBooks

        $file = $this->csv([
            ['11/05/2026', 'Bill', 'T2600001', 'Supplier A', 'SYP', '197,884.00', '1,622.00'],
            ['11/05/2026', 'Bill', 'T2600002', 'Supplier B', 'SYP', '52,000.00', '426.23'],
            ['11/06/2026', 'Bill', 'T2600004', 'Supplier C', 'USD', '300.00', '300.00'],
            ['11/07/2026', 'Bill', 'T2600077', 'Supplier D', 'USD', '10.00', '10.00'],    // never given
            ['11/08/2026', 'Bill Payment', '1045', 'Supplier A', 'USD', '', '1,622.00'],   // not from the system
        ]);
        $this->actingAs($this->admin())->post('/reconcile', ['file' => $file])->assertRedirect();
        $run = \App\Models\QbReconciliation::firstOrFail();
        $by = collect($run->results())->keyBy('num');

        $this->assertSame('ok', $by['T2600001']['status']);
        $this->assertSame('amount', $by['T2600002']['status']);
        $this->assertEquals(2000, $by['T2600002']['diff']);
        $this->assertSame('missing', $by['T2600003']['status']);
        $this->assertSame('cancelled', $by['T2600004']['status']);
        $this->assertSame('unknown', $by['T2600077']['status']);
        $this->assertSame(1, $run->other['rows']);
        $this->assertSame('2026-11-05', $run->period_from->format('Y-m-d'));
        $this->assertSame(1, $run->summary['ok']);
        $this->assertSame(4, $run->summary['issues']);

        $this->get('/reconcile/' . $run->id)->assertOk()->assertSee('T2600002');
        $this->get('/reconcile/' . $run->id . '/excel')->assertOk();
        $this->get('/reconcile')->assertOk();
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'reconcile')->count());
    }

    public function test_detail_report_ignores_the_payable_side(): void
    {
        $rows = [['Date', 'Transaction Type', 'Num', 'Account', 'Amount'],
            ['11/05/2026', 'Bill', 'T2600001', 'Accounts Payable-USD', '-150.00'],
            ['11/05/2026', 'Bill', 'T2600001', 'Utilities', '100.00'],
            ['11/05/2026', 'Bill', 'T2600001', 'Office Rents', '50.00']];
        $det = QbReconcile::detect($rows);
        [$tx] = QbReconcile::qbTransactions($rows, $det);
        $this->assertEquals(150.0, $tx['T2600001']['amount']);
    }

    public function test_money_and_dates_in_the_ways_quickbooks_writes_them(): void
    {
        $this->assertSame(1234.56, QbReconcile::money('1,234.56'));
        $this->assertSame(-1234.56, QbReconcile::money('(1,234.56)'));
        $this->assertSame(1234.56, QbReconcile::money('1.234,56'));
        $this->assertSame(1234.0, QbReconcile::money('1,234'));
        $this->assertSame('2026-11-05', QbReconcile::date('11/05/2026'));
        $this->assertSame('2026-11-25', QbReconcile::date('25/11/2026'));
        $this->assertSame('2026-11-05', QbReconcile::date(new \DateTime('2026-11-05')));
    }

    public function test_a_file_without_numbers_is_refused_with_help(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'qb') . '.csv';
        file_put_contents($path, "Account,Balance\nCash,100");
        $this->actingAs($this->admin())->post('/reconcile', ['file' => new UploadedFile($path, 'x.csv', 'text/csv', null, true)])
            ->assertSessionHasErrors('file');
    }

    public function test_excel_export_with_real_dates(): void
    {
        $this->doc('T2600001', 1500, 'USD');
        $path = tempnam(sys_get_temp_dir(), 'qb') . '.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer();
        $w->openToFile($path);
        foreach ([['Violet Organization'], ['Transaction List by Date'], [], ['Date', 'Transaction Type', 'No.', 'Name', 'Amount'],
            [new \DateTimeImmutable('2026-11-05'), 'Bill', 'T2600001', 'Supplier A', 1500.0]] as $r) {
            $w->addRow(\OpenSpout\Common\Entity\Row::fromValues($r));
        }
        $w->close();
        $this->actingAs($this->admin())->post('/reconcile', ['file' => new UploadedFile($path, 'qb.xlsx', null, null, true)])->assertRedirect();
        $run = \App\Models\QbReconciliation::firstOrFail();
        $this->assertSame(1, $run->summary['ok'], json_encode($run->results()));
        $this->assertSame(0, $run->summary['issues']);
    }

    public function test_each_form_must_be_the_right_type_in_quickbooks(): void
    {
        $this->doc('T2600010', 500, 'USD', 'done', '2026-11-05', 'ptr');      // Bill: right
        $this->doc('T2600011', 800, 'USD', 'done', '2026-11-05', 'jar');      // Journal Entry: wrong
        $this->doc('T2600012', 900, 'USD', 'done', '2026-11-05', 'journal');  // Journal Entry: right
        $this->doc('T2600013', 700, 'USD', 'done', '2026-11-05', 'journal');  // Bill Payment: right
        $this->doc('T2600014', 300, 'USD', 'done', '2026-11-05', 'journal');  // Bill: wrong
        $file = $this->csv([
            ['11/05/2026', 'Bill', 'T2600010', 'A', 'USD', '500.00', '500.00'],
            ['11/05/2026', 'Journal Entry', 'T2600011', '', 'USD', '800.00', '800.00'],
            ['11/05/2026', 'Journal Entry', 'T2600012', '', 'USD', '900.00', '900.00'],
            ['11/05/2026', 'Bill Payment (Check)', 'T2600013', 'B', 'USD', '-700.00', '-700.00'],
            ['11/05/2026', 'Bill', 'T2600014', 'C', 'USD', '300.00', '300.00'],
        ]);
        $this->actingAs($this->admin())->post('/reconcile', ['file' => $file]);
        $run = \App\Models\QbReconciliation::firstOrFail();
        $by = collect($run->results())->keyBy('num');
        $this->assertSame('ok', $by['T2600010']['status']);
        $this->assertSame('type', $by['T2600011']['status']);
        $this->assertSame('ok', $by['T2600012']['status']);
        $this->assertSame('ok', $by['T2600013']['status']);
        $this->assertSame('type', $by['T2600014']['status']);
        $this->assertSame(['ok' => 1, 'issues' => 1], $run->summary['kinds']['bill']);
        $this->assertSame(1, $run->summary['kinds']['payment']['ok']);
        $this->get('/reconcile/' . $run->id . '?show=all')->assertOk()->assertSee('Wrong type in QuickBooks')->assertSee('Payments');
    }
}
