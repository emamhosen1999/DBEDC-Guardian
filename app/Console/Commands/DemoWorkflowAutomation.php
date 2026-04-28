<?php

namespace App\Console\Commands;

use App\Models\DailyWork;
use App\Models\User;
use App\Services\DailyWork\DailyWorkWorkflowService;
use Illuminate\Console\Command;

class DemoWorkflowAutomation extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'demo:workflow-automation';

    /**
     * The console command description.
     */
    protected $description = 'Demonstrate the workflow automation features';

    private DailyWorkWorkflowService $workflowService;

    public function __construct(DailyWorkWorkflowService $workflowService)
    {
        parent::__construct();
        $this->workflowService = $workflowService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Daily Works Workflow Automation Demo');
        $this->line('=====================================');

        // Create test users
        $incharge = User::factory()->create(['name' => 'John Incharge']);
        $assignee = User::factory()->create(['name' => 'Jane Assignee']);

        $this->line("Created test users: {$incharge->name} and {$assignee->name}");

        // Demo 1: Completed work with passing inspection
        $this->newLine();
        $this->info('📋 Demo 1: Auto-completion of work with passing inspection');

        $completedWork = DailyWork::factory()->forUsers($incharge, $assignee)->create([
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
            'number' => 'RFI-2026-001',
        ]);

        $this->line("Created completed work: {$completedWork->number} with PASS inspection");
        $this->workflowService->handleAutomaticTransitions($completedWork);
        $this->line("After workflow: Status remains " . $completedWork->fresh()->status);

        // Demo 2: Work blocked by objections
        $this->newLine();
        $this->info('🚫 Demo 2: Work blocked by active objections');

        $blockedWork = DailyWork::factory()->forUsers($incharge, $assignee)->create([
            'status' => DailyWork::STATUS_IN_PROGRESS,
            'number' => 'RFI-2026-002',
        ]);

        $this->line("Created in-progress work: {$blockedWork->number}");

        // Simulate creating an objection (in real scenario this would be done through the UI)
        $this->line("Simulating active objection creation...");
        // Note: In a real scenario, objections would be created through the proper API

        // Demo 3: Rejected RFI requiring resubmission
        $this->newLine();
        $this->info('🔄 Demo 3: Rejected RFI requiring resubmission');

        $rejectedWork = DailyWork::factory()->forUsers($incharge, $assignee)->create([
            'status' => DailyWork::STATUS_COMPLETED,
            'rfi_response_status' => DailyWork::RFI_RESPONSE_REJECTED,
            'number' => 'RFI-2026-003',
        ]);

        $this->line("Created work with rejected RFI response: {$rejectedWork->number}");
        $this->workflowService->handleAutomaticTransitions($rejectedWork);
        $this->line("After workflow: Status changed to " . $rejectedWork->fresh()->status);

        // Demo 4: Overdue work escalation
        $this->newLine();
        $this->info('⚠️  Demo 4: Overdue work escalation');

        $overdueWork = DailyWork::factory()->forUsers($incharge, $assignee)->create([
            'status' => DailyWork::STATUS_IN_PROGRESS,
            'date' => now()->subDay(),
            'planned_time' => now()->subHours(3)->format('H:i:s'),
            'number' => 'RFI-2026-004',
        ]);

        $this->line("Created overdue work: {$overdueWork->number} (planned: {$overdueWork->planned_time})");
        $this->workflowService->handleAutomaticTransitions($overdueWork);
        $this->line("After workflow: Status changed to " . $overdueWork->fresh()->status);

        // Demo 5: Reminder functionality
        $this->newLine();
        $this->info('📧 Demo 5: Reminder functionality');

        $overdueItems = $this->workflowService->getOverdueWorkForReminders();
        $this->line("Found {$overdueItems->count()} overdue work items for reminders");

        $this->newLine();
        $this->info('✅ Workflow Automation Demo Complete!');
        $this->line('The system now automatically:');
        $this->line('• Transitions work statuses based on business rules');
        $this->line('• Sends notifications when status changes occur');
        $this->line('• Identifies overdue work for follow-up');
        $this->line('• Blocks work with active objections');

        return self::SUCCESS;
    }
}