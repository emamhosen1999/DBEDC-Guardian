<?php

declare(strict_types=1);

namespace App\Services\Aeon\Tools;

use App\Contracts\Ai\AeonToolContract;
use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\PettyCash\PettyCashService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Petty cash for approvers: the real advances (petty_cash_loans) and their expense, reimbursement and repayment
 * entries (petty_cash_transactions), limited to the employees the approver's department scope covers - the same
 * rows the petty cash admin page shows. Nothing here is estimated or illustrative; empty ledgers say so.
 */
class PettyCashTool implements AeonToolContract
{
    private const RECENT_LIMIT = 10;

    public function __construct(private ToolGate $gate, private DepartmentScope $scope) {}

    public function name(): string
    {
        return 'petty_cash';
    }

    public function description(): string
    {
        return 'Petty cash advances in your scope: outstanding balances, spending by expense category this month, advances waiting for approval, and the latest ledger entries.';
    }

    public function parameters(): array
    {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Petty cash action: "summary", "category_breakdown", "pending_approvals", "recent_transactions"',
                'enum' => ['summary', 'category_breakdown', 'pending_approvals', 'recent_transactions'],
            ],
        ];
    }

    public function run(array $args, int|string|null $userId): array
    {
        $action = (string) ($args['action'] ?? 'summary');

        // These are company ledgers: approvers only. Everyone else reads their own advances via query_data.
        if ($denied = $this->gate->deny($userId, ['petty-cash.approve'])) {
            return $denied;
        }
        $actor = $this->gate->actor($userId);

        return match ($action) {
            'category_breakdown' => $this->categoryBreakdown($actor),
            'pending_approvals' => $this->pendingApprovals($actor),
            'recent_transactions', 'recent_vouchers' => $this->recentTransactions($actor),
            default => $this->summary($actor),
        };
    }

    /** Advances whose holder the actor may see (DepartmentScope, as on the admin overview). */
    private function loans(User $actor): Builder
    {
        $query = PettyCashLoan::query();
        $this->scope->applyToEmployeeOwned($query, $actor, 'user_id');

        return $query;
    }

    private function transactions(User $actor): Builder
    {
        return PettyCashTransaction::query()->whereIn('petty_cash_loan_id', $this->loans($actor)->select('id'));
    }

    private function summary(User $actor): array
    {
        $active = $this->loans($actor)->where('status', 'active');
        $activeCount = (clone $active)->count();
        $advanced = (float) (clone $active)->sum('original_amount');
        $outstanding = (float) (clone $active)->sum('current_balance');
        $pending = $this->loans($actor)->where('status', 'pending_approval');
        $pendingCount = (clone $pending)->count();
        $pendingAmount = (float) (clone $pending)->sum('original_amount');
        $spentThisMonth = (float) $this->transactions($actor)->where('type', 'expense')
            ->whereDate('transaction_date', '>=', now()->startOfMonth()->toDateString())->whereDate('transaction_date', '<=', now()->toDateString())->sum('amount');

        if ($activeCount === 0 && $pendingCount === 0 && ! $this->loans($actor)->exists()) {
            return $this->empty('There are no petty cash advances in your scope yet.');
        }

        return [
            'text' => sprintf('Petty cash in your scope: %d active advance%s holding %s of %s advanced; %s spent this month; %d advance%s waiting for approval.',
                $activeCount, $activeCount === 1 ? '' : 's', $this->bdt($outstanding), $this->bdt($advanced), $this->bdt($spentThisMonth), $pendingCount, $pendingCount === 1 ? '' : 's'),
            'blocks' => [
                [
                    'type' => 'stats',
                    'items' => [
                        ['k' => 'Active advances', 'v' => (string) $activeCount, 'd' => $this->bdt($advanced).' advanced'],
                        ['k' => 'Balance still held', 'v' => $this->bdt($outstanding)],
                        ['k' => 'Spent this month', 'v' => $this->bdt($spentThisMonth), 'd' => now()->format('F Y')],
                        ['k' => 'Waiting for approval', 'v' => (string) $pendingCount, 'd' => $pendingCount > 0 ? $this->bdt($pendingAmount).' requested' : null],
                    ],
                ],
            ],
            'data' => [
                'active_count' => $activeCount,
                'advanced_bdt' => $advanced,
                'outstanding_bdt' => $outstanding,
                'spent_this_month_bdt' => $spentThisMonth,
                'pending_count' => $pendingCount,
                'pending_bdt' => $pendingAmount,
            ],
        ];
    }

    private function categoryBreakdown(User $actor): array
    {
        $from = now()->startOfMonth();
        $rows = $this->transactions($actor)->where('type', 'expense')
            ->whereDate('transaction_date', '>=', $from->toDateString())->whereDate('transaction_date', '<=', now()->toDateString())
            ->selectRaw('category, SUM(amount) as total')->groupBy('category')->orderByDesc('total')->get();

        if ($rows->isEmpty()) {
            return $this->empty('No petty cash expenses have been recorded in your scope in '.$from->format('F Y').'.');
        }

        $labels = PettyCashService::CATEGORIES;
        $items = $rows->map(fn ($r) => ['label' => $labels[$r->category] ?? ($r->category ? ucfirst(str_replace('_', ' ', (string) $r->category)) : 'Uncategorised'), 'value' => round((float) $r->total, 2)])->values()->all();

        return [
            'text' => 'Petty cash expenses by category, '.$from->format('F Y').' to date: '.$this->bdt((float) $rows->sum('total')).' in total.',
            'blocks' => [['type' => 'donut', 'title' => 'Expenses by category, '.$from->format('F Y'), 'items' => $items]],
            'data' => ['month' => $from->format('Y-m'), 'categories' => $items],
        ];
    }

    private function pendingApprovals(User $actor): array
    {
        $loans = $this->loans($actor)->where('status', 'pending_approval')->with('user:employee_id,name')->orderBy('loan_date')->get();

        if ($loans->isEmpty()) {
            return $this->empty('No petty cash advances are waiting for approval in your scope.');
        }

        return [
            'text' => $loans->count().' petty cash advance'.($loans->count() === 1 ? ' is' : 's are').' waiting for approval.',
            'blocks' => [[
                'type' => 'table',
                'columns' => ['Employee', 'Fund', 'Amount', 'Requested on'],
                'rows' => $loans->map(fn (PettyCashLoan $l) => [
                    $l->user?->name ?? (string) $l->user_id,
                    $l->fund_name ?: 'General fund',
                    $this->bdt((float) $l->original_amount),
                    $l->loan_date?->format('d M Y') ?? '—',
                ])->all(),
            ]],
            'data' => ['pending_count' => $loans->count(), 'pending_bdt' => (float) $loans->sum('original_amount')],
        ];
    }

    private function recentTransactions(User $actor): array
    {
        $entries = $this->transactions($actor)->with('pettyCashLoan.user:employee_id,name')
            ->orderByDesc('transaction_date')->orderByDesc('id')->limit(self::RECENT_LIMIT)->get();

        if ($entries->isEmpty()) {
            return $this->empty('No petty cash entries have been recorded in your scope yet.');
        }

        $labels = PettyCashService::CATEGORIES;

        return [
            'text' => 'The latest '.$entries->count().' petty cash entries in your scope.',
            'blocks' => [[
                'type' => 'table',
                'columns' => ['Date', 'Employee', 'Entry', 'Category', 'Amount'],
                'rows' => $entries->map(fn (PettyCashTransaction $t) => [
                    $t->transaction_date?->format('d M Y') ?? '—',
                    $t->pettyCashLoan?->user?->name ?? '—',
                    ucfirst(str_replace('_', ' ', (string) $t->type)),
                    $t->category ? ($labels[$t->category] ?? (string) $t->category) : '—',
                    $this->bdt((float) $t->amount),
                ])->all(),
            ]],
            'data' => ['count' => $entries->count()],
        ];
    }

    private function bdt(float $amount): string
    {
        return '৳ '.number_format($amount, 0);
    }

    /** @return array{text: string, blocks: array<int, mixed>, data: array<string, mixed>} */
    private function empty(string $message): array
    {
        return ['text' => $message, 'blocks' => [], 'data' => ['empty' => true]];
    }
}
