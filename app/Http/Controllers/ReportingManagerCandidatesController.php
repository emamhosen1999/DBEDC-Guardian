<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access\ReportingManagerCandidates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Feeds the "Reports To" picker of the add-user and employment forms. */
class ReportingManagerCandidatesController extends Controller
{
    public function __invoke(Request $request, ReportingManagerCandidates $candidates): JsonResponse
    {
        $request->validate(['employee_id' => 'nullable|string|max:64']);
        $actor = $request->user();
        $employeeId = $request->input('employee_id');

        if ($employeeId !== null && $employeeId !== '') {
            // Editing someone: only an actor who may change that person's placement may browse the list.
            $target = User::withTrashed()->find($employeeId);
            abort_unless($target && $actor->can('updatePlacement', $target), 403);
        } else {
            abort_unless($actor->can('employees.create'), 403);
        }

        return response()->json(['candidates' => $candidates->list($actor, $employeeId ?: null)]);
    }
}
