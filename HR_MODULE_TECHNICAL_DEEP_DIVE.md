# 🔬 HR MODULE TECHNICAL DEEP DIVE
**Database Schema, Relationships, and Implementation Patterns**

---

## 📊 DATABASE ENTITY RELATIONSHIP DIAGRAM

### Core User-Department-Designation Hierarchy
```
┌─────────────────┐
│     users       │ (Employees)
├─────────────────┤
│ id              │
│ name            │
│ email           │
│ employee_id     │
│ department_id   │◄─────┐
│ designation_id  │◄─┐   │
│ report_to       │  │   │
│ profile_image   │  │   │
│ active          │  │   │
│ date_of_joining │  │   │
│ bank_account_no │  │   │
│ [50+ HR fields] │  │   │
└─────────────────┘  │   │
                     │   │
         ┌───────────┘   │
         │               │
    ┌─────────────────┐  │
    │ designations    │  │
    ├─────────────────┤  │
    │ id              │  │
    │ name            │◄─┘
    │ hierarchy_level │
    └─────────────────┘

    ┌──────────────────┐
    │   departments    │
    ├──────────────────┤
    │ id               │
    │ name             │◄─┐
    │ parent_id        │  │ (Self Relations for Hierarchy)
    │ head_id          │  │
    └──────────────────┘  │
                          │
                    ┌─────┘
```

### Performance Review Workflow
```
┌──────────────────────────┐
│  performance_reviews     │
├──────────────────────────┤
│ id                       │
│ employee_id      ────────┼──────► users
│ reviewer_id      ────────┼──────► users
│ template_id      ────────┼──────► performance_review_templates
│ review_date              │
│ status (pending/...)     │
│ overall_rating    ──────┐│
│ comments                ││
└──────────────────────────┘│
                            │
    ┌───────────────────────┘
    │
    ▼
┌──────────────────────────┐
│ performance_goals_kpi    │
├──────────────────────────┤
│ id                       │
│ review_id                │
│ kpi_id       ────────────┼──► kpis
│ target_value             │
│ actual_value             │
│ achievement_percent      │
└──────────────────────────┘
```

### Recruitment ATS Pipeline
```
┌─────────────────────┐
│       jobs          │ (Job Postings)
├─────────────────────┤
│ id                  │
│ title               │
│ department_id   ───┬┼──► departments
│ status              │
│ posted_date         │
│ deadline            │
│ budget              │
└─────────────────────┘
        ▲
        │
        │ (One-to-Many)
        │
┌─────────────────────────────┐
│   job_applications          │ (Candidates)
├─────────────────────────────┤
│ id                          │
│ job_id                      │
│ candidate_name              │
│ email, phone                │
│ resume_path                 │
│ hiring_stage_id   ──────────┼──► job_hiring_stages
│ status                      │
│ created_at                  │
└─────────────────────────────┘
        │
        │ (One-to-Many per application)
        │
┌──────────────────────────┐
│   job_interviews         │
├──────────────────────────┤
│ id                       │
│ application_id           │
│ interviewer_id  ────────┬┼──► users
│ interview_date           │
│ feedback_rating          │
│ result (pass/fail)       │
└──────────────────────────┘
        │
        ▼
┌──────────────────────────┐
│   job_offers             │
├──────────────────────────┤
│ id                       │
│ application_id           │
│ salary_offered           │
│ offer_date               │
│ expiry_date              │
│ status (accepted/...)    │
└──────────────────────────┘
```

### Leave Management Workflow
```
┌──────────────────────────┐
│   leaves                 │
├──────────────────────────┤
│ id                       │
│ employee_id      ────────┼──► users
│ applied_date             │
│ start_date               │
│ end_date                 │
│ leave_type_id     ───────┼──► leave_types
│ reason                   │
│ status (pending/...)     │
│ approver_1_id    ────────┼──► users (Manager)
│ approver_2_id    ────────┼──► users (HR Head)
│ approver_3_id    ────────┼──► users (HR Admin)
│ approver_1_date          │
│ approver_2_date          │
│ approver_3_date          │
└──────────────────────────┘

Multi-level Approval Flow:
Submitted ──Manager Approval──► Pending HR Head ──HR Head Approval──► Pending Admin ──Admin Approval──► Approved
                                                  ║ Reject
                                                  ▼
                                              Rejected
```

### Training Enrollment
```
┌──────────────────────┐
│   trainings          │
├──────────────────────┤
│ id                   │
│ title                │
│ category_id  ────────┼──► training_categories
│ description          │
│ mandatory            │
│ duration_days        │
│ end_date             │
└──────────────────────┘
        │
        │ (One-to-Many)
        │
┌──────────────────────────────┐
│ training_enrollments         │
├──────────────────────────────┤
│ id                           │
│ training_id                  │
│ employee_id          ────────┼──► users
│ enrolled_on                  │
│ completion_date              │
│ status (enrolled/completed)  │
│ score                        │
│ certificate_id               │
└──────────────────────────────┘
        │
        │ (One-to-Many)
        │
┌──────────────────────────┐
│ training_feedbacks       │
├──────────────────────────┤
│ id                       │
│ enrollment_id            │
│ rating (1-5)             │
│ comments                 │
│ submitted_at             │
└──────────────────────────┘
```

### Payroll Structure
```
┌──────────────────────────┐
│   payrolls               │ (Payroll Runs)
├──────────────────────────┤
│ id                       │
│ period_from              │
│ period_to                │
│ status                   │
│ created_at               │
└──────────────────────────┘
        │
        │ (One-to-Many)
        │
┌──────────────────────────────┐
│   payslips                   │ (Individual Salary Slips)
├──────────────────────────────┤
│ id                           │
│ payroll_id                   │
│ employee_id          ────────┼──► users
│ basic_salary                 │
│ gross_salary                 │
│ deductions_total             │
│ net_salary                   │
│ generated_at                 │
│ payment_status               │
└──────────────────────────────┘
        │
        ├─────┬────────┬─────────────┐
        │     │        │             │
        ▼     ▼        ▼             ▼
  ┌──────────────────────────────────────────────┐
  │                                              │
  │  Allowances        Deductions         Tax    │
  │  ═════════         ═══════════        ════   │
  │  - HRA             - Loan EMI         from   │
  │  - DA              - Insurance    TaxSlabs   │
  │  - Bonus           - Prof Tax              │
  │  - Transport       - Medical                │
  │                                              │
  └──────────────────────────────────────────────┘

  Formula: Net = (Basic + Allowances) - (Deductions + Tax)
```

### Attendance with Geo-Fencing
```
┌──────────────────────────┐
│   attendance             │
├──────────────────────────┤
│ id                       │
│ employee_id      ────────┼──► users
│ punch_in                 │
│ punch_out                │
│ punch_in_location        │ (GPS coordinates)
│ punch_in_photo           │ (Via Media Library)
│ punch_out_location       │
│ punch_out_photo          │
│ validation_method        │ (polygon/qr/ip/route)
│ status                   │ (approved/pending)
│ date                     │
└──────────────────────────┘
        │
        │
        ▼
┌──────────────────────────────────────────┐
│   Validation Methods                     │
├──────────────────────────────────────────┤
│                                          │
│ 1. Polygon Geo-fence:                    │
│    - Stored boundaries for office        │
│    - GPS point-in-polygon algorithm      │
│                                          │
│ 2. QR Code:                              │
│    - Unique QR per employee/day          │
│    - Scanned via mobile app              │
│                                          │
│ 3. IP Address:                           │
│    - Office network IP range             │
│    - VPN detection                       │
│                                          │
│ 4. Route Waypoint:                       │
│    - Travel path validation              │
│    - Field employee tracking             │
│                                          │
│ 5. Face Recognition (likely):            │
│    - Biometric validation                │
│    - AI-based verification               │
│                                          │
└──────────────────────────────────────────┘
```

---

## 🔑 KEY ELOQUENT RELATIONSHIPS

### User Model Relationships
```php
// app/Models/User.php

class User extends Authenticatable {
    // HR Relationships
    public function department(): BelongsTo {
        return $this->belongsTo(Department::class);
    }
    
    public function designation(): BelongsTo {
        return $this->belongsTo(Designation::class);
    }
    
    public function manager(): BelongsTo {
        return $this->belongsTo(User::class, 'report_to');
    }
    
    public function subordinates(): HasMany {
        return $this->hasMany(User::class, 'report_to');
    }
    
    // As Reviewer
    public function performanceReviewsAsReviewer(): HasMany {
        return $this->hasMany(PerformanceReview::class, 'reviewer_id');
    }
    
    // As Employee (reviewed)
    public function performanceReviewsAsEmployee(): HasMany {
        return $this->hasMany(PerformanceReview::class, 'employee_id');
    }
    
    // Attendance
    public function attendance(): HasMany {
        return $this->hasMany(Attendance::class, 'employee_id');
    }
    
    // Leave Requests
    public function leaves(): HasMany {
        return $this->hasMany(Leave::class, 'employee_id');
    }
    
    // Training Enrollments
    public function trainingEnrollments(): HasMany {
        return $this->hasMany(TrainingEnrollment::class, 'employee_id');
    }
}
```

### Performance Review Relationships
```php
// app/Models/HRM/PerformanceReview.php

class PerformanceReview extends Model {
    public function employee(): BelongsTo {
        return $this->belongsTo(User::class, 'employee_id');
    }
    
    public function reviewer(): BelongsTo {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
    
    public function template(): BelongsTo {
        return $this->belongsTo(PerformanceReviewTemplate::class);
    }
    
    public function kpis(): HasMany {
        return $this->hasMany(KPI::class, 'review_id');
    }
    
    public function goals(): HasMany {
        return $this->hasMany(PerformanceGoal::class, 'review_id');
    }
}
```

### Recruitment Pipeline Relationships
```php
// app/Models/HRM/JobApplication.php

class JobApplication extends Model {
    public function job(): BelongsTo {
        return $this->belongsTo(Job::class);
    }
    
    public function hiringStage(): BelongsTo {
        return $this->belongsTo(JobHiringStage::class);
    }
    
    public function interviews(): HasMany {
        return $this->hasMany(JobInterview::class);
    }
    
    public function offer(): HasOne {
        return $this->hasOne(JobOffer::class);
    }
    
    public function education(): HasMany {
        return $this->hasMany(JobApplicantEducation::class);
    }
    
    public function experience(): HasMany {
        return $this->hasMany(JobApplicantExperience::class);
    }
    
    public function stageHistory(): HasMany {
        return $this->hasMany(JobApplicationStageHistory::class);
    }
}
```

---

## 💼 SERVICE LAYER PATTERNS

### PayrollCalculationService
```php
// app/Services/Payroll/PayrollCalculationService.php

class PayrollCalculationService {
    
    /**
     * Calculate complete payslip for employee
     */
    public function calculatePayslip(User $employee, $period): array {
        $basic = $employee->salary; // From designation/contract
        
        $allowances = $this->calculateAllowances($employee);
        $attendanceBonus = $this->calculateAttendanceBonus($employee, $period);
        $overtime = $this->calculateOvertime($employee, $period);
        
        $grossSalary = $basic + $allowances + $attendanceBonus + $overtime;
        
        $tax = $this->calculateTax($grossSalary, $employee);
        
        $deductions = $this->calculateDeductions($employee);
        
        $netSalary = $grossSalary - $tax - $deductions;
        
        return [
            'basic_salary' => $basic,
            'allowances' => $allowances,
            'attendance_bonus' => $attendanceBonus,
            'overtime' => $overtime,
            'gross_salary' => $grossSalary,
            'tax' => $tax,
            'deductions' => $deductions,
            'net_salary' => $netSalary,
        ];
    }
    
    /**
     * Calculate HRA, DA, and other allowances
     */
    protected function calculateAllowances(User $employee): float {
        $salary = $employee->salary;
        return ($salary * 0.50) + ($salary * 0.20); // HRA 50% + DA 20%
    }
    
    /**
     * Tax calculation based on TaxSlab
     */
    protected function calculateTax(float $gross, User $employee): float {
        $taxSlabs = TaxSlab::where('financial_year', date('Y'))
            ->orderBy('from_amount')
            ->get();
            
        $tax = 0;
        foreach ($taxSlabs as $slab) {
            if ($gross > $slab->from_amount) {
                $taxableAmount = min($gross, $slab->to_amount) - $slab->from_amount;
                $tax += $taxableAmount * ($slab->rate / 100);
            }
        }
        
        return $tax;
    }
    
    /**
     * Calculate attendance bonus (perfect attendance incentive)
     */
    protected function calculateAttendanceBonus(User $employee, $period): float {
        $totalDays = $this->getWorkingDaysInPeriod($period);
        $attendedDays = $employee->attendance()
            ->whereBetween('date', [$period['from'], $period['to']])
            ->count();
            
        if ($attendedDays == $totalDays) {
            return $employee->salary * 0.05; // 5% bonus for perfect attendance
        }
        return 0;
    }
    
    /**
     * Calculate overtime pay
     */
    protected function calculateOvertime(User $employee, $period): float {
        $overtimeHours = $employee->attendance()
            ->whereBetween('date', [$period['from'], $period['to']])
            ->where('overtime_hours', '>', 0)
            ->sum('overtime_hours');
            
        $overtimeRate = ($employee->salary / 26 / 8); // Hourly rate
        return $overtimeHours * $overtimeRate * 1.5; // 1.5x pay
    }
}
```

### Leave Approval Workflow Service
```php
// app/Services/HR/LeaveApprovalService.php

class LeaveApprovalService {
    
    public function submitLeaveRequest(Leave $leave): bool {
        // Validate leave conditions
        $this->validateLeaveRequest($leave);
        
        // Check for overlapping leaves
        $this->checkOverlappingLeaves($leave);
        
        // Check leave balance
        $this->validateLeaveBalance($leave);
        
        // Set initial status to pending with manager
        $leave->status = 'pending_manager';
        $leave->save();
        
        // Notify manager
        $this->notifyManager($leave);
        
        return true;
    }
    
    public function approveLeave(Leave $leave, User $approver, string $level): bool {
        // Validate approver authority
        $this->validateApprovalAuthority($leave, $approver, $level);
        
        if ($level === 'manager') {
            $leave->approver_1_id = $approver->id;
            $leave->approver_1_date = now();
            $leave->status = 'pending_hr_head';
            $this->notifyHrHead($leave);
        } 
        elseif ($level === 'hr_head') {
            $leave->approver_2_id = $approver->id;
            $leave->approver_2_date = now();
            $leave->status = 'pending_admin';
            $this->notifyHrAdmin($leave);
        } 
        elseif ($level === 'admin') {
            $leave->approver_3_id = $approver->id;
            $leave->approver_3_date = now();
            $leave->status = 'approved';
            $this->deductLeaveBalance($leave);
            $this->notifyEmployee($leave, 'approved');
        }
        
        $leave->save();
        return true;
    }
    
    public function rejectLeave(Leave $leave, User $rejector, string $reason): bool {
        $leave->status = 'rejected';
        $leave->rejection_reason = $reason;
        $leave->save();
        
        $this->notifyEmployee($leave, 'rejected', $reason);
        return true;
    }
    
    protected function validateLeaveBalance(Leave $leave): void {
        $balance = $this->getLeaveBalance($leave->employee_id, $leave->leave_type_id);
        $requestedDays = $leave->start_date->diffInDays($leave->end_date) + 1;
        
        if ($balance < $requestedDays) {
            throw new InsufficientLeaveBalanceException(
                "Insufficient balance. Available: {$balance}, Requested: {$requestedDays}"
            );
        }
    }
    
    protected function checkOverlappingLeaves(Leave $leave): void {
        $overlap = Leave::where('employee_id', $leave->employee_id)
            ->where('status', '!=', 'rejected')
            ->whereBetween('start_date', [$leave->start_date, $leave->end_date])
            ->orWhereBetween('end_date', [$leave->start_date, $leave->end_date])
            ->first();
            
        if ($overlap) {
            throw new OverlappingLeaveException(
                "Leave already exists from {$overlap->start_date} to {$overlap->end_date}"
            );
        }
    }
}
```

### Attendance Validation Factory
```php
// app/Services/Attendance/ValidationFactory.php

class AttendanceValidationFactory {
    
    public static function validate(Attendance $attendance): bool {
        $validator = match($attendance->validation_method) {
            'polygon' => new PolygonValidator(),
            'qr_code' => new QrCodeValidator(),
            'ip_address' => new IpAddressValidator(),
            'route_waypoint' => new RouteWaypointValidator(),
            'face_recognition' => new FaceRecognitionValidator(),
            default => throw new InvalidValidationMethodException(),
        };
        
        return $validator->validate($attendance);
    }
}

// Individual Validators
class PolygonValidator {
    public function validate(Attendance $attendance): bool {
        $geoFence = GeoFence::forLocation($attendance->office_location);
        return $this->isPointInPolygon(
            $attendance->punch_in_location,
            $geoFence->boundary_polygon
        );
    }
}

class QrCodeValidator {
    public function validate(Attendance $attendance): bool {
        $qrPath = "/qr-codes/{$attendance->employee_id}/" . 
                  now()->toDateString() . ".png";
        
        return Storage::disk('public')->exists($qrPath) &&
               $this->verifyQrSignature($attendance->qr_data);
    }
}

class IpAddressValidator {
    public function validate(Attendance $attendance): bool {
        $allowedIPs = config('hr.allowed_ips');
        $clientIP = $attendance->ip_address;
        
        return in_array($clientIP, $allowedIPs);
    }
}

class RouteWaypointValidator {
    public function validate(Attendance $attendance): bool {
        // Verify GPS path matches expected route for field employees
        $route = $attendance->employee->assigned_route;
        return $this->verifyRouteCompliance($attendance->gps_path, $route);
    }
}

class FaceRecognitionValidator {
    public function validate(Attendance $attendance): bool {
        // Call ML service to verify face match
        $faceVerification = app('face-recognition-service')
            ->verify($attendance->punch_in_photo, $attendance->employee_id);
            
        return $faceVerification['confidence'] > 0.95;
    }
}
```

---

## 📱 CONTROLLER IMPLEMENTATION EXAMPLES

### Performance Review Controller - Dashboard
```php
class PerformanceReviewController extends Controller {
    
    public function dashboard() {
        // Statistics with relationship eager loading
        $stats = [
            'totalReviews' => PerformanceReview::count(),
            'pendingReviews' => PerformanceReview::where('status', 'pending')->count(),
            'completedReviews' => PerformanceReview::where('status', 'completed')->count(),
            'averageRating' => PerformanceReview::whereNotNull('overall_rating')
                ->avg('overall_rating'),
        ];
        
        // Recent reviews with relationships
        $recentReviews = PerformanceReview::with(['employee', 'reviewer', 'template'])
            ->latest()
            ->take(5)
            ->get();
        
        // Upcoming reviews
        $upcomingReviews = PerformanceReview::with(['employee', 'reviewer'])
            ->where('status', 'scheduled')
            ->orderBy('review_date')
            ->take(5)
            ->get();
        
        // Pending approvals
        $pendingActions = PerformanceReview::with(['employee', 'reviewer'])
            ->where('status', 'pending')
            ->where('approver_id', Auth::id())
            ->get();
        
        // Attendance overview for this period
        $attendanceOverview = $this->getAttendanceStats();
        
        // Training progress
        $trainingProgress = TrainingEnrollment::with('training')
            ->where('status', 'enrolled')
            ->get()
            ->groupBy('training_id');
        
        return Inertia::render('HR/Dashboard', [
            'stats' => $stats,
            'recentReviews' => $recentReviews,
            'upcomingReviews' => $upcomingReviews,
            'pendingActions' => $pendingActions,
            'attendanceOverview' => $attendanceOverview,
            'trainingProgress' => $trainingProgress,
        ]);
    }
}
```

---

## 🎯 ADVANCED QUERIES

### Performance Analytics Query
```php
// Complex aggregation for performance analytics
$performanceAnalytics = PerformanceReview::with(['employee.department', 'template'])
    ->whereYear('review_date', date('Y'))
    ->get()
    ->groupBy('employee.department_id')
    ->map(function ($reviews, $deptId) {
        $department = Department::find($deptId);
        return [
            'department' => $department->name,
            'employee_count' => $reviews->count(),
            'average_rating' => $reviews->avg('overall_rating'),
            'rating_distribution' => [
                'excellent' => $reviews->where('overall_rating', '>=', 4.5)->count(),
                'good' => $reviews->whereBetween('overall_rating', [3.5, 4.49])->count(),
                'average' => $reviews->whereBetween('overall_rating', [2.5, 3.49])->count(),
                'needs_improvement' => $reviews->where('overall_rating', '<', 2.5)->count(),
            ],
        ];
    });
```

### Recruitment Pipeline Status
```php
// Track applications through hiring stages
$pipelineStatus = Job::with('applications.hiringStage')
    ->where('status', 'open')
    ->get()
    ->map(function ($job) {
        return [
            'job_title' => $job->title,
            'pipeline' => JobHiringStage::with(['applications' => function ($query) use ($job) {
                $query->where('job_id', $job->id);
            }])->get(),
        ];
    });
```

### Leave Accrual & Balance Calculation
```php
// Calculate leave balance with accrual
$leaveBalance = function (User $employee, LeaveType $leaveType) {
    $opening = $employee->leave_carried_forward
        ->where('leave_type_id', $leaveType->id)
        ->sum('balance');
    
    $accrued = $this->calculateAccrual($employee, $leaveType);
    
    $used = Leave::where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('status', 'approved')
        ->whereBetween('start_date', [now()->startOfYear(), now()])
        ->sum(DB::raw('DATEDIFF(end_date, start_date) + 1'));
    
    return $opening + $accrued - $used;
};
```

---

## 🔒 PERMISSION MATRIX

| Permission | Role | Description |
|-----------|------|------------|
| `hr.dashboard.view` | All HR Users | Access HR module dashboard |
| `hr.performance.view` | Manager, HR Lead | View performance reviews |
| `hr.performance.create` | Manager, HR Lead | Create reviews |
| `hr.performance.edit` | Manager, HR Lead | Edit own reviews |
| `hr.performance.delete` | HR Admin | Delete reviews |
| `hr.recruitment.view` | HR Lead, Recruiter | View job postings |
| `hr.recruitment.publish` | HR Lead | Publish jobs |
| `hr.recruitment.close` | HR Lead | Close jobs |
| `hr.payroll.view` | HR Admin, Finance | View payroll |
| `hr.payroll.generate` | HR Admin | Generate payroll runs |
| `hr.selfservice.view` | All Employees | Self-service access |
| `hr.analytics.view` | HR Lead, HR Admin | View analytics dashboards |

---

## 📈 SCALABILITY CONSIDERATIONS

### Current Bottlenecks
1. **Dashboard Caching** - Stats aggregation on every load
2. **Attendance Queries** - Large attendance tables for company-wide searches
3. **Payroll Processing** - Complex calculations without async queue
4. **Report Generation** - Synchronous PDF generation

### Recommended Optimizations
```php
// 1. Cache dashboard stats
Cache::remember('hr.dashboard.stats', 3600, function () {
    return [...];
});

// 2. Index frequently queried columns
$table->index(['employee_id', 'status']);
$table->index(['created_at', 'employee_id']);

// 3. Queue payroll processing
dispatch(new GeneratePayrollJob($payrollPeriod));

// 4. Pagination for large result sets
PerformanceReview::paginate(15);

// 5. Soft delete for audit trail
$review->delete(); // Soft delete
$review->forceDelete(); // Permanent delete
```

---

## 🚀 CONCLUSION

The HR module implements enterprise patterns with:
- ✅ Complex multi-stage workflows (approvals, recruitment, payroll)
- ✅ Sophisticated relationship hierarchies (organizational chart)
- ✅ Advanced calculations (payroll tax, slab-based deductions)
- ✅ Multiple validation strategies (geo-fencing, QR, biometric)
- ✅ Permission-based access control
- ✅ Service layer abstraction for complex logic

**Ready for enterprise deployment with recommended optimizations.**

