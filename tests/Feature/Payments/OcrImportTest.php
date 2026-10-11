<?php

namespace Tests\Feature\Payments;

use App\Enums\MovementType;
use App\Enums\OcrJobStatus;
use App\Jobs\ProcessOcrJob;
use App\Models\OcrJob;
use App\Models\User;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesExpenses;
use Tests\TestCase;

class OcrImportTest extends TestCase
{
    use CreatesExpenses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put('tickets/ticket.png', 'fake');
    }

    private function fakeOcr(array $items, string $establishment = 'Mercadona'): void
    {
        $response = json_encode([
            'establishment' => $establishment,
            'category' => 'Supermercado',
            'items' => $items,
        ]);

        Process::fake([
            '*' => Process::result(output: json_encode(['response' => $response, 'done' => true])),
        ]);
    }

    private function expenseWithTicket(string $total): \App\Models\Expense
    {
        // Se asigna después de crear: con la foto en el create, el observer ya lanza el OCR.
        $expense = $this->createExpense($total);
        $expense->forceFill(['ticket_photo_hash' => 'tickets/ticket.png'])->save();

        return $expense;
    }

    public function test_imports_items_and_balances_with_adjustment(): void
    {
        $this->fakeOcr([
            ['concept' => 'Leche', 'quantity' => 2, 'unit_price' => 1.10],
            ['concept' => 'Pan', 'quantity' => 1, 'unit_price' => 0.90],
        ]);
        $expense = $this->expenseWithTicket('4.00');

        $count = app(OcrService::class)->importExpenseItemsFromTicketOcr($expense);

        $this->assertSame(2, $count);
        $this->assertSame('4.00', $this->itemsTotal($expense));
        $adjustment = $expense->items()->where('movement_type', MovementType::ADJUSTMENT)->sole();
        $this->assertSame('0.90', $adjustment->line_total);
        $this->assertTrue($expense->fresh()->pending_review);
    }

    public function test_ocr_can_run_again_after_deleting_the_lines(): void
    {
        $this->fakeOcr([['concept' => 'Leche', 'quantity' => 1, 'unit_price' => 1.00]]);
        $expense = $this->expenseWithTicket('3.00');

        app(OcrService::class)->importExpenseItemsFromTicketOcr($expense);
        $expense->items()->where('movement_type', '!=', MovementType::ADJUSTMENT)->delete();

        $this->assertTrue($expense->fresh()->isOcrAvailable());

        app(OcrService::class)->importExpenseItemsFromTicketOcr($expense->fresh());

        $this->assertSame('3.00', $this->itemsTotal($expense));
        $this->assertSame(1, $expense->items()->where('movement_type', MovementType::ADJUSTMENT)->count());
    }

    public function test_refuses_to_overwrite_existing_lines(): void
    {
        $this->fakeOcr([['concept' => 'Leche', 'quantity' => 1, 'unit_price' => 1.00]]);
        $expense = $this->expenseWithTicket('3.00');
        $this->addItem($expense, '1', '3.00');

        $this->expectException(RuntimeException::class);

        app(OcrService::class)->importExpenseItemsFromTicketOcr($expense);
    }

    public function test_job_marks_ocr_job_completed(): void
    {
        $this->fakeOcr([['concept' => 'Leche', 'quantity' => 1, 'unit_price' => 1.00]]);
        $expense = $this->createExpense('1.00', attributes: ['ticket_photo_hash' => 'tickets/ticket.png']);
        $ocrJob = OcrJob::query()->sole();

        $this->assertSame(OcrJobStatus::COMPLETED, $ocrJob->status);
        $this->assertSame(1, $ocrJob->items_imported);
        $this->assertSame('1.00', $this->itemsTotal($expense));
    }

    public function test_job_marks_ocr_job_failed_when_expense_already_has_lines(): void
    {
        $this->fakeOcr([['concept' => 'Leche', 'quantity' => 1, 'unit_price' => 1.00]]);
        $user = User::factory()->create();
        $expense = $this->createExpense('1.00');
        $this->addItem($expense, '1', '1.00');
        $ocrJob = OcrJob::query()->create([
            'expense_id' => $expense->id,
            'user_id' => $user->id,
            'status' => OcrJobStatus::PENDING,
            'ticket_path' => 'tickets/ticket.png',
        ]);

        try {
            ProcessOcrJob::dispatchSync($ocrJob->id);
        } catch (RuntimeException) {
        }

        $ocrJob->refresh();
        $this->assertSame(OcrJobStatus::FAILED, $ocrJob->status);
        $this->assertStringContainsString('ya tiene líneas', $ocrJob->error_message);
    }

    public function test_job_failed_hook_marks_pending_ocr_job_as_failed(): void
    {
        $user = User::factory()->create();
        $expense = $this->createExpense('1.00');
        $ocrJob = OcrJob::query()->create([
            'expense_id' => $expense->id,
            'user_id' => $user->id,
            'status' => OcrJobStatus::PENDING,
            'ticket_path' => 'tickets/ticket.png',
        ]);

        (new ProcessOcrJob($ocrJob->id))->failed(new RuntimeException('Worker caído'));

        $ocrJob->refresh();
        $this->assertSame(OcrJobStatus::FAILED, $ocrJob->status);
        $this->assertSame('Worker caído', $ocrJob->error_message);
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_job_is_not_retried_and_has_a_timeout_above_ollama_timeout(): void
    {
        $job = new ProcessOcrJob('x');

        $this->assertSame(1, $job->tries);
        $this->assertGreaterThan((int) config('services.ollama.timeout'), $job->timeout);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.database.retry_after'));
    }
}
