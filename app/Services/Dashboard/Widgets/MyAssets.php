<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\Asset;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;

/**
 * Company property currently in the viewer's name — what must be handed back at exit.
 * Own records only. There is no self-service asset page yet, so the card carries no
 * drill-down (a follow-up); HR sees the full register through "Assets held by team".
 */
final class MyAssets extends DashboardWidget
{
    private const CATEGORIES = [
        Asset::CATEGORY_IT_HARDWARE => 'IT hardware',
        Asset::CATEGORY_SIM_CARD => 'SIM card',
        Asset::CATEGORY_ACCESS_CARD => 'Access card',
        Asset::CATEGORY_SAFETY_GEAR => 'Safety gear',
        Asset::CATEGORY_KEYS => 'Keys',
        Asset::CATEGORY_VEHICLE => 'Vehicle',
        Asset::CATEGORY_OTHER => 'Other',
    ];

    public function key(): string
    {
        return 'me.assets';
    }

    public function title(): string
    {
        return 'My assets';
    }

    public function permissions(): array
    {
        return ['profile.own.view'];
    }

    public function personas(): array
    {
        return ['employee', 'line_manager', 'department_admin', 'hr_manager'];
    }

    public function section(): string
    {
        return self::SECTION_ME;
    }

    public function dashboard(): string
    {
        return self::DASHBOARD_EMPLOYEE;
    }

    public function priority(): int
    {
        return 40;
    }

    public function span(): int
    {
        return 12;
    }

    public function type(): string
    {
        return 'list';
    }

    public function ttl(): int
    {
        return 300;
    }

    public function data(User $viewer): array
    {
        $held = Asset::query()
            ->where('assignee_id', $this->id($viewer))
            ->where('status', Asset::STATUS_ASSIGNED);

        $total = (clone $held)->count();

        $items = $held->orderBy('assigned_date')->limit(5)->get(['id', 'asset_code', 'name', 'category', 'assigned_date'])
            ->map(fn (Asset $asset): array => $this->item(
                (string) $asset->id,
                (string) $asset->name,
                self::CATEGORIES[$asset->category] ?? 'Asset',
                $asset->assigned_date ? 'since '.$asset->assigned_date->format('j M Y') : null,
            ))
            ->all();

        return ['total' => $total, 'items' => $items];
    }
}
