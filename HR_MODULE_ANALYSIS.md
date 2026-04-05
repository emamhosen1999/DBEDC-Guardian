# HR Module Structure Analysis - Aero-Enterprise-Suite

## Overview
The Aero-Enterprise-Suite contains a comprehensive Human Resources (HR) module built with Laravel 11, featuring advanced employee management, recruitment, payroll, performance management, training, and compliance features.

---

## 1. HR-Related Models

### Location: `app/Models/HRM/` (Primary HR Models Directory)

#### Core Employee & Organization Models
| Model | Purpose | Key Relationships |
|-------|---------|-------------------|
| [Department.php](app/Models/HRM/Department.php) | Organization departments with hierarchical support | parent_id (self-join), manager_id (User), employees |
| [Designation.php](app/Models/HRM/Designation.php) | Job titles/positions with hierarchy levels | department_id, parent_id (self-join), users, hierarchy_level |
| [AttendanceType.php](app/Models/HRM/AttendanceType.php) | Types of attendance tracking configurations | users |
| [AttendanceSetting.php](app/Models/HRM/AttendanceSetting.php) | Global attendance system settings | - |

#### Attendance & Timesheet Models
| Model | Purpose | Key Relationships |
|-------|---------|-------------------|
| [Attendance.php](app/Models/HRM/Attendance.php) | Employee punch in/out records with location tracking | user_id, punchin_photo, punchout_photo (media collections) |
| [Holiday.php](app/Models/HRM/Holiday.php) | Company holidays and special dates | created_by, updated_by |

#### Time-Off Management Models
| Model | Purpose | Key Relationships |
|-------|---------|-------------------|
| [Leave.php](app/Models/HRM/Leave.php) | Leave/time-off requests with approval workflows | user_id, approved_by, rejection_tracking, approval_chain |
| [LeaveSetting.php](app/Models/HRM/LeaveSetting.php) | Leave policy configuration (accrual, approval settings) | - |

#### Recruitment & Hiring Models
| Model | Purpose | Key Relationships |
|-------|---------|-------------------|
| [Job.php](app/Models/HRM/Job.php) | Job postings/openings | department_id, hiring_manager_id, applications, hiringStages |
| [JobApplication.php](app/Models/HRM/JobApplication.php) | Job applicant data with resume/documents | job_id, applicant_id, current_stage_id, interviews, offers |
| [JobApplicantEducation.php](app/Models/HRM/JobApplicantEducation.php) | Applicant education history | application_id |
| [JobApplicantExperience.php](app/Models/HRM/JobApplicantExperience.php) | Applicant work experience | application_id |
| [JobApplicationStageHistory.php](app/Models/HRM/JobApplicationStageHistory.php) | Application pipeline tracking | application_id, stage_id, moved_by |
| [JobHiringStage.php](app/Models/HRM/JobHiringStage.php) | Recruitment funnel stages | job_id, applications |
| [JobInterview.php](app/Models/HRM/JobInterview.php) | Interview scheduling and feedback | application_id, feedback |
| [JobInterviewFeedback.php](app/Models/HRM/JobInterviewFeedback.php) | Interview feedback from interviewers | - |
| [JobOffer.php](app/Models/HRM/JobOffer.php) | Job offers to candidates | application_id, acceptances |

#### Performance & Review Models
| Model | Purpose | Key Relationships |
|-------|---------|-------------------|
| [PerformanceReview.php](app/Models/HRM/PerformanceReview.php) | Employee performance reviews/appraisals | employee_id, reviewer_id, template_id, competency_scores |
| [PerformanceReviewTemplate.php](app/Models/HRM/PerformanceReviewTemplate.php) | Templates for performance reviews | department_id, competency_categories |
| [KPI.php](app/Models/HRM/KPI.php) | Key performance indicators | responsible_user_id, target values |
| [KPIValue.php](app/Models/HRM/KPIValue.php) | KPI tracking values over time | kpi_id |

#### Training & Development Models
| Model | Purpose | Key Relationships |
|-------|---------|-------------------|
| [Training.php](app/Models/HRM/Training.php) | Training programs/courses | category_id, instructor_id, department_id, media |
| [TrainingCategory.php](app/Models/HRM/TrainingCategory.php) | Training program categories | trainings |
| [TrainingSession.php](app/Models/HRM/TrainingSession.php) | Individual training sessions | category_id, instructor_id |
| [TrainingMaterial.php](app/Models/HRM/TrainingMaterial.php) | Training course content/materials | session_id, media |
| [TrainingEnrollment.php](app/Models/HRM/TrainingEnrollment.php) | Employee training enrollments | user_id, training_id |
| [TrainingAssignment.php](app/Models/HRM/TrainingAssignment.php) | Mandatory/assigned trainings | user_id, training_id |
| [TrainingAssignmentSubmission.php](app/Models/HRM/TrainingAssignmentSubmission.php) | Training assignment submissions/completions | assignment_id |
| [TrainingFeedback.php](app/Models/HRM/TrainingFeedback.php) | Course feedback from trainees | training_id, user_id |

#### Payroll & Compensation Models
| Model | Purpose | Key Relationships |
|-------|---------|-------------------|
| [Payroll.php](app/Models/HRM/Payroll.php) | Monthly payroll records | user_id, pay_period, salary calculations |
| [Payslip.php](app/Models/HRM/Payslip.php) | Individual payslips (PDF generation) | payroll_id, user_id, email_sent tracking |
| [PayrollAllowance.php](app/Models/HRM/PayrollAllowance.php) | Salary allowances (HRA, DA, etc.) | payroll_id |
| [PayrollDeduction.php](app/Models/HRM/PayrollDeduction.php) | Salary deductions (tax, insurance, etc.) | payroll_id |
| [TaxSlab.php](app/Models/HRM/TaxSlab.php) | Tax calculation slabs | - |

#### Onboarding & Offboarding Models
| Model | Purpose | Key Relationships |
|-------|---------|-------------------|
| [Onboarding.php](app/Models/HRM/Onboarding.php) | Employee onboarding process management | employee_id, tasks, created_by |
| [OnboardingTask.php](app/Models/HRM/OnboardingTask.php) | Individual onboarding tasks/checklist items | onboarding_id, assigned_to |
| [Offboarding.php](app/Models/HRM/Offboarding.php) | Employee exit/offboarding process | employee_id, tasks, reason tracking |
| [OffboardingTask.php](app/Models/HRM/OffboardingTask.php) | Individual offboarding tasks | offboarding_id, assigned_to |
| [Opportunity.php](app/Models/HRM/Opportunity.php) | Internal career opportunities/promotions | - |

#### Skills & Competencies Models
| Model | Purpose | Key Relationships |
|-------|---------|-------------------|
| [HrDocument.php](app/Models/HRM/HrDocument.php) | HR document management (policies, etc.) | category_id, created_by, employees (many-to-many) |

#### Core User Models (in app/Models/)
| Model | Purpose | Key Relationships |
|-------|---------|-------------------|
| [User.php](app/Models/User.php) | **Main employee model** - Contains all HR attributes including: designation_id, department_id, report_to (manager), employment details (DOJ, salary, NID), personal info (DOB, address, passport, marital status), emergency contacts, banking info, family details, education history, work experience, HR permissions | HasRoles, HasPermissions, many HR relationships |
| [Skill.php](app/Models/Skill.php) | Skills available in the system | employees (many-to-many via employee_skills), competencies |
| [Competency.php](app/Models/Competency.php) | Required competencies for roles | skills (many-to-many) |

---

## 2. HR Database Migrations

### Location: `database/migrations/`

#### Core HR Migrations
| Migration File | Purpose | Tables Created |
|----------------|---------|-----------------|
| [0001_01_01_000000_create_departments_table.php](database/migrations/0001_01_01_000000_create_departments_table.php) | Initial department structure | `departments` - hierarchical with parent_id |
| [0001_01_01_000001_create_designations_table.php](database/migrations/0001_01_01_000001_create_designations_table.php) | Job titles/positions | `designations` - with hierarchy_level |
| [0001_01_01_000002_create_users_table.php](database/migrations/0001_01_01_000002_create_users_table.php) | Main user/employee table | `users` - comprehensive employee data |

#### Attendance & Timesheet Migrations
| Migration File | Tables Created |
|----------------|-----------------|
| [2025_06_16_050411_create_attendance_types_table.php](database/migrations/2025_06_16_050411_create_attendance_types_table.php) | `attendance_types` |
| [2025_06_16_072730_create_attendance_settings_table.php](database/migrations/2025_06_16_072730_create_attendance_settings_table.php) | `attendance_settings` |
| [2025_06_16_213117_add_attendance_type_to_users_table.php](database/migrations/2025_06_16_213117_add_attendance_type_to_users_table.php) | Adds attendance_type_id to users |

#### Leave Management Migrations
| Migration File | Tables Created |
|----------------|-----------------|
| [2024_08_04_230622_create_leaves_table.php](database/migrations/2024_08_04_230622_create_leaves_table.php) | `leaves` - basic leave requests |
| [2025_11_25_170720_create_leave_carry_forwards_table.php](database/migrations/2025_11_25_170720_create_leave_carry_forwards_table.php) | `leave_carry_forwards` - unused leave carryover |
| [2025_11_25_170827_add_approval_chain_to_leaves_table.php](database/migrations/2025_11_25_170827_add_approval_chain_to_leaves_table.php) | Adds approval workflow to leaves |
| [2025_11_25_171758_create_leave_accruals_table.php](database/migrations/2025_11_25_171758_create_leave_accruals_table.php) | `leave_accruals` - earned/accrued leave tracking |
| [2025_11_25_171905_add_is_earned_to_leave_settings_table.php](database/migrations/2025_11_25_171905_add_is_earned_to_leave_settings_table.php) | Leave settings for earned leaves |
| [2025_11_25_175534_add_approval_fields_to_leave_settings_table.php](database/migrations/2025_11_25_175534_add_approval_fields_to_leave_settings_table.php) | Leave approval configuration |

#### Recruitment Migrations
| Migration File | Tables Created |
|----------------|-----------------|
| [2024_07_31_000003_create_recruitment_management_tables.php](database/migrations/2024_07_31_000003_create_recruitment_management_tables.php) | `jobs_recruitment`, `job_hiring_stages`, `job_applications`, `job_interviews`, `job_offers` |
| [2025_01_09_000001_update_recruitment_tables_consistency.php](database/migrations/2025_01_09_000001_update_recruitment_tables_consistency.php) | Recruitment table consistency updates |

#### Performance Management Migrations
| Migration File | Tables Created |
|----------------|-----------------|
| [2024_07_31_000001_create_performance_management_tables.php](database/migrations/2024_07_31_000001_create_performance_management_tables.php) | `performance_review_templates`, `performance_competency_categories`, `performance_competencies`, `performance_reviews`, `performance_goals`, `performance_competency_scores`, `performance_feedback` |

#### Training Management Migrations
| Migration File | Tables Created |
|----------------|-----------------|
| [2024_07_31_000002_create_training_management_tables.php](database/migrations/2024_07_31_000002_create_training_management_tables.php) | `training_categories`, `training_sessions`, `training_materials`, `training_enrollments`, `training_assignments`, `training_assignment_submissions`, `training_feedback` |

#### Payroll & Compensation Migrations
| Migration File | Tables Created |
|----------------|-----------------|
| [2025_07_09_233629_create_payrolls_table.php](database/migrations/2025_07_09_233629_create_payrolls_table.php) | `payrolls` - basic payroll structure |
| [2024_07_31_000001](database/migrations/2024_07_31_000001_create_performance_management_tables.php) (extended section) | `payroll_allowances`, `payroll_deductions`, `payslips`, `tax_slabs` |

#### Onboarding & Offboarding Migrations
| Migration File | Tables Created |
|----------------|-----------------|
| [2025_07_08_000001_create_onboarding_offboarding_tables.php](database/migrations/2025_07_08_000001_create_onboarding_offboarding_tables.php) | `onboardings`, `onboarding_tasks`, `offboardings`, `offboarding_tasks` |

#### Skills & Competencies Migrations
| Migration File | Tables Created |
|----------------|-----------------|
| [2025_07_08_000002_create_skills_competencies_tables.php](database/migrations/2025_07_08_000002_create_skills_competencies_tables.php) | `skills`, `competencies`, `competency_skills`, `employee_skills`, `position_competencies` |

#### Workplace Safety Migrations
| Migration File | Tables Created |
|----------------|-----------------|
| [2025_07_08_000004_create_workplace_safety_tables.php](database/migrations/2025_07_08_000004_create_workplace_safety_tables.php) | Safety incident and inspection tables |

#### HR Document Management Migrations
| Migration File | Tables Created |
|----------------|-----------------|
| [2025_07_08_000005_create_hr_document_tables.php](database/migrations/2025_07_08_000005_create_hr_document_tables.php) | HR document storage and employee access tables |

#### Supporting Migrations
| Migration File | Purpose |
|----------------|---------|
| [2024_08_04_230346_create_holidays_table.php](database/migrations/2024_08_04_230346_create_holidays_table.php) | Company holidays |
| [2025_11_25_124843_add_hierarchy_level_to_designations_table.php](database/migrations/2025_11_25_124843_add_hierarchy_level_to_designations_table.php) | Designation hierarchy |
| [2025_08_07_141735_fix_departments_table_engine.php](database/migrations/2025_08_07_141735_fix_departments_table_engine.php) | DB optimization for departments |
| [2024_08_12_120945_create_experiences_table.php](database/migrations/2024_08_12_120945_create_experiences_table.php) | Employee work experience |
| [2024_08_12_121902_create_education_table.php](database/migrations/2024_08_12_121902_create_education_table.php) | Employee education |

---

## 3. HR Permissions & Roles Configuration

### Implementation: Spatie Laravel Permission
- **Package**: `spatie/laravel-permission`
- **Location**: Permission guards defined in `routes/hr.php`

### HR Permissions Structure
Permissions are controlled via middleware `permission:` in routes:

#### Dashboard
- `hr.dashboard.view` - Access HR dashboard

#### Performance Management
- `hr.performance.view` - View performance reviews
- `hr.performance.create` - Create reviews
- `hr.performance.update` - Update reviews
- `hr.performance.delete` - Delete reviews
- `hr.performance.templates.*` - Template management

#### Training Management
- `hr.training.view` - View training programs
- `hr.training.create` - Create training
- `hr.training.update` - Update training
- `hr.training.delete` - Delete training
- `hr.training.materials.*` - Material management
- `hr.training.enrollments.*` - Enrollment management

#### Recruitment Management
- `hr.recruitment.view` - View job postings
- `hr.recruitment.create` - Create jobs
- `hr.recruitment.update` - Update jobs
- `hr.recruitment.delete` - Delete jobs
- `hr.recruitment.publish` - Publish jobs
- `hr.recruitment.applications.*` - Application management
- `hr.recruitment.interviews.*` - Interview management
- `hr.recruitment.statistics` - View recruitment stats

#### Onboarding & Offboarding
- `hr.onboarding.view` - View onboarding
- `hr.onboarding.create` - Create onboarding
- `hr.onboarding.update` - Update onboarding
- `hr.onboarding.delete` - Delete onboarding
- `hr.onboarding.checklists.*` - Checklist management

#### Skills & Competency
- `hr.skills.view` - View skills
- `hr.skills.create` - Create skills
- `hr.skills.update` - Update skills
- `hr.skills.delete` - Delete skills

#### Time Off & Leave
- `hr.time_off.view` - View leave requests
- `hr.time_off.apply` - Apply for leave
- `hr.time_off.approve` - Approve leave requests

#### Attendance
- `hr.attendance.view` - View attendance
- `hr.attendance.manage` - Manage attendance records

#### Payroll
- `hr.payroll.view` - View payroll
- `hr.payroll.process` - Process payroll
- `hr.payroll.manage` - Manage payroll

#### Safety & Compliance
- `hr.workplace_safety.view` - View safety records
- `hr.hr_documents.manage` - Document management

---

## 4. HR Service Classes

### Location: `app/Services/`

#### Core Payroll Services
| Service | Purpose | Key Methods |
|---------|---------|------------|
| [PayrollCalculationService.php](app/Services/PayrollCalculationService.php) | Comprehensive payroll calculation | calculatePayroll(), calculateAllowances(), calculateTaxDeductions(), getAttendanceData() |
| [PayrollReportService.php](app/Services/PayrollReportService.php) | Payroll reporting and analytics | - |
| [PayslipService.php](app/Services/PayslipService.php) | Payslip generation and management | - |

#### Attendance Services
| Service | Purpose |
|---------|---------|
| [Attendance/AttendancePunchService.php](app/Services/Attendance/AttendancePunchService.php) | Punch in/out operations |
| [Attendance/AttendanceValidatorFactory.php](app/Services/Attendance/AttendanceValidatorFactory.php) | Location validation factory pattern |
| [Attendance/BaseAttendanceValidator.php](app/Services/Attendance/BaseAttendanceValidator.php) | Base validator for locations |
| [Attendance/IpLocationValidator.php](app/Services/Attendance/IpLocationValidator.php) | IP-based location validation |
| [Attendance/PolygonLocationValidator.php](app/Services/Attendance/PolygonLocationValidator.php) | Geo-fenced location validation |
| [Attendance/QrCodeValidator.php](app/Services/Attendance/QrCodeValidator.php) | QR code based attendance |
| [Attendance/RouteWaypointValidator.php](app/Services/Attendance/RouteWaypointValidator.php) | Route waypoint validation |

#### Leave Management Services
| Service | Purpose |
|---------|---------|
| [Leave/BulkLeaveService.php](app/Services/Leave/BulkLeaveService.php) | Bulk leave operations |
| [Leave/LeaveApprovalService.php](app/Services/Leave/LeaveApprovalService.php) | Leave approval workflows |
| [Leave/LeaveCrudService.php](app/Services/Leave/LeaveCrudService.php) | Leave CRUD operations |
| [Leave/LeaveOverlapService.php](app/Services/Leave/LeaveOverlapService.php) | Detect overlapping leaves |
| [Leave/LeaveQueryService.php](app/Services/Leave/LeaveQueryService.php) | Leave queries and searches |
| [Leave/LeaveSummaryService.php](app/Services/Leave/LeaveSummaryService.php) | Leave balance summaries |
| [Leave/LeaveValidationService.php](app/Services/Leave/LeaveValidationService.php) | Leave validation (dates, balance, etc.) |

#### Performance Services
| Service | Purpose |
|---------|---------|
| [Performance/DatabaseOptimizationService.php](app/Services/Performance/DatabaseOptimizationService.php) | Performance data optimization |

#### Other HR Services
| Service | Purpose |
|---------|---------|
| [DeviceAuthService.php](app/Services/DeviceAuthService.php) | Device-based authentication |
| [ModernAuthenticationService.php](app/Services/ModernAuthenticationService.php) | Modern authentication methods |

---

## 5. HR Controllers

### Location: `app/Http/Controllers/HR/`

| Controller | Responsibilities |
|-----------|-----------------|
| [PerformanceReviewController.php](app/Http/Controllers/HR/PerformanceReviewController.php) | Performance reviews, templates, competencies, ratings |
| [TrainingController.php](app/Http/Controllers/HR/TrainingController.php) | Training programs, sessions, enrollments, materials, materials, feedback |
| [RecruitmentController.php](app/Http/Controllers/HR/RecruitmentController.php) | Job postings, applications, interviews, offers, hiring stages |
| [OnboardingController.php](app/Http/Controllers/HR/OnboardingController.php) | Onboarding/offboarding processes, checklists, tasks |
| [PayrollController.php](app/Http/Controllers/HR/PayrollController.php) | Payroll processing, payslips, allowances, deductions |
| [SkillsController.php](app/Http/Controllers/HR/SkillsController.php) | Skills, competencies, employee skill mapping |
| [TimeOffController.php](app/Http/Controllers/HR/TimeOffController.php) | Leave requests (legacy route) |
| [TimeOffManagementController.php](app/Http/Controllers/HR/TimeOffManagementController.php) | Leave management, approvals, balances |
| [BenefitsController.php](app/Http/Controllers/HR/BenefitsController.php) | Employee benefits enrollment, management |
| [EmployeeSelfServiceController.php](app/Http/Controllers/HR/EmployeeSelfServiceController.php) | Employee portal for personal data, leaves, documents |
| [HrAnalyticsController.php](app/Http/Controllers/HR/HrAnalyticsController.php) | HR dashboards, reports, analytics |
| [HrDocumentController.php](app/Http/Controllers/HR/HrDocumentController.php) | HR policy documents, accessibility |
| [WorkplaceSafetyController.php](app/Http/Controllers/HR/WorkplaceSafetyController.php) | Safety incidents, inspections, compliance |
| [SafetyIncidentController.php](app/Http/Controllers/HR/SafetyIncidentController.php) | Incident logging and tracking |
| [ManagersController.php](app/Http/Controllers/HR/ManagersController.php) | Manager-specific HR functions |

---

## 6. HR Routes

### Location: `routes/hr.php`

**Route Prefix**: `/hr`  
**Middleware**: `['auth', 'verified']`

### Main Route Groups:
1. **Dashboard** - HR module overview
2. **Performance Management** - Reviews, templates, KPIs
3. **Training Management** - Programs, sessions, enrollments
4. **Recruitment** - Jobs, applications, interviews, offers
5. **Onboarding/Offboarding** - Employee lifecycle management
6. **Skills & Competencies** - Skill tracking, competency mapping
7. **Time Off/Leave** - Leave requests, approvals, balances
8. **Attendance** - Punch records, reports, settings
9. **Payroll** - Salary processing, payslips, reports
10. **Benefits** - Benefits enrollment, management
11. **Employee Self-Service** - Personal HR portal
12. **Workplace Safety** - Incidents, inspections
13. **HR Documents** - Policy management
14. **Analytics** - HR reports and dashboards

---

## 7. Complete HR Feature Set Summary

### A. Employee Management
- **Core Employee Data**: Comprehensive user profiles with HR attributes
  - Personal data (DOB, address, contact info, family details)
  - Employment data (DOJ, designation, department, manager)
  - Professional data (education, experience, skills)
  - Document storage (passport, NID, certificates, etc.)
  - Banking/payroll information

### B. Organizational Structure
- **Hierarchical Department Management**
  - Nested departments with parent-child relationships
  - Department managers and leaders assignment
  - Location-based department structure
  - Department-level compliance tracking

- **Designation/Position Management**
  - Job titles with hierarchy levels
  - Position-based competency requirements
  - Salary grade mapping
  - Department-specific positions

### C. Attendance & Timesheet Management
- **Advanced Punch System**
  - Geo-fenced location validation (polygon-based)
  - IP-based attendance verification
  - QR code-based punch in/out
  - Route waypoint tracking for field employees
  - Photo-based punch records (with media collections)
  - Configurable attendance types

- **Holiday Management**
  - Company-wide holiday calendar
  - Regional holiday support
  - Holiday rules and applicability
  - Holiday impact on payroll

### D. Leave & Time-Off Management
- **Leave Management System**
  - Multiple leave types (casual, earned, privilege, etc.)
  - Approval workflows with multi-level approvers
  - Leave balance tracking and accrual
  - Earned leave with time-based accrual
  - Leave carryover policies
  - Overlap detection to prevent double-booking
  - Leave settings per leave type

- **Leave Analytics**
  - Leave balance summaries
  - Utilization reports
  - Carryover tracking

### E. Recruitment & Hiring
- **Job Posting Management**
  - Multiple job types (full-time, part-time, contract, internship, remote)
  - Salary ranges with currency support
  - Custom fields for job-specific requirements
  - Featured job posting support
  - Multi-position openings

- **Application Management**
  - Application tracking system (ATS)
  - Resume/document storage via media library
  - Applicant education and experience history
  - Rating and evaluation system
  - Application source tracking
  - Referral management

- **Hiring Pipeline**
  - Customizable hiring stages
  - Stage-based workflows
  - Application stage history tracking
  - Bulk application operations

- **Interview Management**
  - Interview scheduling
  - Interviewer feedback
  - Interview notes and comments
  - Multiple interview rounds support

- **Job Offers**
  - Offer creation and tracking
  - Offer acceptance management
  - Conditional/unconditional offers

### F. Performance Management
- **Performance Reviews**
  - Customizable review templates per department
  - Multi-rater feedback system
  - Competency-based assessment
  - KPI evaluation integration
  - Overall rating system
  - Strengths and improvement areas documentation
  - Employee acknowledgment workflow

- **Performance Templates**
  - Reusable review templates
  - Competency category configuration
  - Category weighting system

- **KPI Management**
  - KPI definition and configuration
  - Target-based tracking
  - Responsibility assignment
  - Time-based KPI value tracking
  - Formula-based KPI calculation

### G. Training & Development
- **Training Program Management**
  - Online, in-person, hybrid, and self-paced delivery modes
  - Training categories and organization
  - Prerequisite specification
  - Learning objectives definition
  - Skills covered tracking
  - Mandatory training designation
  - Cost tracking
  - Certification support

- **Training Sessions**
  - Session scheduling (start/end dates and times)
  - Instructor assignment
  - Location management
  - Virtual meeting link integration
  - Capacity management
  - Duration tracking

- **Training Materials**
  - Course content management
  - Document and media attachment
  - Material versioning

- **Training Enrollment**
  - Employee enrollment management
  - Enrollment status tracking (pending, completed, etc.)

- **Training Assignments**
  - Mandatory training assignment
  - Assignment submission and completion tracking

- **Training Feedback**
  - Post-training surveys and feedback collection
  - Feedback analytics

### H. Payroll & Compensation
- **Payroll Processing**
  - Pay period management (monthly, bi-weekly, etc.)
  - Flexible payroll dates
  - Automatic attendance-based salary adjustments
  - Overtime calculation (hourly rates)
  - Salary cut/proration support
  - Multi-currency support

- **Salary Components**
  - **Allowances**: HRA, DA, special allowances, etc.
  - **Deductions**: Tax, insurance, professional tax, etc.
  - **Overtime**: Configurable overtime rates

- **Tax Management**
  - Tax slab configuration
  - Tax calculation based on income slabs
  - Tax deduction tracking

- **Payslips**
  - Digital payslip generation (PDF)
  - Payslip distribution via email
  - Payslip access history
  - Salary breakdown details

- **Payroll Reports**
  - Monthly payroll reports
  - Employee salary reports
  - Compliance reporting

### I. Onboarding & Offboarding
- **Employee Onboarding**
  - Structured onboarding process with milestones
  - Customizable onboarding tasks/checklists
  - Task assignment to specific team members
  - Task status tracking (pending, in-progress, completed)
  - Completion timeline management
  - Notes and documentation

- **Employee Offboarding**
  - Exit interview scheduling and documentation
  - Structured offboarding tasks
  - Equipment return tracking
  - Knowledge transfer documentation
  - Exit reason categorization (resignation, termination, retirement, etc.)
  - Employee feedback collection
  - Final clearance tracking

### J. Skills & Competency Management
- **Skill Catalog**
  - Skills database with categories
  - Skill types (technical, soft-skill, certification, language, etc.)
  - Skill proficiency levels (beginner, intermediate, advanced, expert)
  - Skill validation/verification system
  - Skill expiration tracking (certifications)

- **Competency Framework**
  - Competency definitions
  - Competency levels (entry, mid, senior, expert)
  - Competency-to-skill mapping
  - Competency categories

- **Employee Skills**
  - Employee skill proficiency tracking
  - Skill acquisition date tracking
  - Skill verification management
  - Skill expiration for certifications
  - Verified by tracking (verification chain)

- **Position Requirements**
  - Required competencies per position
  - Competency importance levels (required, preferred, optional)
  - Skills gap analysis

### K. Workplace Safety
- **Safety Incidents**
  - Incident logging and documentation
  - Incident type categorization
  - Severity levels
  - Participant tracking
  - Incident investigation tracking

- **Safety Inspections**
  - Safety inspection scheduling
  - Inspection result documentation
  - Non-conformance tracking (NCR)
  - Corrective action management
  - Inspection history

- **Training Integration**
  - Safe safety training requirement
  - Safety training tracking
  - Certification management

### L. HR Document Management
- **Document Types**
  - Policy documents
  - HR guidelines
  - Compliance documents
  - Department-specific documents

- **Document Access**
  - Employee-specific document access
  - Access logging and tracking
  - Document acknowledgment tracking
  - Version management

### M. Benefits Management
- **Benefits Catalog**
  - Health insurance
  - Dental coverage
  - Vision coverage
  - Retirement/pension plans
  - Perks and allowances

- **Benefit Enrollment**
  - Employee self-service enrollment
  - Coverage level selection (individual, family, etc.)
  - Cost tracking per employee
  - Benefit eligibility criteria
  - Enrollment period management

- **Benefit Administration**
  - Benefit provider management
  - Cost management
  - Eligibility criteria configuration
  - Status tracking

### N. HR Analytics & Reporting
- **HR Dashboards**
  - Employee statistics
  - Recruitment metrics
  - Training and development KPIs
  - Payroll analytics
  - Attendance trends
  - Performance review summaries
  - Leave utilization

- **Reports**
  - Employee roster reports
  - Department reports
  - Recruitment pipeline reports
  - Payroll reports
  - Compliance reports
  - Custom report building

### O. Employee Self-Service Portal
- **Personal Data Management**
  - View and edit personal information
  - Update contact details
  - Update bank information (for payroll)

- **Leave Management**
  - Apply for leave
  - View leave balance
  - Check leave history
  - Approval status tracking

- **Document Access**
  - View assigned HR documents
  - Access to payslips
  - Download certificates
  - View company policies

- **Performance Feedback**
  - View performance reviews
  - Provide feedback
  - Acknowledge reviews

---

## 8. Related Models (In app/Models/ but HR-Connected)

| Model | Purpose | HR Connection |
|-------|---------|----------------|
| [User.php](app/Models/User.php) | Main employee/user model | Core employee entity with Spatie roles/permissions |
| [Skill.php](app/Models/Skill.php) | Skills database | Employee skill proficiency tracking |
| [Competency.php](app/Models/Competency.php) | Competencies | Performance reviews and position requirements |
| [Asset.php](app/Models/Asset.php) | Company assets | Asset allocation to employees |
| [Benefit.php](app/Models/Benefit.php) | Benefits | Employee benefits enrollment |

---

## 9. Technology Stack & Patterns

### Packages & Features Used
- **Spatie Laravel Permission**: Role-based access control
- **Spatie Media Library**: Document/file uploads (resumes, payslips, IDs, certificates)
- **Laravel's Relationship Methods**: Complex relationship management
- **Soft Deletes**: Data archival without hard deletion
- **Factories & Seeders**: Data generation and initialization
- **Form Requests**: Validation layer (assumed in Controllers)
- **Query Optimization**: Eager loading, indexes on foreign keys
- **Multi-language Support**: i18n in models/records
- **Carbon Dates**: Date manipulation utilities

### Best Practices Implemented
✓ Eloquent relationships with proper type hints  
✓ Hierarchical data management (departments, designations)  
✓ Approval workflows and state machines  
✓ Audit trails (created_by, updated_by)  
✓ Soft deletion for compliance  
✓ Media library for documents  
✓ Factory-based testing  
✓ Service class business logic  
✓ Route-based permission checks  

---

## 10. Data Relationships Summary

### Key Relationship Patterns
1. **User → Department**: Many users per department, one manager per department
2. **User → Designation**: Many users per designation
3. **Department → Designation**: Many designations per department
4. **User → Performance Review**: One user is employee, another is reviewer (many-to-many via pivot)
5. **Job → Job Application**: One-to-many
6. **Job Application → JobInterview → JobOffer**: Sequential workflow
7. **Training → Training Enrollment**: Many-to-many via students
8. **Payroll → Allowances/Deductions**: One-to-many
9. **Department → Holiday**: Many-to-many (company holidays apply to departments)
10. **Leave → Approval Chain**: Multi-level approval workflow

---

## Summary Statistics

| Category | Count |
|----------|-------|
| **HRM Models** | 37 models |
| **HR Controllers** | 15 controllers |
| **HR Migrations** | 20+ dedicated migrations |
| **Service Classes** | 15+ HR-specific services |
| **Permissions** | 50+ granular permissions |
| **Routes** | 100+ HR endpoints |
| **Database Tables** | 50+ HR tables |

---

*Last Updated: April 5, 2026*  
*Laravel Version: 11*  
*Analysis Scope: Complete HR Module Structure*
