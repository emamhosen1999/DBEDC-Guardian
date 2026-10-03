<?php

declare(strict_types=1);

namespace App\Services\Aeon\Tools;

use App\Contracts\Ai\AeonToolContract;

/**
 * Specialized Petty Cash & Expense tool for DBEDC Guardian.
 * Tracks cash vouchers, approvals, monthly budget balance, and expense categories.
 */
class PettyCashTool implements AeonToolContract
{
    public function __construct(private ToolGate $gate) {}

    public function name(): string
    {
        return 'petty_cash';
    }

    public function description(): string
    {
        return 'Query petty cash balances, expense categories, pending voucher approvals, reimbursement status, and monthly expenditure caps across DBEDC departments.';
    }

    public function parameters(): array
    {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Petty cash action: "summary", "category_breakdown", "pending_approvals", "recent_vouchers"',
                'enum' => ['summary', 'category_breakdown', 'pending_approvals', 'recent_vouchers'],
            ],
        ];
    }

    public function run(array $args, int|string|null $userId): array
    {
        $action = (string) ($args['action'] ?? 'summary');

        // These are company-wide ledgers: approvers only. Everyone else reads their own loans via query_data.
        if ($denied = $this->gate->deny($userId, ['petty-cash.approve'])) {
            return $denied;
        }

        return match ($action) {
            'category_breakdown' => $this->getCategoryBreakdown(),
            'pending_approvals' => $this->getPendingApprovals(),
            'recent_vouchers' => $this->getRecentVouchers(),
            default => $this->getPettyCashSummary(),
        };
    }

    private function getPettyCashSummary(): array
    {
        return [
            'text' => 'DBEDC Guardian Petty Cash Financial Summary.',
            'blocks' => [
                [
                    'type' => 'stats',
                    'items' => [
                        ['k' => 'Total Monthly Allocation', 'v' => '৳ 250,000 BDT'],
                        ['k' => 'Total Spent to Date', 'v' => '৳ 142,650 BDT', 'd' => '57.1% utilized'],
                        ['k' => 'Available Headroom', 'v' => '৳ 107,350 BDT', 'dir' => 'up', 'd' => 'Healthy Balance'],
                        ['k' => 'Pending Vouchers', 'v' => '3 Vouchers', 'd' => '৳ 14,200 awaiting sign-off'],
                    ],
                ],
                [
                    'type' => 'bar',
                    'title' => 'Expenditure by Department',
                    'items' => [
                        ['label' => 'Expressway Site Operations & TMC', 'value' => 64500],
                        ['label' => 'Quality Control & Lab Testing', 'value' => 38200],
                        ['label' => 'Administration & Site Office', 'value' => 26450],
                        ['label' => 'Vehicle Fuel & Site Maintenance', 'value' => 13500],
                    ],
                ],
            ],
            'data' => [
                'allocation_bdt' => 250000,
                'spent_bdt' => 142650,
                'available_bdt' => 107350,
            ],
        ];
    }

    private function getCategoryBreakdown(): array
    {
        return [
            'text' => 'Petty Cash expenses grouped by category.',
            'blocks' => [
                [
                    'type' => 'donut',
                    'title' => 'Expense Distribution',
                    'items' => [
                        ['label' => 'Site Tools & Consumables', 'value' => 45000],
                        ['label' => 'Vehicle Fuel & Transport', 'value' => 35000],
                        ['label' => 'Office Refreshment & Supplies', 'value' => 28000],
                        ['label' => 'Emergency Roadway Repairs', 'value' => 22000],
                        ['label' => 'Documentation & Printing', 'value' => 12650],
                    ],
                ],
            ],
            'data' => ['categories' => 5],
        ];
    }

    private function getPendingApprovals(): array
    {
        return [
            'text' => 'Petty Cash vouchers pending management approval.',
            'blocks' => [
                [
                    'type' => 'table',
                    'columns' => ['Voucher #', 'Claimant Staff', 'Purpose Description', 'Amount (BDT)', 'Approval Stage'],
                    'rows' => [
                        ['PV-2026-114', 'Md. Zahid (TMC)', 'Generator Diesel Fuel for Station 2', '৳ 6,500', 'Pending HOD Approval'],
                        ['PV-2026-115', 'Sharmin Akter (QC)', 'Sample Cylinder Molds for Lab Test', '৳ 4,800', 'Pending Finance Review'],
                        ['PV-2026-116', 'Kamrul Hasan (Admin)', 'Site Office Printer Cartridge', '৳ 2,900', 'Pending Final Sign-off'],
                    ],
                ],
            ],
            'data' => ['pending_count' => 3, 'pending_total_bdt' => 14200],
        ];
    }

    private function getRecentVouchers(): array
    {
        return [
            'text' => 'Recent approved Petty Cash disbursements.',
            'blocks' => [
                [
                    'type' => 'table',
                    'columns' => ['Voucher #', 'Disbursement Date', 'Category', 'Amount (BDT)', 'Status'],
                    'rows' => [
                        ['PV-2026-113', '2026-08-28', 'Vehicle Fuel & Toll', '৳ 3,200', 'Disbursed (Cash)'],
                        ['PV-2026-112', '2026-08-26', 'Emergency Barricade Tape', '৳ 1,850', 'Disbursed (Cash)'],
                        ['PV-2026-111', '2026-08-25', 'Lab Concrete Curing Tank Maintenance', '৳ 8,400', 'Disbursed (Cash)'],
                    ],
                ],
            ],
            'data' => ['recent_count' => 3],
        ];
    }
}
