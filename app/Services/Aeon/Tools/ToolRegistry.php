<?php

declare(strict_types=1);

namespace App\Services\Aeon\Tools;

use App\Contracts\Ai\AeonToolContract;
use App\Services\Aeon\Data\AeonAccess;
use App\Services\Aeon\Data\QueryTool;

/**
 * Discovers, registers, and routes calls to all active Aeon tools.
 */
class ToolRegistry
{
    /**
     * Permissions that make a tool worth offering to a user (any-of). Tools absent from the map are
     * offered to everyone; each tool still re-checks its own action's permission on every call.
     *
     * @var array<string, array<int, string>>
     */
    private const VISIBLE_WITH = [
        'hrm_attendance' => ['attendance.own.view', 'attendance.view', 'attendance.settings', 'attendance.roster.manage', 'leave.own.view', 'leaves.own.view', 'leaves.view'],
        'petty_cash' => ['petty-cash.approve'],
        'quality_assurance' => ['quality.ncr.view', 'daily-works.view'],
        'asset_maintenance' => ['om.equipment.view', 'om.maintenance.view'],
        'expressway_intelligence' => ['om.dashboard.view', 'om.incidents.view', 'om.toll.manage', 'daily-works.view'],
    ];

    /** @var array<string, AeonToolContract> */
    private array $tools = [];

    public function __construct(
        QueryTool $queryTool,
        PrepareOperationTool $prepareOperationTool,
        NavigateTool $navigateTool,
        ExpresswayIntelligenceTool $expresswayTool,
        QualityAssuranceTool $qaTool,
        HumanResourcesTool $hrmTool,
        PettyCashTool $pettyCashTool,
        ExecutiveBriefingTool $executiveBriefingTool,
        AssetMaintenanceTool $assetMaintenanceTool,
        private ToolGate $gate,
        private AeonAccess $access
    ) {
        $this->register($queryTool);
        $this->register($prepareOperationTool);
        $this->register($navigateTool);
        $this->register($expresswayTool);
        $this->register($qaTool);
        $this->register($hrmTool);
        $this->register($pettyCashTool);
        $this->register($executiveBriefingTool);
        $this->register($assetMaintenanceTool);
    }

    public function register(AeonToolContract $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /**
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function declarations(): array
    {
        $out = [];
        foreach ($this->tools as $tool) {
            $out[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ];
        }

        return $out;
    }

    /**
     * Declarations of the tools this user may use: the model never learns about the others.
     *
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function declarationsFor(int|string|null $userId): array
    {
        return array_values(array_filter(
            $this->declarations(),
            fn (array $declaration) => $this->isAvailableTo($declaration['name'], $userId),
        ));
    }

    public function isAvailableTo(string $name, int|string|null $userId): bool
    {
        if (! isset($this->tools[$name])) {
            return false;
        }

        $actor = $this->gate->actor($userId);
        if ($actor === null) {
            return false;
        }

        if ($name === 'query_data') {
            return $this->access->tables($actor) !== [];
        }

        if ($name === 'executive_briefing') {
            $briefing = $this->tools['executive_briefing'] ?? null;

            return $briefing instanceof ExecutiveBriefingTool && $briefing->availableTo($actor);
        }

        return ! isset(self::VISIBLE_WITH[$name]) || $this->access->holdsAny($actor, self::VISIBLE_WITH[$name]);
    }

    public function find(string $name): ?AeonToolContract
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<string, AeonToolContract>
     */
    public function all(): array
    {
        return $this->tools;
    }
}
