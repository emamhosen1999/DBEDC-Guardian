<?php

namespace App\Providers;

use App\Contracts\Ai\AiProvider;
use App\Models\Jurisdiction;
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
use App\Services\Aeon\AeonService;
use App\Services\Aeon\Data\QueryTool;
use App\Services\Aeon\Data\RowScope;
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
use App\Services\FeatureFlagService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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

        // --- Aeon AI Assistant Engine Bindings ---
        $this->app->bind(AiProvider::class, function ($app) {
            $driver = (string) config('aeon.provider', 'gemini');

            return match ($driver) {
                'openai' => $app->make(OpenAiCompatProvider::class),
                default => $app->make(GeminiProvider::class),
            };
        });

        $this->app->singleton(SchemaCatalog::class);
        $this->app->singleton(RowScope::class);
        $this->app->singleton(QueryTool::class);
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

        Model::preventLazyLoading(! $this->app->isProduction());

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
            // Regex for validating chainage format with optional text afterwards
            $chainageRegex = '/([A-Z]*K[0-9]+(?:\+[0-9]+(?:\.[0-9]+)?)?)-([A-Z]*K[0-9]+(?:\+[0-9]+(?:\.[0-9]+)?)?)|([A-Z]*K[0-9]+)(.*)/';

            // Check if the location value matches the chainage format
            if (! preg_match($chainageRegex, $value, $matches)) {
                $validator->errors()->add($attribute, 'DailyWork has an invalid location format: '.$value);

                return false; // Invalid format
            } else {
                // Extract start and end chainages, if available
                $startChainage = $matches[1] === '' ? $matches[0] : $matches[1]; // e.g., K05+900 or K30
                $endChainage = $matches[2] === '' ? null : $matches[2]; // e.g., K06+400 (optional)

                // Convert chainages to a comparable string format for jurisdiction check
                $startChainageFormatted = $this->formatChainage($startChainage);
                $endChainageFormatted = $endChainage ? $this->formatChainage($endChainage) : null;
            }

            // Retrieve all jurisdictions
            $jurisdictions = Jurisdiction::all();

            // Check for jurisdiction based on the formatted chainages
            $jurisdictionFound = false;
            Log::info($matches);
            Log::info('Chainage: '.$startChainageFormatted.'-'.($endChainageFormatted ?? 'N/A'));

            foreach ($jurisdictions as $jurisdiction) {
                $formattedStartJurisdiction = $this->formatChainage($jurisdiction->start_chainage);
                $formattedEndJurisdiction = $this->formatChainage($jurisdiction->end_chainage);

                // Check if the start chainage is within the jurisdiction's range
                if ($startChainageFormatted >= $formattedStartJurisdiction && $startChainageFormatted <= $formattedEndJurisdiction) {
                    Log::info('Jurisdiction: '.$formattedStartJurisdiction.'-'.$formattedEndJurisdiction);
                    $jurisdictionFound = true;
                    break; // Stop checking once a match is found
                }

                // If an end chainage exists, check if it's within the jurisdiction's range
                if ($endChainageFormatted &&
                    $endChainageFormatted >= $formattedStartJurisdiction &&
                    $endChainageFormatted <= $formattedEndJurisdiction) {
                    Log::info('Jurisdiction: '.$formattedStartJurisdiction.'-'.$formattedEndJurisdiction);
                    $jurisdictionFound = true;
                    break; // Stop checking once a match is found
                }
            }

            // If no jurisdiction is found, add an error
            if (! $jurisdictionFound) {
                $validator->errors()->add($attribute, 'The location must have a valid jurisdiction for the specified chainage: '.$value);

                return false; // Invalid jurisdiction
            }

            return true; // Return true if valid and jurisdiction exists
        });
    }

    private function formatChainage($chainage)
    {
        // Check if the chainage includes a range or just a single chainage
        if (preg_match('/([A-Z]*K)([0-9]+)(?:\+([0-9]+(?:\.[0-9]+)?))?/', $chainage, $matches)) {
            $kilometers = $matches[2]; // e.g., '14' from 'K14'
            $meters = $matches[3] ?? '000'; // Default to '0' if no meters are provided

            // Return a numeric format: kilometers and meters (with decimal if present)
            return $kilometers.$meters; // Remove decimal for sorting
        }

        // If the chainage is just a single K and number (e.g., 'K30'), format accordingly
        if (preg_match('/([A-Z]*K)([0-9]+)/', $chainage, $matches)) {
            $kilometers = $matches[2]; // e.g., '30'

            return $kilometers.'000'; // Assume no meters are present
        }

        return $chainage; // Return the chainage unchanged if it doesn't match the expected format
    }
}
