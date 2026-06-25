<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth;

class FirebaseTokenController extends Controller
{
    public function __invoke(Request $request, Auth $auth): JsonResponse
    {
        $user = $request->user();
        $token = $auth->createCustomToken((string) $user->id, ['uid_int' => $user->id]);

        return response()->json(['token' => $token->toString()]);
    }
}
