<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\HRM\Leave;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    /**
     * Get admin dashboard statistics
     */
    public function stats()
    {
        $user = Auth::user();

        // Check if user has admin role
        if (! $user->hasRole('Super Administrator')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $totalUsers = User::count();
        $activeMembers = User::whereHas('roles', function ($query) {
            $query->where('name', 'Member');
        })->count();
        $pendingRequests = Leave::where('status', 'pending')->count();
        $systemStatus = 'online'; // Can be enhanced with actual system checks

        return response()->json([
            'totalUsers' => $totalUsers,
            'activeMembers' => $activeMembers,
            'pendingRequests' => $pendingRequests,
            'systemStatus' => $systemStatus,
        ]);
    }

    /**
     * Get recent activity for admin dashboard
     */
    public function recentActivity()
    {
        $user = Auth::user();

        // Check if user has admin role
        if (! $user->hasRole('Super Administrator')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        // Get recent user joins
        $recentJoins = User::with('roles')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'type' => 'user_joined',
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'profile_image_url' => $user->profile_image_url,
                    ],
                    'timestamp' => $user->created_at ? $user->created_at->toISOString() : null,
                    'description' => 'joined the organization',
                ];
            });

        // Get recent leave requests
        $recentLeaves = Leave::with('user')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($leave) {
                return [
                    'id' => $leave->id,
                    'type' => 'leave_requested',
                    'user' => [
                        'id' => $leave->user->id,
                        'name' => $leave->user->name,
                        'profile_image_url' => $leave->user->profile_image_url,
                    ],
                    'timestamp' => $leave->created_at ? $leave->created_at->toISOString() : null,
                    'description' => 'requested leave',
                ];
            });

        // Combine and sort by timestamp
        $activities = $recentJoins
            ->concat($recentLeaves)
            ->sortByDesc('timestamp')
            ->take(10)
            ->values();

        return response()->json([
            'activities' => $activities,
        ]);
    }

    /**
     * Get attendance trends for admin dashboard
     */
    public function attendanceTrends()
    {
        $user = Auth::user();

        // Check if user has admin role
        if (! $user->hasRole('Super Administrator')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        // Get attendance data for the last 7 days
        $trends = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            
            // Count present users (those who punched in)
            $present = DB::table('attendances')
                ->whereDate('date', $date)
                ->distinct('user_id')
                ->count();

            // Count total active members
            $total = User::whereHas('roles', function ($query) {
                $query->where('name', 'Member');
            })->count();

            $absent = $total - $present;

            $trends[] = [
                'date' => $date,
                'present' => $present,
                'absent' => max(0, $absent),
            ];
        }

        return response()->json([
            'trends' => $trends,
        ]);
    }

    /**
     * Get pending approvals for admin dashboard
     */
    public function pendingApprovals()
    {
        $user = Auth::user();

        // Check if user has admin role
        if (! $user->hasRole('Super Administrator')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $approvals = Leave::with('user')
            ->where('status', 'pending')
            ->orderBy('from_date', 'asc')
            ->take(10)
            ->get()
            ->map(function ($leave) {
                $fromDate = $leave->from_date instanceof \Carbon\Carbon ? $leave->from_date : \Carbon\Carbon::parse($leave->from_date);
                $toDate = $leave->to_date instanceof \Carbon\Carbon ? $leave->to_date : \Carbon\Carbon::parse($leave->to_date);
                
                $duration = $fromDate->diffInDays($toDate) + 1;
                $urgency = 'medium';
                
                // Determine urgency based on start date
                if ($fromDate->isToday()) {
                    $urgency = 'high';
                } elseif ($fromDate->diffInDays(now()) <= 2) {
                    $urgency = 'high';
                } elseif ($fromDate->diffInDays(now()) <= 5) {
                    $urgency = 'medium';
                } else {
                    $urgency = 'low';
                }

                return [
                    'id' => $leave->id,
                    'user' => [
                        'id' => $leave->user->id,
                        'name' => $leave->user->name,
                        'profile_image_url' => $leave->user->profile_image_url,
                    ],
                    'leaveType' => $leave->leaveType ? $leave->leaveType->type : 'Leave',
                    'duration' => $duration.' day'.($duration > 1 ? 's' : ''),
                    'urgency' => $urgency,
                    'from_date' => $leave->from_date ? ($leave->from_date instanceof \Carbon\Carbon ? $leave->from_date->toISOString() : \Carbon\Carbon::parse($leave->from_date)->toISOString()) : null,
                    'to_date' => $leave->to_date ? ($leave->to_date instanceof \Carbon\Carbon ? $leave->to_date->toISOString() : \Carbon\Carbon::parse($leave->to_date)->toISOString()) : null,
                ];
            });

        return response()->json([
            'approvals' => $approvals,
        ]);
    }

    /**
     * Get system health information
     */
    public function systemHealth()
    {
        $user = Auth::user();

        // Check if user has admin role
        if (! $user->hasRole('Super Administrator')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        // Get database connection status
        try {
            DB::connection()->getPdo();
            $databaseStatus = 'connected';
            $dbConnections = DB::select('SHOW STATUS LIKE "Threads_connected"');
            $connections = isset($dbConnections[0]) ? (int) $dbConnections[0]->Value : 0;
        } catch (\Exception $e) {
            $databaseStatus = 'disconnected';
            $connections = 0;
        }

        // Get storage usage
        $storageUsed = 45; // Placeholder - can be enhanced with actual disk usage check

        // Get last backup time (placeholder)
        $lastBackup = '2 hours ago';

        // Server response time (approximate)
        $responseTime = rand(30, 60);

        return response()->json([
            'server' => [
                'status' => 'healthy',
                'responseTime' => $responseTime,
            ],
            'database' => [
                'status' => $databaseStatus,
                'connections' => $connections,
            ],
            'storage' => [
                'used' => $storageUsed,
                'total' => 100,
            ],
            'lastBackup' => $lastBackup,
        ]);
    }

    /**
     * Get recent members for admin dashboard
     */
    public function recentMembers()
    {
        $user = Auth::user();

        // Check if user has admin role
        if (! $user->hasRole('Super Administrator')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $members = User::with(['roles', 'department'])
            ->whereHas('roles', function ($query) {
                $query->where('name', 'Member');
            })
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->map(function ($user) {
                $status = 'Active';
                
                // Check if user is on leave
                $onLeave = Leave::where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->whereDate('from_date', '<=', now())
                    ->whereDate('to_date', '>=', now())
                    ->exists();
                
                if ($onLeave) {
                    $status = 'On Leave';
                } elseif (! $user->is_active) {
                    $status = 'Inactive';
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'department' => $user->department ? $user->department->name : 'Unassigned',
                    'joinDate' => $user->created_at ? $user->created_at->toISOString() : null,
                    'status' => $status,
                    'profile_image_url' => $user->profile_image_url,
                ];
            });

        return response()->json([
            'members' => $members,
        ]);
    }

    /**
     * Approve a leave request
     */
    public function approveLeave($id)
    {
        $user = Auth::user();

        // Check if user has admin role
        if (! $user->hasRole('Super Administrator')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $leave = Leave::findOrFail($id);
        $leave->status = 'approved';
        $leave->approved_by = $user->id;
        $leave->approved_at = now();
        $leave->save();

        return response()->json([
            'message' => 'Leave request approved successfully',
        ]);
    }

    /**
     * Reject a leave request
     */
    public function rejectLeave($id)
    {
        $user = Auth::user();

        // Check if user has admin role
        if (! $user->hasRole('Super Administrator')) {
            return response()->json([
                'message' => 'Unauthorized access',
            ], 403);
        }

        $leave = Leave::findOrFail($id);
        $leave->status = 'rejected';
        $leave->approved_by = $user->id;
        $leave->approved_at = now();
        $leave->save();

        return response()->json([
            'message' => 'Leave request rejected',
        ]);
    }
}
