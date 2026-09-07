<?php

namespace App\Services\Operations;

use App\Models\OmWorkOrder;
use App\Models\OmWorkOrderMaterial;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OmInventoryIntegrationService
{
    /**
     * Get O&M materials inventory status, reorder levels, and consumption.
     */
    public function getInventoryOverview(): array
    {
        $hasInventoryItems = Schema::hasTable('inventory_items');
        $hasInventoryStocks = Schema::hasTable('inventory_stocks');

        if ($hasInventoryItems && $hasInventoryStocks) {
            $items = DB::table('inventory_items')
                ->leftJoin('inventory_stocks', 'inventory_items.id', '=', 'inventory_stocks.inventory_item_id')
                ->select([
                    'inventory_items.id',
                    'inventory_items.item_code',
                    'inventory_items.name',
                    'inventory_items.category',
                    'inventory_items.unit',
                    'inventory_items.cost_price',
                    'inventory_items.reorder_level',
                    DB::raw('COALESCE(SUM(inventory_stocks.quantity), 0) as current_stock'),
                ])
                ->groupBy([
                    'inventory_items.id',
                    'inventory_items.item_code',
                    'inventory_items.name',
                    'inventory_items.category',
                    'inventory_items.unit',
                    'inventory_items.cost_price',
                    'inventory_items.reorder_level',
                ])
                ->orderBy('inventory_items.name')
                ->get();
        } else {
            // Fallback to distinct materials recorded on work orders
            $items = OmWorkOrderMaterial::select([
                'item_name as name',
                'item_code',
                'unit',
                'unit_cost as cost_price',
                DB::raw('SUM(quantity_planned) as total_planned'),
                DB::raw('SUM(quantity_used) as current_stock'),
                DB::raw('10 as reorder_level'),
            ])
                ->groupBy(['item_name', 'item_code', 'unit', 'unit_cost'])
                ->get();
        }

        $totalItems = $items->count();
        $lowStockItems = $items->filter(fn ($item) => (float) $item->current_stock <= (float) $item->reorder_level)->values();
        $totalStockValue = $items->sum(fn ($item) => (float) $item->current_stock * (float) ($item->cost_price ?? 0));

        return [
            'total_material_skus' => $totalItems,
            'low_stock_count' => $lowStockItems->count(),
            'total_inventory_valuation' => round($totalStockValue, 2),
            'low_stock_items' => $lowStockItems,
            'all_materials' => $items,
        ];
    }

    /**
     * Log material consumption directly onto a work order (e.g. from Mobile or Web).
     */
    public function logMaterialConsumption(OmWorkOrder $workOrder, array $materialData): OmWorkOrderMaterial
    {
        $material = OmWorkOrderMaterial::create([
            'work_order_id' => $workOrder->id,
            'item_name' => $materialData['item_name'],
            'item_code' => $materialData['item_code'] ?? null,
            'unit' => $materialData['unit'] ?? 'Nos',
            'quantity_planned' => (float) ($materialData['quantity_planned'] ?? $materialData['quantity_used'] ?? 1),
            'quantity_used' => (float) ($materialData['quantity_used'] ?? 1),
            'unit_cost' => (float) ($materialData['unit_cost'] ?? 0),
            'total_cost' => (float) ($materialData['quantity_used'] ?? 1) * (float) ($materialData['unit_cost'] ?? 0),
        ]);

        // Auto-deduct from inventory_stocks if available
        if (Schema::hasTable('inventory_stocks') && ! empty($materialData['inventory_item_id'])) {
            DB::table('inventory_stocks')
                ->where('inventory_item_id', $materialData['inventory_item_id'])
                ->decrement('quantity', (float) $materialData['quantity_used']);
        }

        return $material;
    }
}
