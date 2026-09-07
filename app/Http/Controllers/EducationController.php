<?php

namespace App\Http\Controllers;

use App\Models\Education;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EducationController extends Controller
{
    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'educations' => 'required|array|min:1',
                'educations.*.id' => 'nullable|exists:education,id',
                'educations.*.institution' => 'required|string|max:255',
                'educations.*.subject' => 'required|string|max:255',
                'educations.*.degree' => 'required|string|max:255',
                'educations.*.starting_date' => 'required|date',
                'educations.*.complete_date' => 'required|date',
                'educations.*.grade' => 'required|string|max:255',
                'educations.*.user_id' => 'required|exists:users,employee_id',
            ], [
                'educations.*.id.exists' => 'The specified education ID does not exist.',
                'educations.*.institution.required' => 'Institution is required.',
                'educations.*.subject.required' => 'Subject is required.',
                'educations.*.degree.required' => 'Degree is required.',
                'educations.*.starting_date.required' => 'Started in is required.',
                'educations.*.starting_date.date' => 'Started in must be a valid date.',
                'educations.*.complete_date.required' => 'Completed in is required.',
                'educations.*.complete_date.date' => 'Complete date must be a valid date.',
                'educations.*.grade.required' => 'Grade is required.',
                'educations.*.user_id.exists' => 'The specified user ID does not exist.',
            ]);

            $targetUserId = $this->singleTargetUserId($validated['educations']);
            $this->authorizeProfileUpdate($request, $targetUserId);

            $messages = [];

            foreach ($validated['educations'] as $educationData) {
                if (isset($educationData['starting_date'])) {
                    $educationData['starting_date'] = date('Y-m-d', strtotime($educationData['starting_date']));
                }
                if (isset($educationData['complete_date'])) {
                    $educationData['complete_date'] = date('Y-m-d', strtotime($educationData['complete_date']));
                }
                if (isset($educationData['id'])) {
                    $education = Education::where('id', $educationData['id'])
                        ->where('user_id', $educationData['user_id'])
                        ->first();
                    if ($education) {
                        $education->update($educationData);
                        $messages[] = 'Education record updated: '.$educationData['institution'];
                    } else {
                        return response()->json(['error' => 'Education record not found.'], 404);
                    }
                } else {
                    Education::create($educationData);
                    $messages[] = 'Education record added: '.$educationData['institution'];
                }
            }

            // Retrieve the updated education list for the user
            $updatedEducations = Education::where('user_id', $targetUserId)->get();

            foreach ($updatedEducations as $education) {
                $education->starting_date = date('Y-m', strtotime($education->starting_date));
                $education->complete_date = date('Y-m', strtotime($education->complete_date));
            }

            return response()->json([
                'messages' => $messages,
                'educations' => $updatedEducations,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'errors' => $e->errors(),
            ], 422);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['error' => 'Update Education Error: '.$e->getMessage()], 500);
        }
    }

    // Delete education records
    public function delete(Request $request)
    {
        try {
            $validated = $request->validate([
                'id' => 'required|exists:education,id',
                'user_id' => 'required|exists:users,employee_id',
            ], [
                'id.required' => 'Education ID is required.',
                'id.exists' => 'The specified education ID does not exist.',
                'user_id.required' => 'User ID is required.',
                'user_id.exists' => 'The specified user ID does not exist.',
            ]);

            $this->authorizeProfileUpdate($request, (string) $validated['user_id']);

            $education = Education::where('id', $validated['id'])
                ->where('user_id', $validated['user_id'])
                ->first();

            if ($education) {
                $education->delete();

                // Retrieve the updated education list for the user
                $updatedEducations = Education::where('user_id', $validated['user_id'])->get();

                return response()->json([
                    'message' => 'Education record deleted successfully.',
                    'educations' => $updatedEducations,
                ]);
            } else {
                return response()->json(['error' => 'Education record not found.'], 404);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'errors' => $e->errors(),
            ], 422);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['error' => 'Delete Education Error: '.$e->getMessage()], 500);
        }
    }

    private function singleTargetUserId(array $records): string
    {
        $userIds = collect($records)
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        if ($userIds->count() !== 1) {
            throw ValidationException::withMessages([
                'educations' => 'All education records must belong to the same user.',
            ]);
        }

        return $userIds->first();
    }

    private function authorizeProfileUpdate(Request $request, string $targetUserId): void
    {
        $actor = $request->user();
        $isSelf = $actor && (string) $actor->getKey() === $targetUserId;

        abort_unless(
            $actor && (($isSelf && $actor->can('profile.own.update')) || $actor->can('users.update')),
            403
        );
    }
}
