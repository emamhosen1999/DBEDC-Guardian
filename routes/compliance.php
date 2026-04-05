<?php

use App\Http\Controllers\Compliance\AuditController;
use App\Http\Controllers\Compliance\ComplianceController;
use App\Http\Controllers\Compliance\CompliancePolicyController;
use App\Http\Controllers\Compliance\ComplianceTrainingRecordController;
use App\Http\Controllers\Compliance\ControlledDocumentController;
use App\Http\Controllers\Compliance\DocumentController;
use App\Http\Controllers\Compliance\RegulatoryRequirementController;
use App\Http\Controllers\Compliance\RequirementController;
use App\Http\Controllers\Compliance\RiskAssessmentController;
use Illuminate\Support\Facades\Route;

// Compliance Routes
Route::middleware(['auth', 'verified'])->prefix('compliance')->name('compliance.')->group(function () {
    // Compliance Dashboard
    Route::middleware(['permission:compliance.dashboard.view'])->get('/dashboard', [ComplianceController::class, 'index'])->name('dashboard');

    // Documents
    Route::middleware(['permission:compliance.documents.view'])->group(function () {
        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/create', [DocumentController::class, 'create'])->name('documents.create');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::get('/documents/{document}/edit', [DocumentController::class, 'edit'])->name('documents.edit');
        Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
    });

    Route::middleware(['permission:compliance.documents.create'])->post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::middleware(['permission:compliance.documents.update'])->put('/documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::middleware(['permission:compliance.documents.delete'])->delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    // Audits
    Route::middleware(['permission:compliance.audits.view'])->group(function () {
        Route::get('/audits', [AuditController::class, 'index'])->name('audits.index');
        Route::get('/audits/create', [AuditController::class, 'create'])->name('audits.create');
        Route::get('/audits/{audit}', [AuditController::class, 'show'])->name('audits.show');
        Route::get('/audits/{audit}/edit', [AuditController::class, 'edit'])->name('audits.edit');
    });

    Route::middleware(['permission:compliance.audits.create'])->post('/audits', [AuditController::class, 'store'])->name('audits.store');
    Route::middleware(['permission:compliance.audits.update'])->group(function () {
        Route::put('/audits/{audit}', [AuditController::class, 'update'])->name('audits.update');
        Route::post('/audits/{audit}/findings', [AuditController::class, 'storeFinding'])->name('audits.findings.store');
        Route::put('/findings/{finding}', [AuditController::class, 'updateFinding'])->name('findings.update');
        Route::delete('/findings/{finding}', [AuditController::class, 'destroyFinding'])->name('findings.destroy');
    });
    Route::middleware(['permission:compliance.audits.delete'])->delete('/audits/{audit}', [AuditController::class, 'destroy'])->name('audits.destroy');

    // Policies
    Route::middleware(['permission:compliance.policies.view'])->get('/policies', [CompliancePolicyController::class, 'index'])->name('policies.index');
    Route::middleware(['permission:compliance.policies.view'])->get('/policies/create', [CompliancePolicyController::class, 'create'])->name('policies.create');
    Route::middleware(['permission:compliance.policies.view'])->get('/policies/{policy}', [CompliancePolicyController::class, 'show'])->name('policies.show');
    Route::middleware(['permission:compliance.policies.view'])->get('/policies/{policy}/edit', [CompliancePolicyController::class, 'edit'])->name('policies.edit');
    Route::middleware(['permission:compliance.policies.create'])->post('/policies', [CompliancePolicyController::class, 'store'])->name('policies.store');
    Route::middleware(['permission:compliance.policies.update'])->put('/policies/{policy}', [CompliancePolicyController::class, 'update'])->name('policies.update');
    Route::middleware(['permission:compliance.policies.delete'])->delete('/policies/{policy}', [CompliancePolicyController::class, 'destroy'])->name('policies.destroy');

    // Regulatory Requirements
    Route::middleware(['permission:compliance.regulatory_requirements.view'])->get('/regulatory-requirements', [RegulatoryRequirementController::class, 'index'])->name('regulatory-requirements.index');
    Route::middleware(['permission:compliance.regulatory_requirements.view'])->get('/regulatory-requirements/create', [RegulatoryRequirementController::class, 'create'])->name('regulatory-requirements.create');
    Route::middleware(['permission:compliance.regulatory_requirements.view'])->get('/regulatory-requirements/{requirement}', [RegulatoryRequirementController::class, 'show'])->name('regulatory-requirements.show');
    Route::middleware(['permission:compliance.regulatory_requirements.view'])->get('/regulatory-requirements/{requirement}/edit', [RegulatoryRequirementController::class, 'edit'])->name('regulatory-requirements.edit');
    Route::middleware(['permission:compliance.regulatory_requirements.create'])->post('/regulatory-requirements', [RegulatoryRequirementController::class, 'store'])->name('regulatory-requirements.store');
    Route::middleware(['permission:compliance.regulatory_requirements.update'])->put('/regulatory-requirements/{requirement}', [RegulatoryRequirementController::class, 'update'])->name('regulatory-requirements.update');
    Route::middleware(['permission:compliance.regulatory_requirements.delete'])->delete('/regulatory-requirements/{requirement}', [RegulatoryRequirementController::class, 'destroy'])->name('regulatory-requirements.destroy');

    // Requirements
    Route::middleware(['permission:compliance.requirements.view'])->group(function () {
        Route::get('/requirements', [RequirementController::class, 'index'])->name('requirements.index');
        Route::get('/requirements/create', [RequirementController::class, 'create'])->name('requirements.create');
        Route::get('/requirements/{requirement}', [RequirementController::class, 'show'])->name('requirements.show');
        Route::get('/requirements/{requirement}/edit', [RequirementController::class, 'edit'])->name('requirements.edit');
    });

    Route::middleware(['permission:compliance.requirements.create'])->post('/requirements', [RequirementController::class, 'store'])->name('requirements.store');
    Route::middleware(['permission:compliance.requirements.update'])->put('/requirements/{requirement}', [RequirementController::class, 'update'])->name('requirements.update');
    Route::middleware(['permission:compliance.requirements.delete'])->delete('/requirements/{requirement}', [RequirementController::class, 'destroy'])->name('requirements.destroy');

    // Risks
    Route::middleware(['permission:compliance.risks.view'])->get('/risks', [RiskAssessmentController::class, 'index'])->name('risks.index');
    Route::middleware(['permission:compliance.risks.view'])->get('/risks/create', [RiskAssessmentController::class, 'create'])->name('risks.create');
    Route::middleware(['permission:compliance.risks.view'])->get('/risks/{risk}', [RiskAssessmentController::class, 'show'])->name('risks.show');
    Route::middleware(['permission:compliance.risks.view'])->get('/risks/{risk}/edit', [RiskAssessmentController::class, 'edit'])->name('risks.edit');
    Route::middleware(['permission:compliance.risks.create'])->post('/risks', [RiskAssessmentController::class, 'store'])->name('risks.store');
    Route::middleware(['permission:compliance.risks.update'])->put('/risks/{risk}', [RiskAssessmentController::class, 'update'])->name('risks.update');
    Route::middleware(['permission:compliance.risks.delete'])->delete('/risks/{risk}', [RiskAssessmentController::class, 'destroy'])->name('risks.destroy');

    // Training Records
    Route::middleware(['permission:compliance.training_records.view'])->get('/training-records', [ComplianceTrainingRecordController::class, 'index'])->name('training-records.index');
    Route::middleware(['permission:compliance.training_records.view'])->get('/training-records/create', [ComplianceTrainingRecordController::class, 'create'])->name('training-records.create');
    Route::middleware(['permission:compliance.training_records.view'])->get('/training-records/{trainingRecord}', [ComplianceTrainingRecordController::class, 'show'])->name('training-records.show');
    Route::middleware(['permission:compliance.training_records.view'])->get('/training-records/{trainingRecord}/edit', [ComplianceTrainingRecordController::class, 'edit'])->name('training-records.edit');
    Route::middleware(['permission:compliance.training_records.create'])->post('/training-records', [ComplianceTrainingRecordController::class, 'store'])->name('training-records.store');
    Route::middleware(['permission:compliance.training_records.update'])->put('/training-records/{trainingRecord}', [ComplianceTrainingRecordController::class, 'update'])->name('training-records.update');
    Route::middleware(['permission:compliance.training_records.delete'])->delete('/training-records/{trainingRecord}', [ComplianceTrainingRecordController::class, 'destroy'])->name('training-records.destroy');

    // Controlled Documents
    Route::middleware(['permission:compliance.controlled_documents.view'])->get('/controlled-documents', [ControlledDocumentController::class, 'index'])->name('controlled-documents.index');
    Route::middleware(['permission:compliance.controlled_documents.view'])->get('/controlled-documents/create', [ControlledDocumentController::class, 'create'])->name('controlled-documents.create');
    Route::middleware(['permission:compliance.controlled_documents.view'])->get('/controlled-documents/{document}', [ControlledDocumentController::class, 'show'])->name('controlled-documents.show');
    Route::middleware(['permission:compliance.controlled_documents.view'])->get('/controlled-documents/{document}/edit', [ControlledDocumentController::class, 'edit'])->name('controlled-documents.edit');
    Route::middleware(['permission:compliance.controlled_documents.create'])->post('/controlled-documents', [ControlledDocumentController::class, 'store'])->name('controlled-documents.store');
    Route::middleware(['permission:compliance.controlled_documents.update'])->put('/controlled-documents/{document}', [ControlledDocumentController::class, 'update'])->name('controlled-documents.update');
    Route::middleware(['permission:compliance.controlled_documents.delete'])->delete('/controlled-documents/{document}', [ControlledDocumentController::class, 'destroy'])->name('controlled-documents.destroy');
});
