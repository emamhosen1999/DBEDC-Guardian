# Architectural Blueprint & Refactoring Plan

**Date:** January 2025  
**Based On:** Full-Stack Audit Report  
**Objective:** Provide exact file modifications and unified patterns to enforce across all layers

---

## Phase 1: Critical Foundation (Week 1-2)

### 1.1 Backend: Global Exception Handler

**File:** `app/Exceptions/Handler.php`

**Changes:**
- Implement standardized JSON API exception handler
- Add logging for all exceptions
- Return consistent error response structure

**Pattern to Enforce:**
```php
// Standard API Error Response
{
  "success": false,
  "message": "Human-readable error message",
  "error_code": "SPECIFIC_ERROR_CODE",
  "errors": { // validation errors only
    "field": ["error message"]
  }
}
```

---

### 1.2 Backend: API Response Standardization

**Files to Modify:**
- `app/Http/Controllers/Api/V1/AttendanceController.php`
- `app/Http/Controllers/Api/V1/DailyWorkController.php`
- `app/Http/Controllers/Api/V1/LeaveController.php`
- `app/Http/Controllers/Api/V1/AuthController.php`
- All other API controllers in `Api/V1/`

**Changes:**
- Create `app/Http/Responses/ApiResponse.php` trait
- Standardize all responses to use `success`, `data`, `message` structure
- Remove inconsistent response patterns

**Pattern to Enforce:**
```php
// Success Response
return response()->json([
    'success' => true,
    'data' => $data,
    'message' => $optionalMessage,
], 200);

// Error Response
return response()->json([
    'success' => false,
    'message' => $message,
    'error_code' => $code,
], $statusCode);
```

---

### 1.3 Backend: Rate Limiting

**File:** `routes/api.php`

**Changes:**
- Add `throttle:api` middleware to all API routes
- Configure rate limits in `app/Providers/RouteServiceProvider.php`

**Pattern to Enforce:**
```php
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // All API routes
});
```

---

### 1.4 Web Frontend: ErrorBoundary at Route Level

**File:** `resources/js/app.jsx`

**Changes:**
- Wrap entire app in ErrorBoundary
- Ensure all route segments have ErrorBoundary

**Pattern to Enforce:**
```jsx
<ErrorBoundary fallbackTitle="Application Error">
  <InertiaApp />
</ErrorBoundary>
```

---

### 1.5 Mobile Frontend: ErrorBoundary Implementation

**File:** `app/_layout.js`

**Changes:**
- Create `components/ErrorBoundary.js` (React Native compatible)
- Wrap all screens in ErrorBoundary

**Pattern to Enforce:**
```jsx
<ErrorBoundary fallback={<ErrorFallback />}>
  <Stack.Navigator>{/* screens */}</Stack.Navigator>
</ErrorBoundary>
```

---

### 1.6 Security: Token Storage Fix

**File:** `src/auth/AuthContext.js` (mobile)

**Changes:**
- Implement secure token storage using expo-secure-store
- Remove localStorage usage

**Pattern to Enforce:**
```javascript
import * as SecureStore from 'expo-secure-store';

// Store token
await SecureStore.setItemAsync('authToken', token);

// Retrieve token
const token = await SecureStore.getItemAsync('authToken');
```

---

## Phase 2: High Priority Architecture (Week 3-4)

### 2.1 Backend: Repository Pattern

**Files to Create:**
- `app/Repositories/Contracts/RepositoryInterface.php`
- `app/Repositories/BaseRepository.php`
- `app/Repositories/AttendanceRepository.php`
- `app/Repositories/DailyWorkRepository.php`
- `app/Repositories/LeaveRepository.php`

**Files to Modify:**
- All API controllers to use repositories instead of direct model queries

**Pattern to Enforce:**
```php
// Repository Interface
interface RepositoryInterface {
    public function all(array $filters = []);
    public function find(int $id);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function paginate(int $perPage = 15);
}

// Controller Usage
public function index(Request $request, AttendanceRepository $repository) {
    return $repository->paginate($request->input('per_page', 15));
}
```

---

### 2.2 Backend: API Resource Transformers

**Files to Create:**
- `app/Http/Resources/Api/AttendanceResource.php`
- `app/Http/Resources/Api/DailyWorkResource.php`
- `app/Http/Resources/Api/LeaveResource.php`
- `app/Http/Resources/Api/UserResource.php`

**Files to Modify:**
- All API controllers to return resources instead of raw data

**Pattern to Enforce:**
```php
// Resource Definition
class AttendanceResource extends JsonResource {
    public function toArray($request) {
        return [
            'id' => $this->id,
            'date' => $this->date->format('Y-m-d'),
            'punchin_time' => $this->punchin?->format('H:i:s'),
            'punchout_time' => $this->punchout?->format('H:i:s'),
            'user' => new UserResource($this->whenLoaded('user')),
        ];
    }
}

// Controller Usage
return response()->json([
    'success' => true,
    'data' => AttendanceResource::collection($attendances),
]);
```

---

### 2.3 Web Frontend: React Query Integration

**Files to Create:**
- `resources/js/api/reactQueryClient.js`
- `resources/js/api/queries/useAttendanceQuery.js`
- `resources/js/api/queries/useDailyWorksQuery.js`
- `resources/js/api/queries/useLeavesQuery.js`
- `resources/js/api/mutations/useAttendanceMutation.js`

**Files to Modify:**
- `resources/js/app.jsx` - Add QueryClientProvider
- All page components to use React Query hooks instead of manual useState/axios

**Pattern to Enforce:**
```javascript
// Query Hook
export const useAttendanceToday = () => {
    return useQuery({
        queryKey: ['attendance', 'today'],
        queryFn: () => fetchAttendanceToday(),
        staleTime: 5 * 60 * 1000, // 5 minutes
    });
};

// Component Usage
const { data, isLoading, error } = useAttendanceToday();
```

---

### 2.4 Web Frontend: TypeScript Migration

**Files to Create:**
- `resources/js/types/api.ts` - API response types
- `resources/js/types/attendance.ts` - Attendance types
- `resources/js/types/daily-work.ts` - Daily work types
- `resources/js/types/leave.ts` - Leave types

**Files to Modify:**
- Rename `.jsx` files to `.tsx` gradually
- Add type annotations to all components
- Update `tsconfig.json` configuration

**Pattern to Enforce:**
```typescript
// API Response Type
interface ApiResponse<T> {
    success: boolean;
    data: T;
    message?: string;
}

// Attendance Type
interface Attendance {
    id: number;
    date: string;
    punchin_time: string | null;
    punchout_time: string | null;
    user: User;
}

// Component Props
interface DailyTimesheetTabProps {
    selectedDate: Date;
    onDateChange: (date: Date) => void;
    isActive?: boolean;
}
```

---

### 2.5 Mobile Frontend: Centralized Data Fetching

**Files to Create:**
- `src/api/reactQueryClient.js` (React Query for React Native)
- `src/api/queries/useMobileAttendanceQuery.js`
- `src/api/queries/useMobileLeavesQuery.js`
- `src/api/mutations/useMobilePunchMutation.js`

**Files to Modify:**
- `app/_layout.js` - Add QueryClientProvider
- All screen components to use React Query hooks

**Pattern to Enforce:**
```javascript
// Same pattern as web, using React Query for React Native
import { useQuery } from '@tanstack/react-query';

export const useAttendanceToday = (config) => {
    return useQuery({
        queryKey: ['attendance', 'today'],
        queryFn: () => fetchAttendanceToday(config),
    });
};
```

---

### 2.6 Backend: Service Layer Completion

**Files to Create:**
- `app/Services/Attendance/AttendanceQueryService.php`
- `app/Services/Attendance/AttendanceValidationService.php`
- `app/Services/DailyWork/DailyWorkQueryService.php`
- `app/Services/Leave/LeaveQueryService.php`

**Files to Modify:**
- Move business logic from controllers to services
- Controllers become thin, delegating to services

**Pattern to Enforce:**
```php
// Service Class
class AttendanceQueryService {
    public function getTodayAttendance(User $user): array {
        // Business logic here
    }
}

// Controller Usage
public function today(Request $request, AttendanceQueryService $service) {
    $data = $service->getTodayAttendance($request->user());
    return response()->json([
        'success' => true,
        'data' => $data,
    ]);
}
```

---

## Phase 3: Medium Priority Architecture (Week 5-8)

### 3.1 Web Frontend: Zustand State Management

**Files to Create:**
- `resources/js/store/index.ts`
- `resources/js/store/authStore.ts`
- `resources/js/store/uiStore.ts`
- `resources/js/store/attendanceStore.ts`

**Files to Modify:**
- Remove local state for shared data
- Use Zustand stores for global state

**Pattern to Enforce:**
```typescript
// Store Definition
interface AuthState {
    user: User | null;
    setUser: (user: User | null) => void;
}

const useAuthStore = create<AuthState>((set) => ({
    user: null,
    setUser: (user) => set({ user }),
}));

// Component Usage
const { user, setUser } = useAuthStore();
```

---

### 3.2 Mobile Frontend: Zustand State Management

**Files to Create:**
- `src/store/index.ts`
- `src/store/authStore.ts`
- `src/store/locationStore.ts`

**Pattern to Enforce:**
- Same as web, using Zustand for React Native

---

### 3.3 Backend: Caching Strategy

**File:** `app/Providers/CacheServiceProvider.php`

**Changes:**
- Implement Redis caching for read-heavy endpoints
- Add cache tags for invalidation
- Configure cache TTL per endpoint type

**Pattern to Enforce:**
```php
// Cache Usage
public function getTodayAttendance(User $user) {
    return Cache::tags(['attendance', "user:{$user->id}"])
        ->remember("attendance:today:{$user->id}", 300, function () use ($user) {
            return Attendance::where('user_id', $user->id)
                ->whereDate('date', today())
                ->get();
        });
}

// Cache Invalidation
Cache::tags(['attendance', "user:{$userId}"])->flush();
```

---

### 3.4 Backend: Pagination Standardization

**File:** `app/Http/Resources/Api/PaginationResource.php`

**Changes:**
- Create standard pagination resource
- All list endpoints return consistent pagination structure

**Pattern to Enforce:**
```php
// Standard Pagination Response
{
    "success": true,
    "data": [...],
    "pagination": {
        "current_page": 1,
        "last_page": 10,
        "per_page": 25,
        "total": 250,
        "from": 1,
        "to": 25
    }
}
```

---

### 3.5 Integration: OpenAPI Specification

**File:** `openapi.yaml` (root of project)

**Changes:**
- Document all API endpoints with OpenAPI 3.0 spec
- Include request/response schemas
- Generate TypeScript types from OpenAPI spec

**Pattern to Enforce:**
```yaml
paths:
  /api/v1/attendance/today:
    get:
      summary: Get today's attendance
      responses:
        '200':
          description: Success
          content:
            application/json:
              schema:
                type: object
                properties:
                  success:
                    type: boolean
                  data:
                    $ref: '#/components/schemas/AttendanceResponse'
```

---

### 3.6 Backend: Database Transactions

**Files to Modify:**
- All write operations in controllers/services

**Pattern to Enforce:**
```php
// Transaction Pattern
DB::transaction(function () use ($data) {
    $attendance = Attendance::create($data);
    // Related operations
    $attendance->punches()->create($punchData);
});
```

---

## Phase 4: Low Priority Enhancements (Week 9-12)

### 4.1 Web Frontend: Code Splitting

**File:** `vite.config.js`

**Changes:**
- Implement route-based code splitting
- Lazy load heavy components

**Pattern to Enforce:**
```javascript
// Lazy Loading
const DailyTimesheetTab = lazy(() => import('./Pages/Attendance/DailyTimesheetTab'));

<Suspense fallback={<Skeleton />}>
    <DailyTimesheetTab />
</Suspense>
```

---

### 4.2 Backend: API Version Deprecation

**File:** `routes/api.php`

**Changes:**
- Add version headers
- Implement deprecation warnings
- Document version lifecycle

**Pattern to Enforce:**
```php
Route::prefix('v1')->middleware(['api-deprecated-warning'])->group(function () {
    // v1 routes
});

Route::prefix('v2')->group(function () {
    // v2 routes
});
```

---

### 4.3 Mobile Frontend: Offline Support

**Files to Create:**
- `src/services/offlineStorage.js`
- `src/services/syncService.js`

**Pattern to Enforce:**
- Store data locally using AsyncStorage
- Sync when connection restored
- Queue offline mutations

---

### 4.4 Backend: Background Jobs

**Files to Create:**
- `app/Jobs/SendNotificationJob.php`
- `app/Jobs/ProcessAttendanceJob.php`

**Pattern to Enforce:**
```php
// Queue Heavy Operations
ProcessAttendanceJob::dispatch($attendanceId);
```

---

## Unified Patterns Summary

### API Response Pattern
All API endpoints MUST return:
```json
{
  "success": boolean,
  "data": object|array|null,
  "message": string|null,
  "error_code": string|null,
  "errors": object|null
}
```

### Error Handling Pattern
- Backend: Global exception handler with consistent error codes
- Web: ErrorBoundary at route level with fallback UI
- Mobile: ErrorBoundary at screen level with fallback UI

### State Management Pattern
- Web: Zustand for global state, React Query for server state
- Mobile: Zustand for global state, React Query for server state

### Data Fetching Pattern
- Use React Query hooks for all API calls
- Implement request cancellation on unmount
- Add optimistic updates for mutations

### Type Safety Pattern
- Web: TypeScript for all new code, gradual migration
- Mobile: TypeScript for all new code
- Shared: OpenAPI spec generates types for both

### Repository Pattern
- All data access through repository interfaces
- Controllers delegate to services, services use repositories
- Repositories handle Eloquent queries

### Resource Pattern
- All API responses use Laravel API Resources
- Consistent field naming (camelCase in JSON)
- Conditional relationship loading

### Caching Pattern
- Cache tags for invalidation
- Appropriate TTL per data type
- Cache-first for read-heavy data

### Logging Pattern
- Structured logging with context
- Log levels: debug, info, warning, error, critical
- Include user_id, request_id in all logs

---

## File Modification Checklist

### Backend Files (18 files)
- [ ] `app/Exceptions/Handler.php` - Global exception handler
- [ ] `app/Http/Responses/ApiResponse.php` - Create response trait
- [ ] `app/Repositories/Contracts/RepositoryInterface.php` - Create
- [ ] `app/Repositories/BaseRepository.php` - Create
- [ ] `app/Repositories/AttendanceRepository.php` - Create
- [ ] `app/Repositories/DailyWorkRepository.php` - Create
- [ ] `app/Repositories/LeaveRepository.php` - Create
- [ ] `app/Http/Resources/Api/AttendanceResource.php` - Create
- [ ] `app/Http/Resources/Api/DailyWorkResource.php` - Create
- [ ] `app/Http/Resources/Api/LeaveResource.php` - Create
- [ ] `app/Http/Resources/Api/UserResource.php` - Create
- [ ] `app/Http/Resources/Api/PaginationResource.php` - Create
- [ ] `app/Services/Attendance/AttendanceQueryService.php` - Create
- [ ] `app/Services/Attendance/AttendanceValidationService.php` - Create
- [ ] `app/Services/DailyWork/DailyWorkQueryService.php` - Create
- [ ] `app/Services/Leave/LeaveQueryService.php` - Create
- [ ] `app/Providers/CacheServiceProvider.php` - Create/modify
- [ ] `routes/api.php` - Add rate limiting middleware

### Web Frontend Files (15 files)
- [ ] `resources/js/app.jsx` - Add ErrorBoundary, QueryClientProvider
- [ ] `resources/js/api/reactQueryClient.js` - Create
- [ ] `resources/js/api/queries/useAttendanceQuery.js` - Create
- [ ] `resources/js/api/queries/useDailyWorksQuery.js` - Create
- [ ] `resources/js/api/queries/useLeavesQuery.js` - Create
- [ ] `resources/js/api/mutations/useAttendanceMutation.js` - Create
- [ ] `resources/js/store/index.ts` - Create
- [ ] `resources/js/store/authStore.ts` - Create
- [ ] `resources/js/store/uiStore.ts` - Create
- [ ] `resources/js/store/attendanceStore.ts` - Create
- [ ] `resources/js/types/api.ts` - Create
- [ ] `resources/js/types/attendance.ts` - Create
- [ ] `resources/js/types/daily-work.ts` - Create
- [ ] `resources/js/types/leave.ts` - Create
- [ ] `vite.config.js` - Add code splitting config

### Mobile Frontend Files (12 files)
- [ ] `app/_layout.js` - Add ErrorBoundary, QueryClientProvider
- [ ] `components/ErrorBoundary.js` - Create
- [ ] `src/auth/AuthContext.js` - Fix token storage
- [ ] `src/api/reactQueryClient.js` - Create
- [ ] `src/api/queries/useMobileAttendanceQuery.js` - Create
- [ ] `src/api/queries/useMobileLeavesQuery.js` - Create
- [ ] `src/api/mutations/useMobilePunchMutation.js` - Create
- [ ] `src/store/index.ts` - Create
- [ ] `src/store/authStore.ts` - Create
- [ ] `src/store/locationStore.ts` - Create
- [ ] `src/services/offlineStorage.js` - Create
- [ ] `src/services/syncService.js` - Create

### Integration Files (3 files)
- [ ] `openapi.yaml` - Create API specification
- [ ] `resources/js/types/generated.ts` - Generate from OpenAPI
- [ ] `src/types/generated.ts` - Generate from OpenAPI

---

## Migration Strategy

### Incremental Approach
1. **Phase 1** addresses critical security and stability issues
2. **Phase 2** establishes architectural foundations
3. **Phase 3** enhances performance and maintainability
4. **Phase 4** adds polish and long-term improvements

### Backward Compatibility
- API v1 endpoints remain functional during migration
- New endpoints follow new patterns
- Gradual client-side migration
- Feature flags for new features

### Testing Strategy
- Write tests before refactoring (TDD where possible)
- Integration tests for API contracts
- E2E tests for critical user flows
- Performance benchmarks before/after

---

## Approval Required

**Before proceeding with any code changes, user must approve:**
1. This architectural blueprint
2. The prioritized phases
3. The unified patterns to enforce

**After approval:**
- Execute Phase 1 changes first
- Test thoroughly after each phase
- Update this blueprint as needed during implementation
