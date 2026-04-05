<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\RiskAssessment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RiskAssessmentController extends Controller
{
    /**
     * Display a listing of risks.
     */
    public function index()
    {
        $risks = RiskAssessment::all();

        return Inertia::render('Compliance/Risks/Index', [
            'risks' => $risks,
        ]);
    }

    /**
     * Show the form for creating a new risk.
     */
    public function create()
    {
        return Inertia::render('Compliance/Risks/Create');
    }

    /**
     * Store a newly created risk in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            'level' => 'required|string',
            'status' => 'required|string',
        ]);

        RiskAssessment::create($validated);

        return redirect()->route('compliance.risks.index');
    }

    /**
     * Display the specified risk.
     */
    public function show(RiskAssessment $risk)
    {
        return Inertia::render('Compliance/Risks/Show', [
            'risk' => $risk,
        ]);
    }

    /**
     * Show the form for editing the specified risk.
     */
    public function edit(RiskAssessment $risk)
    {
        return Inertia::render('Compliance/Risks/Edit', [
            'risk' => $risk,
        ]);
    }

    /**
     * Update the specified risk in storage.
     */
    public function update(Request $request, RiskAssessment $risk)
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            'level' => 'required|string',
            'status' => 'required|string',
        ]);

        $risk->update($validated);

        return redirect()->route('compliance.risks.index');
    }

    /**
     * Remove the specified risk from storage.
     */
    public function destroy(RiskAssessment $risk)
    {
        $risk->delete();

        return redirect()->route('compliance.risks.index');
    }
}
