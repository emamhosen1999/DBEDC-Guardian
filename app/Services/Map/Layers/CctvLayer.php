<?php

namespace App\Services\Map\Layers;

use App\Models\User;
use App\Models\WorkLocation;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The CCTV camera(s) with live status. The one registered camera watches the Traffic Management Center
 * floor, so it is placed at the TMC work location. Status is a 2 second reachability probe of the camera
 * gateway, cached for a minute so the dashboard poll never fans out to the camera.
 */
final class CctvLayer extends MapLayer
{
    private const STATUS_TTL = 60;

    public function key(): string
    {
        return 'om.cctv';
    }

    public function label(): string
    {
        return 'CCTV cameras';
    }

    public function group(): string
    {
        return 'om';
    }

    public function geometry(): array
    {
        return [self::POINT];
    }

    public function permissions(): array
    {
        return ['monitoring.camera.view'];
    }

    public function tables(): array
    {
        return ['work_locations'];
    }

    public function fields(): array
    {
        return ['status' => 'Status', 'latency' => 'Latency', 'scope' => 'Watches', 'checked' => 'Checked'];
    }

    public function route(): ?string
    {
        return 'om.camera';
    }

    public function icon(): string
    {
        return 'camera-video';
    }

    public function source(): string
    {
        return 'Camera gateway reachability probe (cached 60 s)';
    }

    protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void
    {
        $site = WorkLocation::query()->where('is_active', true)->whereNotNull('latitude')->whereNotNull('longitude')
            ->where(fn ($q) => $q->where('code', 'TMC')->orWhere('name', 'like', '%Traffic Management%'))->first();
        if ($site === null) {
            $out->skip();

            return;
        }
        $status = $this->status();
        $out->add($this->feature('point', 'tmc-main', 'TMC control room camera', [
            'status' => $status['online'] ? 'Online' : 'Offline',
            'latency' => $status['latency_ms'] !== null ? $status['latency_ms'].' ms' : null,
            'scope' => 'Monitoring Center floor, consoles 01 to 06',
            'checked' => substr($status['checked_at'], 11, 5),
        ], $status['online'] ? 'good' : 'crit', ['lat' => (float) $site->latitude, 'lng' => (float) $site->longitude]));
    }

    /** @return array{online: bool, latency_ms: ?int, checked_at: string} */
    private function status(): array
    {
        return Cache::remember('map:cctv:status', self::STATUS_TTL, function (): array {
            $start = microtime(true);
            try {
                $response = Http::timeout(2)->connectTimeout(2)->head((string) config('services.camera.public_url'));
                $online = $response->status() >= 200 && $response->status() < 500;
            } catch (\Throwable) {
                $online = false;
            }

            return ['online' => $online, 'latency_ms' => $online ? (int) round((microtime(true) - $start) * 1000) : null, 'checked_at' => now()->toIso8601String()];
        });
    }
}
