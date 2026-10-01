<?php

namespace Tests\Feature\Access;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * CANARY LEAK SCAN. Department D2 carries a unique marker in every text field of every record kind. A
 * Department Admin of D1 then walks EVERY GET route he can reach — web (HTML with Inertia props, and JSON)
 * and /api/v1 with a Sanctum token — and the marker must never appear in any response body, export
 * included. Routes with parameters are driven with D2 ids and must answer 403/404 with no D2 data, and
 * identically for "exists" and "does not exist" (no existence oracle).
 */
class DepartmentAdminLeakScanTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    /** URIs never walked: device protocols, websocket auth, session mutators that happen to be GET. */
    /** 500s every actor gets in this sqlite sandbox (missing Stripe key / tenancy tables / Fortify views / MySQL-only analytics). */
    private const ENVIRONMENT_5XX = ['#^/?stripe/#', '#^/?tenancy/#', '#^/?verify-email#', '#^/?user/confirm-password#', '#^/?leaves/analytics$#', '#^/?attendance/export/pdf$#', '#^/?profile/[^/]+/stats$#'];

    private const SKIP = ['#^iclock/#', '#^broadcasting/#', '#^sanctum/#', '#^logout$#', '#^_ignition#', '#^up$#', '#^horizon#', '#^telescope#', '#^pulse#'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** @return array<int, RoutingRoute> */
    private function getRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => in_array('GET', $route->methods(), true))
            ->reject(fn (RoutingRoute $route) => collect(self::SKIP)->contains(fn ($pattern) => preg_match($pattern, $route->uri())))
            ->values()
            ->all();
    }

    private function fill(RoutingRoute $route, string $value): string
    {
        $uri = preg_replace('/\{[^}]+\?\}/', '', $route->uri());   // optional segments dropped

        return '/'.trim(preg_replace('/\{[^}]+\}/', $value, $uri), '/');
    }

    /** Plain text of whatever the response carries (HTML/JSON/stream/file/xlsx). */
    private function bodyOf($response): string
    {
        $base = $response->baseResponse;
        try {
            if ($base instanceof BinaryFileResponse) {
                $raw = (string) file_get_contents($base->getFile()->getPathname());
            } elseif ($base instanceof StreamedResponse) {
                $raw = $response->streamedContent();
            } else {
                $raw = (string) $response->getContent();
            }
        } catch (\Throwable) {
            return '';
        }

        if (str_starts_with($raw, 'PK')) {                      // xlsx / docx / zip: read the entries
            $path = tempnam(sys_get_temp_dir(), 'scan');
            file_put_contents($path, $raw);
            $zip = new \ZipArchive;
            $text = '';
            if ($zip->open($path) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $text .= $zip->getFromIndex($i);
                }
                $zip->close();
            }
            @unlink($path);

            return $text;
        }

        return $raw;
    }

    private function leaks(string $body): bool
    {
        return stripos($body, self::MARKER) !== false
            || stripos(urldecode($body), self::MARKER) !== false
            || stripos(html_entity_decode($body), self::MARKER) !== false
            || stripos(str_replace(['\\u002d', '\\/'], ['-', '/'], $body), self::MARKER) !== false;
    }

    private function probe(string $uri, bool $json, bool $api): TestResponse
    {
        if ($api) {
            Sanctum::actingAs($this->admin, ['*']);
        } else {
            $this->actingAs($this->admin);
        }

        return $json || $api ? $this->getJson($uri) : $this->get($uri);
    }

    public function test_no_reachable_get_route_leaks_the_canary(): void
    {
        $problems = [];
        $walked = 0;

        foreach ($this->getRoutes() as $route) {
            $api = str_starts_with($route->uri(), 'api/');
            $hasParams = str_contains($route->uri(), '{');
            // parameter-free routes as-is; routes with parameters filled with D1 ids (the in-scope read)
            $uri = $hasParams ? $this->fill($route, (string) $this->e1->employee_id) : '/'.ltrim($route->uri(), '/');

            foreach ([false, true] as $json) {
                try {
                    $response = $this->probe($uri, $json, $api);
                } catch (\Throwable $e) {
                    $problems[] = "EXCEPTION {$uri}: ".substr($e->getMessage(), 0, 120);

                    continue;
                }
                $walked++;
                if ($this->leaks($this->bodyOf($response))) {
                    $problems[] = 'LEAK '.($json ? 'json' : 'html')." GET {$uri} -> {$response->getStatusCode()}";
                }
                if ($response->getStatusCode() >= 500 && ! collect(self::ENVIRONMENT_5XX)->contains(fn ($p) => preg_match($p, $uri))) {
                    $problems[] = "5xx GET {$uri} -> {$response->getStatusCode()}";
                }
            }
        }

        $this->assertGreaterThan(100, $walked, 'the scan actually walked the route table');
        $this->assertSame([], array_slice(array_values(array_unique($problems)), 0, 60), count($problems).' problem(s)');
    }

    public function test_parameterised_routes_with_d2_ids_never_reveal_d2_data_or_its_existence(): void
    {
        $problems = [];

        foreach ($this->getRoutes() as $route) {
            if (! str_contains($route->uri(), '{')) {
                continue;
            }
            $api = str_starts_with($route->uri(), 'api/');
            foreach ([$this->d2Ids['user'], $this->d2Ids['leave'], $this->d2Ids['asset'], $this->d2Ids['designation']] as $d2Id) {
                $real = $this->probe($this->fill($route, $d2Id), true, $api);
                $none = $this->probe($this->fill($route, '99999'), true, $api);

                if ($this->leaks($this->bodyOf($real))) {
                    $problems[] = "LEAK GET {$route->uri()} with {$d2Id}";
                }
                if ($real->getStatusCode() !== $none->getStatusCode()) {
                    $problems[] = "ORACLE GET {$route->uri()}: exists={$real->getStatusCode()} missing={$none->getStatusCode()}";
                }
                if (($real->getStatusCode() >= 500 || $none->getStatusCode() >= 500) && ! collect(self::ENVIRONMENT_5XX)->contains(fn ($p) => preg_match($p, $route->uri()))) {
                    $problems[] = "5xx GET {$route->uri()}";
                }
            }
        }

        $this->assertSame([], array_slice(array_values(array_unique($problems)), 0, 80), count(array_unique($problems)).' problem(s)');
    }

    public function test_search_terms_and_department_filters_for_d2_never_widen_a_response(): void
    {
        $query = '?department_id=90001&department=90001&dept=90001&user_id=90001&employee_id=90001&employee=Zed&q=Zed&search=Zed&date='.self::DAY;
        $problems = [];
        foreach ($this->getRoutes() as $route) {
            if (str_contains($route->uri(), '{')) {
                continue;
            }
            $api = str_starts_with($route->uri(), 'api/');
            $response = $this->probe('/'.ltrim($route->uri(), '/').$query, true, $api);
            if ($this->leaks($this->bodyOf($response))) {
                $problems[] = "LEAK GET {$route->uri()} with D2 filters";
            }
        }
        $this->assertSame([], array_slice(array_unique($problems), 0, 40));
    }

    public function test_no_response_ever_carries_a_password_hash_or_secret(): void
    {
        $problems = [];
        foreach ($this->getRoutes() as $route) {
            $api = str_starts_with($route->uri(), 'api/');
            $uri = str_contains($route->uri(), '{') ? $this->fill($route, (string) $this->e1->employee_id) : '/'.ltrim($route->uri(), '/');
            $body = $this->bodyOf($this->probe($uri, true, $api));
            if (preg_match('/\$2y\$\d\d\$[.\/A-Za-z0-9]{53}/', $body) || preg_match('/"(two_factor_secret|two_factor_recovery_codes|remember_token|device_secret|adms_token|auth_token)":"[^"]+/', $body)) {
                $problems[] = "SECRET GET {$uri}";
            }
        }
        $this->assertSame([], array_slice(array_unique($problems), 0, 40));
    }
}
