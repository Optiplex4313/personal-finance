<?php

namespace Tests\Feature\Payments;

use App\Models\Expense;
use App\Services\RevolutImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesExpenses;
use Tests\TestCase;

class RevolutImportServiceTest extends TestCase
{
    use CreatesExpenses;
    use RefreshDatabase;

    private const HEADER = 'Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @param  array<int, string>  $rows
     */
    private function import(array $rows, bool $leaveEmpty = false, bool $allowDuplicates = false): array
    {
        $path = 'imports/revolut.csv';
        Storage::disk('local')->put($path, implode("\n", [self::HEADER, ...$rows]) . "\n");

        return app(RevolutImportService::class)->importCsv($path, $leaveEmpty, $allowDuplicates);
    }

    private function row(string $type, string $date, string $description, string $amount, string $fee = '0.00', string $currency = 'EUR', string $state = 'COMPLETED'): string
    {
        return implode(',', [$type, 'Current', $date, $date, $description, $amount, $fee, $currency, $state, '100.00']);
    }

    public function test_imports_card_payment_as_expense_with_single_item(): void
    {
        $summary = $this->import([
            $this->row('Card Payment', '2026-06-15 10:00:00', 'Mercadona', '-23.45'),
        ]);

        $this->assertSame(1, $summary['imported_count']);

        $expense = Expense::query()->with('establishment', 'items')->sole();
        $this->assertSame('23.45', $expense->total);
        $this->assertSame('Mercadona', $expense->establishment->name);
        $this->assertSame('23.45', $this->itemsTotal($expense));
        $this->assertFalse($expense->hasPendingSubexpenses());
    }

    public function test_started_date_is_read_as_local_time(): void
    {
        $this->import([
            $this->row('Card Payment', '2026-07-01 00:30:00', 'Bar', '-2.00'),
        ]);

        $date = Expense::query()->sole()->date;
        $this->assertTrue($date->equalTo(Carbon::parse('2026-06-30 22:30:00', 'UTC')));
    }

    public function test_outgoing_transfer_is_imported_and_incoming_is_skipped(): void
    {
        $summary = $this->import([
            $this->row('Transfer', '2026-06-15 10:00:00', 'Alquiler', '-650.00'),
            $this->row('Transfer', '2026-06-15 11:00:00', 'Nómina', '1800.00'),
        ]);

        $this->assertSame(1, $summary['imported_count']);
        $this->assertSame('650.00', Expense::query()->sole()->total);
    }

    public function test_positive_card_payment_is_not_imported_as_expense(): void
    {
        $summary = $this->import([
            $this->row('Card Payment', '2026-06-15 10:00:00', 'Amazon', '15.99'),
        ]);

        $this->assertSame(0, $summary['imported_count']);
        $this->assertSame(0, Expense::query()->count());
        $this->assertSame('refund', $summary['skipped'][0]['reason']);
    }

    public function test_only_completed_movements_are_imported(): void
    {
        $summary = $this->import([
            $this->row('Card Payment', '2026-06-15 10:00:00', 'A', '-1.00', state: 'COMPLETED'),
            $this->row('Card Payment', '2026-06-15 11:00:00', 'B', '-2.00', state: 'REVERTED'),
            $this->row('Card Payment', '2026-06-15 12:00:00', 'C', '-3.00', state: 'DECLINED'),
            $this->row('Card Payment', '2026-06-15 13:00:00', 'D', '-4.00', state: 'PENDING'),
            $this->row('Card Payment', '2026-06-15 14:00:00', 'E', '-5.00', state: 'FAILED'),
        ]);

        $this->assertSame(1, $summary['imported_count']);
        $this->assertSame(['1.00'], Expense::query()->pluck('total')->all());
        $this->assertSame(4, $summary['skipped_count']);
    }

    public function test_fee_is_added_to_total(): void
    {
        $this->import([
            $this->row('Card Payment', '2026-06-15 10:00:00', 'Hotel', '-100.00', fee: '1.50'),
        ]);

        $expense = Expense::query()->sole();
        $this->assertSame('101.50', $expense->total);
        $this->assertSame('101.50', $this->itemsTotal($expense));
    }

    public function test_foreign_currency_movements_are_skipped(): void
    {
        $summary = $this->import([
            $this->row('Card Payment', '2026-06-15 10:00:00', 'Tesco', '-10.00', currency: 'GBP'),
        ]);

        $this->assertSame(0, $summary['imported_count']);
        $this->assertSame('currency', $summary['skipped'][0]['reason']);
    }

    public function test_other_types_are_ignored(): void
    {
        $summary = $this->import([
            $this->row('Topup', '2026-06-15 10:00:00', 'Recarga', '50.00'),
            $this->row('Exchange', '2026-06-15 10:00:00', 'Cambio', '-50.00'),
        ]);

        $this->assertSame(0, $summary['imported_count']);
        $this->assertSame(0, Expense::query()->count());
    }

    public function test_amounts_are_exact_to_the_cent(): void
    {
        $this->import([
            $this->row('Card Payment', '2026-06-15 10:00:00', 'A', '-0.10', fee: '0.20'),
            $this->row('Card Payment', '2026-06-15 11:00:00', 'B', '-1.005'),
            $this->row('Card Payment', '2026-06-15 12:00:00', 'C', '"-1,234.56"'),
        ]);

        $this->assertEqualsCanonicalizing(['0.30', '1.01', '1234.56'], Expense::query()->pluck('total')->all());
    }

    public function test_two_identical_payments_on_the_same_day_are_both_imported(): void
    {
        $summary = $this->import([
            $this->row('Card Payment', '2026-06-15 09:00:00', 'Cafetería', '-1.50'),
            $this->row('Card Payment', '2026-06-15 17:00:00', 'Cafetería', '-1.50'),
        ]);

        $this->assertSame(2, $summary['imported_count']);
        $this->assertSame(0, $summary['skipped_duplicate_count']);
    }

    public function test_reimporting_the_same_csv_imports_nothing(): void
    {
        $rows = [
            $this->row('Card Payment', '2026-06-15 09:00:00', 'Cafetería', '-1.50'),
            $this->row('Card Payment', '2026-06-15 17:00:00', 'Cafetería', '-1.50'),
            $this->row('Card Payment', '2026-06-16 17:00:00', 'Lidl', '-30.00'),
        ];

        $this->import($rows);
        $second = $this->import($rows);

        $this->assertSame(0, $second['imported_count']);
        $this->assertSame(3, $second['skipped_duplicate_count']);
        $this->assertSame(3, Expense::query()->count());
    }

    public function test_manual_expense_absorbs_only_one_matching_payment(): void
    {
        $manual = $this->createExpense('1.50', Carbon::parse('2026-06-15 08:00:00', 'UTC'));

        $summary = $this->import([
            $this->row('Card Payment', '2026-06-15 09:00:00', 'Cafetería', '-1.50'),
            $this->row('Card Payment', '2026-06-15 17:00:00', 'Cafetería', '-1.50'),
        ]);

        $this->assertSame(1, $summary['imported_count']);
        $this->assertSame(1, $summary['skipped_duplicate_count']);
        $this->assertSame($manual->id, $summary['skipped_duplicates'][0]['manual_expense_id']);
    }

    public function test_duplicates_report_lists_every_skipped_duplicate(): void
    {
        $rows = [
            $this->row('Card Payment', '2026-06-15 09:00:00', 'Cafetería', '-1.50'),
        ];

        $this->import($rows);
        $summary = $this->import($rows);

        $report = json_decode(file_get_contents($summary['duplicates_report_path']), true);
        $this->assertSame(1, $report['total_skipped']);
        $this->assertSame('Cafetería', $report['items'][0]['description']);
        $this->assertSame('1.50', $report['items'][0]['total']);
    }

    public function test_allow_duplicates_imports_everything(): void
    {
        $rows = [
            $this->row('Card Payment', '2026-06-15 09:00:00', 'Cafetería', '-1.50'),
        ];

        $this->import($rows);
        $summary = $this->import($rows, allowDuplicates: true);

        $this->assertSame(1, $summary['imported_count']);
        $this->assertSame(2, Expense::query()->count());
    }

    public function test_leave_subexpenses_empty(): void
    {
        $this->import([
            $this->row('Card Payment', '2026-06-15 09:00:00', 'Cafetería', '-1.50'),
        ], leaveEmpty: true);

        $this->assertSame(0, Expense::query()->sole()->items()->count());
    }

    public function test_invalid_row_rolls_back_the_whole_import(): void
    {
        try {
            $this->import([
                $this->row('Card Payment', '2026-06-15 09:00:00', 'Cafetería', '-1.50'),
                $this->row('Card Payment', '2026-06-15 10:00:00', 'Roto', 'abc'),
            ]);
            $this->fail('La importación debía fallar.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Expense::query()->count());
    }

    public function test_missing_required_column_fails(): void
    {
        $path = 'imports/bad.csv';
        Storage::disk('local')->put($path, "Type,Description\nCard Payment,X\n");

        $this->expectException(RuntimeException::class);

        app(RevolutImportService::class)->importCsv($path);
    }
}
