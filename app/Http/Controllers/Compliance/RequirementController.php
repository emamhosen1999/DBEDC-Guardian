<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\ComplianceRequirement;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RequirementController extends Controller
{
    public function index(Request $request)
    {
        $query = ComplianceRequirement::query()->with('responsiblePerson');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('source', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhere('compliance_evidence', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('applicable')) {
            $query->where('applicable', $request->boolean('applicable'));
        }

        if ($request->filled('responsible_person_id')) {
            $query->where('responsible_person_id', $request->input('responsible_person_id'));
        }

        if ($request->boolean('evaluation_due')) {
            $query->whereDate('next_evaluation_date', '<=', now()->toDateString());
        }

        $requirements = $query->orderBy('updated_at', 'desc')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Compliance/Requirements/Index', [
            'requirements' => $requirements,
            'filters' => $request->only(['search', 'status', 'applicable', 'responsible_person_id', 'evaluation_due']),
            'statuses' => [
                ['id' => 'compliant', 'name' => 'Compliant'],
                ['id' => 'non_compliant', 'name' => 'Non-Compliant'],
                ['id' => 'partially_compliant', 'name' => 'Partially Compliant'],
                ['id' => 'in_progress', 'name' => 'In Progress'],
                ['id' => 'not_evaluated', 'name' => 'Not Evaluated'],
            ],
            'users' => User::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Compliance/Requirements/Create', [
            'statuses' => [
                ['id' => 'compliant', 'name' => 'Compliant'],
                ['id' => 'non_compliant', 'name' => 'Non-Compliant'],
                ['id' => 'partially_compliant', 'name' => 'Partially Compliant'],
                ['id' => 'in_progress', 'name' => 'In Progress'],
                ['id' => 'not_evaluated', 'name' => 'Not Evaluated'],
            ],
            'users' => User::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'source' => 'required|string|max:255',
            'reference_number' => 'nullable|string|max:100',
            'applicable' => 'nullable|boolean',
            'status' => 'required|in:compliant,non_compliant,partially_compliant,in_progress,not_evaluated',
            'compliance_evidence' => 'nullable|string',
            'responsible_person_id' => 'nullable|exists:users,id',
            'last_evaluation_date' => 'nullable|date',
            'next_evaluation_date' => 'nullable|date|after_or_equal:last_evaluation_date',
        ]);

        $validated['applicable'] = $request->boolean('applicable', true);

        ComplianceRequirement::create($validated);

        return redirect()->route('compliance.requirements.index')
            ->with('success', 'Requirement created successfully.');
    }

    public function show(ComplianceRequirement $requirement)
    {
        $requirement->load(['responsiblePerson', 'documents']);

        return Inertia::render('Compliance/Requirements/Show', [
            'requirement' => $requirement,
        ]);
    }

    public function edit(ComplianceRequirement $requirement)
    {
        return Inertia::render('Compliance/Requirements/Edit', [
            'requirement' => $requirement,
            'statuses' => [
                ['id' => 'compliant', 'name' => 'Compliant'],
                ['id' => 'non_compliant', 'name' => 'Non-Compliant'],
                ['id' => 'partially_compliant', 'name' => 'Partially Compliant'],
                ['id' => 'in_progress', 'name' => 'In Progress'],
                ['id' => 'not_evaluated', 'name' => 'Not Evaluated'],
            ],
            'users' => User::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, ComplianceRequirement $requirement)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'source' => 'required|string|max:255',
            'reference_number' => 'nullable|string|max:100',
            'applicable' => 'nullable|boolean',
            'status' => 'required|in:compliant,non_compliant,partially_compliant,in_progress,not_evaluated',
            'compliance_evidence' => 'nullable|string',
            'responsible_person_id' => 'nullable|exists:users,id',
            'last_evaluation_date' => 'nullable|date',
            'next_evaluation_date' => 'nullable|date|after_or_equal:last_evaluation_date',
        ]);

        $validated['applicable'] = $request->boolean('applicable');

        $requirement->update($validated);

        return redirect()->route('compliance.requirements.show', $requirement)
            ->with('success', 'Requirement updated successfully.');
    }

    public function destroy(ComplianceRequirement $requirement)
    {
        $requirement->delete();

        return redirect()->route('compliance.requirements.index')
            ->with('success', 'Requirement deleted successfully.');
    }
}
