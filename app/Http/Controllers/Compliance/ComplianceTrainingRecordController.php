<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\ComplianceTrainingRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ComplianceTrainingRecordController extends Controller
{
    /**
     * Display a listing of training records.
     */
    public function index()
    {
        $trainings = ComplianceTrainingRecord::all();

        return Inertia::render('Compliance/TrainingRecords/Index', [
            'trainings' => $trainings,
        ]);
    }

    /**
     * Show the form for creating a new training record.
     */
    public function create()
    {
        return Inertia::render('Compliance/TrainingRecords/Create');
    }

    /**
     * Store a newly created training record in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'training_title' => 'required|string',
            'training_description' => 'nullable|string',
            'training_type' => 'required|string',
            'training_date' => 'required|date',
        ]);

        ComplianceTrainingRecord::create($validated);

        return redirect()->route('compliance.training-records.index');
    }

    /**
     * Display the specified training record.
     */
    public function show(ComplianceTrainingRecord $trainingRecord)
    {
        return Inertia::render('Compliance/TrainingRecords/Show', [
            'training' => $trainingRecord,
        ]);
    }

    /**
     * Show the form for editing the specified training record.
     */
    public function edit(ComplianceTrainingRecord $trainingRecord)
    {
        return Inertia::render('Compliance/TrainingRecords/Edit', [
            'training' => $trainingRecord,
        ]);
    }

    /**
     * Update the specified training record in storage.
     */
    public function update(Request $request, ComplianceTrainingRecord $trainingRecord)
    {
        $validated = $request->validate([
            'training_title' => 'required|string',
            'training_description' => 'nullable|string',
            'training_type' => 'required|string',
            'training_date' => 'required|date',
        ]);

        $trainingRecord->update($validated);

        return redirect()->route('compliance.training-records.index');
    }

    /**
     * Remove the specified training record from storage.
     */
    public function destroy(ComplianceTrainingRecord $trainingRecord)
    {
        $trainingRecord->delete();

        return redirect()->route('compliance.training-records.index');
    }
}
