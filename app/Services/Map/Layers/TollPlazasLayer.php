<?php

namespace App\Services\Map\Layers;

use App\Models\User;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;
use Illuminate\Support\Facades\DB;

/**
 * Toll activity aggregated per plaza: transactions and revenue, exemptions, shift audits and WIM overloads
 * in the window, drawn on the toll-plaza structure whose name matches the plaza name in the register.
 * A plaza name that matches no surveyed plaza is counted as unplaced, never positioned by guess.
 */
final class TollPlazasLayer extends MapLayer
{
    public function key(): string
    {
        return 'tolls.plazas';
    }

    public function label(): string
    {
        return 'Toll activity';
    }

    public function group(): string
    {
        return 'tolls';
    }

    public function geometry(): array
    {
        return [self::POINT];
    }

    public function permissions(): array
    {
        return ['om.toll.view'];
    }

    public function tables(): array
    {
        return ['om_toll_records', 'om_toll_exemptions', 'om_toll_shift_audits', 'om_wim_fatigue_logs'];
    }

    public function fields(): array
    {
        return ['transactions' => 'Transactions', 'revenue' => 'Revenue', 'exemptions' => 'Exemptions', 'audits' => 'Shift audits', 'overloads' => 'WIM records'];
    }

    public function timeMode(): string
    {
        return self::TIME_PERIOD;
    }

    public function route(): ?string
    {
        return 'om.toll-operations.audit';
    }

    public function icon(): string
    {
        return 'currency-exchange';
    }

    protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void
    {
        $from = $filter->from->toDateString();
        $to = $filter->to->addDay()->toDateString();
        $agg = [];
        $bump = function (?string $plaza, string $key, int|float $n) use (&$agg): void {
            $name = trim((string) $plaza);
            if ($name !== '') {
                $agg[$name][$key] = ($agg[$name][$key] ?? 0) + $n;
            }
        };

        foreach (DB::table('om_toll_records')->where('transacted_at', '>=', $from)->where('transacted_at', '<', $to)->select('plaza_name', DB::raw('COUNT(*) n'), DB::raw('SUM(amount) a'))->groupBy('plaza_name')->get() as $r) {
            $bump($r->plaza_name, 'transactions', (int) $r->n);
            $bump($r->plaza_name, 'revenue', (float) $r->a);
        }
        foreach (DB::table('om_toll_exemptions')->where('passed_at', '>=', $from)->where('passed_at', '<', $to)->select('plaza_name', DB::raw('COUNT(*) n'))->groupBy('plaza_name')->get() as $r) {
            $bump($r->plaza_name, 'exemptions', (int) $r->n);
        }
        foreach (DB::table('om_toll_shift_audits')->where('shift_date', '>=', $from)->where('shift_date', '<', $to)->select('plaza_name', DB::raw('COUNT(*) n'))->groupBy('plaza_name')->get() as $r) {
            $bump($r->plaza_name, 'audits', (int) $r->n);
        }
        foreach (DB::table('om_wim_fatigue_logs')->where('log_date', '>=', $from)->where('log_date', '<', $to)->select('toll_plaza', DB::raw('COUNT(*) n'))->groupBy('toll_plaza')->get() as $r) {
            $bump($r->toll_plaza, 'overloads', (int) $r->n);
        }

        $plazas = array_values(array_filter($geometry->features, fn (array $f): bool => $f['kind'] === 'toll_plaza'));
        foreach ($agg as $name => $v) {
            $hit = null;
            foreach ($plazas as $p) {
                if (str_contains(mb_strtolower($p['name']), mb_strtolower($name)) || str_contains(mb_strtolower($name), mb_strtolower($p['name']))) {
                    $hit = $p;
                    break;
                }
            }
            if ($hit === null) {
                $out->skip();

                continue;
            }
            $out->add($this->feature('point', $name, $name, [
                'transactions' => isset($v['transactions']) ? number_format($v['transactions']) : null,
                'revenue' => isset($v['revenue']) ? 'BDT '.number_format($v['revenue']) : null,
                'exemptions' => $v['exemptions'] ?? null, 'audits' => $v['audits'] ?? null, 'overloads' => $v['overloads'] ?? null,
            ], 'theme', ['lat' => $hit['lat'], 'lng' => $hit['lng'], 'chainage_m' => $hit['chainage_m']]));
        }
    }
}
