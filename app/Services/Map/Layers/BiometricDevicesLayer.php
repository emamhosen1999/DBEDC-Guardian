<?php

namespace App\Services\Map\Layers;

use App\Models\User;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;
use Illuminate\Support\Facades\DB;

/** Biometric devices, placed at the work location they are linked to (the device's own `location` is a room label, not a position). */
final class BiometricDevicesLayer extends MapLayer
{
    /** A device is online when it checked in within this many minutes. */
    private const ONLINE_MINUTES = 10;

    public function key(): string
    {
        return 'workforce.biometric';
    }

    public function label(): string
    {
        return 'Biometric devices';
    }

    public function group(): string
    {
        return 'workforce';
    }

    public function geometry(): array
    {
        return [self::POINT];
    }

    public function permissions(): array
    {
        return ['attendance.settings'];
    }

    public function tables(): array
    {
        return ['biometric_devices', 'work_location_biometric_device', 'work_locations'];
    }

    public function fields(): array
    {
        return ['model' => 'Model', 'room' => 'Room', 'status' => 'Status', 'seen' => 'Last heartbeat', 'site' => 'Work location'];
    }

    public function icon(): string
    {
        return 'fingerprint';
    }

    protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void
    {
        $rows = DB::table('biometric_devices as d')
            ->join('work_location_biometric_device as p', 'p.biometric_device_id', '=', 'd.id')
            ->join('work_locations as w', 'w.id', '=', 'p.work_location_id')
            ->whereNull('w.deleted_at')->where('d.is_active', true)
            ->select('d.id', 'd.name', 'd.model', 'd.location', 'd.last_heartbeat_at', 'w.name as site', 'w.latitude', 'w.longitude')->get();
        $deviceIds = DB::table('biometric_devices')->where('is_active', true)->pluck('id');
        foreach ($deviceIds->diff($rows->pluck('id')) as $unplaced) {
            $out->skip();
        }
        foreach ($rows as $d) {
            if (! is_numeric($d->latitude) || ! is_numeric($d->longitude)) {
                $out->skip();

                continue;
            }
            if ($filter->search !== null && ! str_contains(mb_strtolower($d->name.' '.$d->site), mb_strtolower($filter->search))) {
                continue;
            }
            $online = $d->last_heartbeat_at !== null && now()->diffInMinutes($d->last_heartbeat_at, true) <= self::ONLINE_MINUTES;
            $out->add($this->feature('point', $d->id, (string) $d->name, [
                'model' => $d->model, 'room' => $d->location, 'status' => $online ? 'Online' : 'Offline',
                'seen' => $d->last_heartbeat_at ? substr((string) $d->last_heartbeat_at, 0, 16) : 'never', 'site' => $d->site,
            ], $online ? 'good' : 'crit', ['lat' => (float) $d->latitude, 'lng' => (float) $d->longitude]));
        }
    }
}
