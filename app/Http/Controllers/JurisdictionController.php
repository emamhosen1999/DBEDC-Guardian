<?php

namespace App\Http\Controllers;

use App\Models\Jurisdiction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class JurisdictionController extends Controller
{
    public function index()
    {
        return redirect()->route('daily-works-unified', ['tab' => 'jurisdictions']);
    }

    public function allJurisdictions(Request $request)
    {
        try {
            $jurisdictions = Jurisdiction::with('inchargeUser')->get();
            return response()->json([
                'jurisdictions' => $jurisdictions,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve jurisdictions',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function addJurisdiction(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'location' => 'required|string|unique:jurisdictions,location',
                'start_chainage' => 'required|string',
                'end_chainage' => 'required|string',
                'incharge' => 'required|exists:users,id',
            ], [
                'location.required' => 'Jurisdiction name is required.',
                'location.unique' => 'A jurisdiction with this name already exists.',
                'start_chainage.required' => 'Start Chainage is required.',
                'end_chainage.required' => 'End Chainage is required.',
                'incharge.required' => 'Jurisdiction incharge is required.',
                'incharge.exists' => 'Selected incharge user does not exist.',
            ]);

            $jurisdiction = Jurisdiction::create([
                'location' => $validatedData['location'],
                'start_chainage' => $validatedData['start_chainage'],
                'end_chainage' => $validatedData['end_chainage'],
                'incharge' => $validatedData['incharge'],
            ]);

            $jurisdictions = Jurisdiction::with('inchargeUser')->get();

            return response()->json([
                'message' => 'Jurisdiction added successfully',
                'jurisdictions' => $jurisdictions,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateJurisdiction(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'id' => 'required|exists:jurisdictions,id',
                'location' => 'required|string|unique:jurisdictions,location,' . $request->id,
                'start_chainage' => 'required|string',
                'end_chainage' => 'required|string',
                'incharge' => 'required|exists:users,id',
            ], [
                'location.required' => 'Jurisdiction name is required.',
                'location.unique' => 'A jurisdiction with this name already exists.',
                'start_chainage.required' => 'Start Chainage is required.',
                'end_chainage.required' => 'End Chainage is required.',
                'incharge.required' => 'Jurisdiction incharge is required.',
                'incharge.exists' => 'Selected incharge user does not exist.',
            ]);

            $jurisdiction = Jurisdiction::findOrFail($validatedData['id']);
            $jurisdiction->update([
                'location' => $validatedData['location'],
                'start_chainage' => $validatedData['start_chainage'],
                'end_chainage' => $validatedData['end_chainage'],
                'incharge' => $validatedData['incharge'],
            ]);

            $jurisdictions = Jurisdiction::with('inchargeUser')->get();

            return response()->json([
                'message' => 'Jurisdiction updated successfully',
                'jurisdictions' => $jurisdictions,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function deleteJurisdiction(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'id' => 'required|exists:jurisdictions,id',
            ], [
                'id.required' => 'Jurisdiction ID is required.',
                'id.exists' => 'Jurisdiction not found.',
            ]);

            $jurisdiction = Jurisdiction::findOrFail($validatedData['id']);
            $jurisdiction->delete();

            $jurisdictions = Jurisdiction::with('inchargeUser')->get();

            return response()->json([
                'message' => 'Jurisdiction deleted successfully',
                'jurisdictions' => $jurisdictions,
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
