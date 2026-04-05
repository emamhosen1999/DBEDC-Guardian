<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\ControlledDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlledDocumentController extends Controller
{
    /**
     * Display a listing of controlled documents.
     */
    public function index()
    {
        $documents = ControlledDocument::all();

        return Inertia::render('Compliance/ControlledDocuments/Index', [
            'documents' => $documents,
        ]);
    }

    /**
     * Show the form for creating a new controlled document.
     */
    public function create()
    {
        return Inertia::render('Compliance/ControlledDocuments/Create');
    }

    /**
     * Store a newly created controlled document in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'document_name' => 'required|string',
            'description' => 'nullable|string',
            'version' => 'required|string',
            'status' => 'required|string',
        ]);

        ControlledDocument::create($validated);

        return redirect()->route('compliance.controlled-documents.index');
    }

    /**
     * Display the specified controlled document.
     */
    public function show(ControlledDocument $document)
    {
        return Inertia::render('Compliance/ControlledDocuments/Show', [
            'document' => $document,
        ]);
    }

    /**
     * Show the form for editing the specified controlled document.
     */
    public function edit(ControlledDocument $document)
    {
        return Inertia::render('Compliance/ControlledDocuments/Edit', [
            'document' => $document,
        ]);
    }

    /**
     * Update the specified controlled document in storage.
     */
    public function update(Request $request, ControlledDocument $document)
    {
        $validated = $request->validate([
            'document_name' => 'required|string',
            'description' => 'nullable|string',
            'version' => 'required|string',
            'status' => 'required|string',
        ]);

        $document->update($validated);

        return redirect()->route('compliance.controlled-documents.index');
    }

    /**
     * Remove the specified controlled document from storage.
     */
    public function destroy(ControlledDocument $document)
    {
        $document->delete();

        return redirect()->route('compliance.controlled-documents.index');
    }
}
