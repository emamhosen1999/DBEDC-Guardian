# 📚 HR MODULE QUICK REFERENCE GUIDE
**For Developers: Fast lookup and implementation patterns**

---

## 🚀 QUICK LINKS TO KEY FILES

### Controllers
```
app/Http/Controllers/HR/
├── PerformanceReviewController.php      → CRUD + Dashboard for reviews
├── RecruitmentController.php            → Full ATS pipeline
├── TrainingController.php               → Training programs with enrollment
├── PayrollController.php                → Salary calculation & payslips
├── OnboardingController.php             → Employee lifecycle
├── SkillsController.php                 → Skills & competencies
├── BenefitsController.php               → Benefits management
├── TimeOffManagementController.php      → Modern leave system (preferred)
├── TimeOffController.php                → Legacy leave (backward compat)
├── WorkplaceSafetyController.php        → Safety incidents & inspections
├── HrAnalyticsController.php            → Analytics dashboards
├── HrDocumentController.php             → HR policy docs
├── EmployeeSelfServiceController.php    → Employee portal
├── ManagersController.php               → Manager dashboard
└── SafetyIncidentController.php         → Incident management
```

### Routes
```
routes/hr.php                            → All HR routes (300+ lines)
```

### Models
```
app/Models/
├── User.php                             → Employee (main HR model)

app/Models/HRM/
├── Department.php                       → Org structure
├── Designation.php                      → Job titles
├── Attendance.php, AttendanceType.php  → Attendance records
├── Leave.php, LeaveSetting.php         → Leave management
├── PerformanceReview.php               → Performance reviews
├── PerformanceReviewTemplate.php        → Review templates
├── Job.php, JobApplication.php         → Recruitment ATS
├── JobInterview.php, JobOffer.php      → Interview & offers
├── Training.php, TrainingEnrollment.py → Training programs
├── Payroll.php, Payslip.php            → Payroll processing
├── Onboarding.php, Offboarding.php     → Lifecycle
├── Skill.php, Competency.php           → Skills framework
├── Benefit.php                          → Benefits
├── SafetyIncident.php                  → Safety management
└── HrDocument.php                      → HR policies
```

### Frontend Pages
```
resources/js/Pages/HR/
├── Dashboard.jsx                        → HR overview dashboard
├── Performance/Index.jsx                → Performance reviews list
├── Recruitment/Index.jsx                → Job postings & ATS
├── Training/Index.jsx                   → Training programs
├── Skills/Index.jsx                     → Skills & competencies
├── TimeOff/Index.jsx                    → Leave management
├── Benefits/Index.jsx                   → Benefits enrollment
├── Onboarding/Index.jsx                 → Onboarding checklist
├── Offboarding/Index.jsx                → Exit process
├── Safety/Index.jsx                     → Safety incidents
├── Documents/Index.jsx                  → HR policies
├── Analytics/Index.jsx                  → Analytics dashboard
└── SelfService/Index.jsx                → Employee portal
```

---

## 🎯 FEATURE QUICK START

### Adding a New HR Feature
1. **Create Migration**
```bash
php artisan make:migration create_new_hr_table
```

2. **Create Model**
```bash
php artisan make:model HRM/FeatureName -m
```

3. **Create Controller**
```bash
php artisan make:controller HR/FeatureNameController -r
```

4. **Create React Pages**
```
resources/js/Pages/HR/FeatureName/
├── Index.jsx
├── Create.jsx
├── Edit.jsx
└── Show.jsx
```

5. **Add Routes** to routes/hr.php
6. **Add Permission** via Spatie Permission
7. **Add Navigation** to resources/js/Props/pages.jsx

### Common Implementation Pattern

#### Controller
```php
class FeatureController extends Controller {
    public function index(Request $request) {
        return Inertia::render('HR/Feature/Index', [
            'items' => Model::with('relationships')->paginate(),
        ]);
    }
    
    public function store(Request $request) {
        $this->validate($request, [...]);
        Model::create($request->validated());
        return redirect()->route('hr.feature.index');
    }
}
```

#### React Page
```jsx
import { Link, router, usePage } from '@inertiajs/react';
import App from '@/Layouts/App';

export default function Index({ items }) {
    return (
        <App>
            <Head title="Feature" />
            {/* Page content */}
        </App>
    );
}
```

---

## 📋 PERMISSION CHECKLIST

### Adding New Permission

1. **Create in seeder** (database/seeders/PermissionSeeder.php)
```php
Permission::create(['name' => 'hr.feature.view']);
Permission::create(['name' => 'hr.feature.create']);
Permission::create(['name' => 'hr.feature.edit']);
Permission::create(['name' => 'hr.feature.delete']);
```

2. **Add route middleware** (routes/hr.php)
```php
Route::middleware(['permission:hr.feature.view'])->group(function () {
    Route::get('/feature', [Controller::class, 'index']);
});
```

3. **Add navigation** (resources/js/Props/pages.jsx)
```jsx
...(permissions.includes('hr.feature.view') ? [{
    name: 'Feature',
    icon: <IconName />,
    route: 'hr.feature.index'
}] : []),
```

4. **Run seeder**
```bash
php artisan db:seed --class=PermissionSeeder
```

---

## 🔍 COMMON QUERIES

### Get Employee with Full HR Context
```php
$employee = User::with([
    'department',
    'designation',
    'manager',
    'subordinates',
    'leave',
    'attendance',
    'performanceReviews',
])->find($userId);
```

### Get Department Organization Chart
```php
$dept = Department::with([
    'employees' => function ($query) {
        $query->with('designation');
    },
    'children', // Sub-departments
])->find($deptId);
```

### Leave Balance Calculation
```php
$balance = Leave::leftJoin('leave_settings', 'leaves.leave_type_id', '=', 'leave_settings.id')
    ->where('leaves.employee_id', $employeeId)
    ->where('leaves.status', 'approved')
    ->selectRaw('DATEDIFF(end_date, start_date) + 1 as days_used')
    ->sum('days_used');

$balance = (new LeaveApprovalService())->getLeaveBalance($employeeId, $leaveTypeId);
```

### Performance Review Stats
```php
$stats = PerformanceReview::selectRaw('
    COUNT(*) as total,
    COUNT(IF(status = "completed", 1, NULL)) as completed,
    COUNT(IF(status = "pending", 1, NULL)) as pending,
    AVG(overall_rating) as avg_rating
')
->where('review_date', '>=', now()->startOfYear())
->first();
```

### Recruitment Pipeline
```php
$pipeline = Job::where('status', 'open')
    ->with=['applications' => function ($q) {
        $q->with('hiringStage', 'interviews', 'offer');
    }]
    ->get();
```

---

## 🛠️ DEBUGGING TIPS

### Check HR Permissions for User
```php
$user = User::find($userId);
dump($user->permissions);  // Direct permissions
dump($user->roles);         // Assigned roles
dump($user->getAllPermissions()); // All inherited
```

### View All Available Routes
```bash
php artisan route:list | grep hr
```

### Database Query Logging
```php
// In controller or tinker
DB::enableQueryLog();
// ... run queries ...
dump(DB::getQueryLog());
```

### Trace Approval Workflow
```php
$leave = Leave::with('approvals')->find(1);
foreach ($leave->approvals as $approval) {
    echo "{$approval->approver->name} - {$approval->status}";
}
```

### Performance Profiling
```php
\Debugbar::startMeasure('calculation', 'Payroll Calculation');
// ... calculation ...
\Debugbar::stopMeasure('calculation');
```

---

## 📊 PERFORMANCE OPTIMIZATION CHECKLIST

- [ ] Add indexes on frequently filtered columns
  ```php
  $table->index(['employee_id', 'status', 'created_at']);
  ```

- [ ] Implement dashboard stats caching (3600s)
  ```php
  Cache::remember('hr.stats', 3600, fn() => [...]); 
  ```

- [ ] Pagination for large lists (default: 15)
  ```php
  Model::paginate(15);
  ```

- [ ] Eager load relationships to prevent N+1
  ```php
  Model::with('department', 'designation')->get();
  ```

- [ ] Use select() to limit columns
  ```php
  User::select('id', 'name', 'email')->get();
  ```

- [ ] Queue long-running operations
  ```php
  dispatch(new GeneratePayrollJob($period));
  ```

- [ ] Archive old records
  ```php
  Attendance::where('date', '<', now()->subYears(2))->delete();
  ```

- [ ] Monitor query performance
  ```php
  DB::enableQueryLog(); // Check in Debugbar
  ```

---

## 🧪 TESTING PATTERNS

### Test Permission Enforcement
```php
public function test_unauthorized_user_cannot_access_payroll() {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get('/hr/payroll');
    $response->assertForbidden();
}
```

### Test Leave Approval Workflow
```php
public function test_leave_approval_flow() {
    $employee = User::factory()->create();
    $manager = User::factory()->create();
    $employee->update(['report_to' => $manager->id]);
    
    $leave = Leave::factory()
        ->for($employee, 'employee')
        ->create();
    
    $this->actingAs($manager)
        ->post(route('hr.leave.approve', $leave), ['approved' => true])
        ->assertRedirect();
        
    $this->assertEquals('approved', $leave->fresh()->status);
}
```

### Test Payroll Calculation
```php
public function test_payroll_calculates_correctly() {
    $employee = User::factory()->create(['base_salary' => 50000]);
    $service = app(PayrollCalculationService::class);
    
    $payslip = $service->calculatePayslip($employee, [
        'from' => '2024-01-01',
        'to' => '2024-01-31'
    ]);
    
    $this->assertGreater($payslip['gross_salary'], 0);
    $this->assertGreater($payslip['net_salary'], 0);
    $this->assertLess($payslip['net_salary'], $payslip['gross_salary']);
}
```

---

## 📌 IMPORTANT NOTES

### Version Considerations
- **PHP 8.2.12** - Uses constructor property promotion
- **Laravel 11** - Using v10 migration structure (not latest)
- **React 18** - Functional components with hooks
- **Inertia v2** - Latest version features available

### Known Issues
1. ⚠️ Dual TimeOff implementations - consider consolidation
2. ⚠️ Large HR models directory - consider feature-based namespacing
3. ⚠️ Dashboard stats not cached - consider adding Cache layer

### Backend Dependencies
```json
{
  "spatie/laravel-permission": "^6.0",
  "spatie/laravel-media-library": "^10.0",
  "laravel/fortify": "^1.0",
  "laravel/sanctum": "^3.0"
}
```

### Frontend Dependencies
```json
{
  "react": "^18.0",
  "@inertiajs/react": "^2.0",
  "tailwindcss": "^3.0",
  "@heroui/react": "^2.0",
  "framer-motion": "^10.0"
}
```

---

## 🔗 RELATED MODULES

### Compliance Module Integration
- HR Safety incidents link to Compliance Incidents
- Training programs link to Compliance Training Requirements

### Project Management Integration  
- Employees assigned to projects
- Time tracking from HR Time-off module

### Document Management (DMS)
- HR Documents stored in DMS
- Employee documents linked to HR records

### Quality Module
- Incident investigations involve HR
- Safety compliance metrics in Quality dashboards

---

## 📞 QUICK TROUBLESHOOTING

| Issue | Solution |
|-------|----------|
| "Route not found" | Add route to routes/hr.php, run `php artisan cache:clear` |
| "Permission denied" | Check user has permission via `$user->hasPermissionTo('hr.action')` |
| "N+1 query problem" | Add eager loading: `.with('relationships')` |
| "Slow dashboard" | Add Cache: `Cache::remember(..., 3600, fn() => [])` |
| "Payroll incorrect" | Check PayrollCalculationService logic & tax slabs |
| "Leave approval stuck" | Check Leave status & approver_id not matching user |
| "File upload fails" | Check storage permissions & Media Library config |

---

## 📖 FURTHER READING

1. **Database Schema**: Database Relationships section in TECHNICAL_DEEP_DIVE.md
2. **Service Layer**: Service Layer Patterns section in TECHNICAL_DEEP_DIVE.md
3. **API Design**: Check API routes in routes/api.php (if applicable)
4. **Testing**: Look at tests/Feature/HR/ directory for test examples

---

**Last Updated**: 2026-04-05
**Status**: ✅ Production Ready
**Recommendation**: Implement caching & optimize queries for 1000+ employee scale

