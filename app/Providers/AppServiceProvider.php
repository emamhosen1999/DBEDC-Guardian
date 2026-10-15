<?php

namespace App\Providers;

use App\Console\Commands\DeepE2ECrudAudit;
use App\Contracts\Ai\AiProvider;
use App\Listeners\RecordAccessAudit;
use App\Models\OmAsset;
use App\Models\OmAssetConditionSurvey;
use App\Models\OmDefect;
use App\Models\OmEquipment;
use App\Models\OmIncident;
use App\Models\OmIncidentPhoto;
use App\Models\OmIncidentVehicle;
use App\Models\OmLaneClosurePermit;
use App\Models\OmPatrolShift;
use App\Models\OmShiftLog;
use App\Models\OmTollExemption;
use App\Models\OmTollRecord;
use App\Models\OmTollShiftAudit;
use App\Models\OmTrafficLog;
use App\Models\OmVmsMessage;
use App\Models\OmWorkOrder;
use App\Models\OmWorkOrderCrew;
use App\Models\OmWorkOrderMaterial;
use App\Models\PettyCashAuditLog;
use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
use App\Observers\OperationsRealtimeObserver;
use App\Observers\PettyCashRealtimeObserver;
use App\Services\Access\AccessAudit;
use App\Services\Access\DepartmentScope;
use App\Services\Aeon\AeonService;
use App\Services\Aeon\Data\AeonAccess;
use App\Services\Aeon\Data\QueryTool;
use App\Services\Aeon\Data\SchemaCatalog;
use App\Services\Aeon\IndexingService;
use App\Services\Aeon\Operations\FormSpecBuilder;
use App\Services\Aeon\Operations\OperationResolver;
use App\Services\Aeon\Operations\RulesIntrospector;
use App\Services\Aeon\Providers\GeminiProvider;
use App\Services\Aeon\Providers\OpenAiCompatProvider;
use App\Services\Aeon\RagService;
use App\Services\Aeon\Tools\NavigateTool;
use App\Services\Aeon\Tools\PrepareOperationTool;
use App\Services\Aeon\Tools\ToolRegistry;
use App\Services\Corridor\ChainageResolver;
use App\Services\DailyWork\DailyWorkVisibility;
use App\Services\FeatureFlagService;
use App\Services\Notification\NotificationChannelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so the per-request memo actually holds: several call sites
        // (config endpoint, sync kill switch) may resolve flags in one request
        // and CACHE_STORE is null in production, i.e. every miss hits the DB.
        $this->app->singleton(FeatureFlagService::class);

        // Per-request (and per-queue-job) memo of who-sees-whom. Scoped, not a
        // singleton, so a long-lived worker never serves a stale scope; it is also
        // dropped after every handled HTTP request (see boot()).
        $this->app->scoped(DepartmentScope::class);

        // The one daily-works visibility rule, with a per-request memo of who is a section in-charge / which department a person is in.
        $this->app->scoped(DailyWorkVisibility::class);

        // Per-request memo of notification registry rows (scoped for the same reason as DepartmentScope).
        $this->app->scoped(NotificationChannelResolver::class);

        // The access audit ledger carries its actor / plan-hash context while a command runs. A singleton, not scoped:
        // the Spatie-event subscriber is resolved once at boot and must share the instance the command writes its context to.
        $this->app->singleton(AccessAudit::class);

        // --- Aeon AI Assistant Engine Bindings ---
        $this->app->bind(AiProvider::class, function ($app) {
            $driver = (string) config('aeon.provider', 'gemini');

            return match ($driver) {
                'openai' => $app->make(OpenAiCompatProvider::class),
                default => $app->make(GeminiProvider::class),
            };
        });

        $this->app->singleton(SchemaCatalog::class);
        $this->app->scoped(AeonAccess::class);
        $this->app->scoped(QueryTool::class);
        $this->app->singleton(OperationResolver::class);
        $this->app->singleton(RulesIntrospector::class);
        $this->app->singleton(FormSpecBuilder::class);
        $this->app->singleton(PrepareOperationTool::class);
        $this->app->singleton(NavigateTool::class);
        $this->app->singleton(ToolRegistry::class);
        $this->app->singleton(RagService::class);
        $this->app->singleton(IndexingService::class);
        $this->app->singleton(AeonService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        Event::listen(RequestHandled::class, fn () => $this->app->forgetInstance(DepartmentScope::class));

        // Every role / permission attach and detach lands in access_audit_logs (needs permission.events_enabled).
        Event::subscribe(RecordAccessAudit::class);

        Model::preventLazyLoading(! $this->app->isProduction());

        // Its rollback cannot undo writes to production's MyISAM tables.
        DeepE2ECrudAudit::prohibit($this->app->isProduction());

        foreach ([
            OmAsset::class,
            OmAssetConditionSurvey::class,
            OmDefect::class,
            OmEquipment::class,
            OmIncident::class,
            OmIncidentPhoto::class,
            OmIncidentVehicle::class,
            OmLaneClosurePermit::class,
            OmPatrolShift::class,
            OmShiftLog::class,
            OmTollExemption::class,
            OmTollRecord::class,
            OmTollShiftAudit::class,
            OmTrafficLog::class,
            OmVmsMessage::class,
            OmWorkOrder::class,
            OmWorkOrderCrew::class,
            OmWorkOrderMaterial::class,
        ] as $operationsModel) {
            $operationsModel::observe(OperationsRealtimeObserver::class);
        }

        foreach ([PettyCashLoan::class, PettyCashTransaction::class, PettyCashAuditLog::class] as $pettyCashModel) {
            $pettyCashModel::observe(PettyCashRealtimeObserver::class);
        }

        // Share application version with all Inertia responses
        Inertia::share([
            'app' => [
                'version' => config('app.version'),
                'name' => config('app.name'),
            ],
        ]);

        Validator::extend('custom_location', function ($attribute, $value, $parameters, $validator) {
            // Format gate, unchanged: a chainage such as K05+900, K05+900-K06+400 or K30 (any letters before the K, text after).
            $chainageRegex = '/([A-Z]*K[0-9]+(?:\+[0-9]+(?:\.[0-9]+)?)?)-([A-Z]*K[0-9]+(?:\+[0-9]+(?:\.[0-9]+)?)?)|([A-Z]*K[0-9]+)(.*)/';

            if (! preg_match($chainageRegex, $value, $matches)) {
                $validator->errors()->add($attribute, 'DailyWork has an invalid location format: '.$value);

                return false; // Invalid format
            }
            $startChainage = $matches[1] === '' ? $matches[0] : $matches[1]; // e.g., K05+900 or K30
            $endChainage = $matches[2] === '' ? null : $matches[2]; // e.g., K06+400 (optional)

            // The jurisdiction check is numeric and as of the work's date (ChainageResolver), not a string comparison.
            $data = $validator->getData();
            $date = is_string($data['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}/', $data['date']) === 1 ? substr($data['date'], 0, 10) : null;
            if (app(ChainageResolver::class)->forTokens($startChainage, $endChainage, $date) === null) {
                $validator->errors()->add($attribute, 'The location must have a valid jurisdiction for the specified chainage: '.$value);

                return false; // Invalid jurisdiction
            }

            return true;
        });
    }
}
