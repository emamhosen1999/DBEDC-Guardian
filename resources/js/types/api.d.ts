/**
 * API Response Types
 * JSDoc type definitions for API contracts
 */

/**
 * Standard API Success Response
 * @template T
 * @typedef {Object} ApiResponseSuccess
 * @property {boolean} success - Always true for successful responses
 * @property {string} message - Success message
 * @property {T} data - Response data
 */

/**
 * Standard API Error Response
 * @typedef {Object} ApiResponseError
 * @property {boolean} success - Always false for error responses
 * @property {string} message - Error message
 * @property {string} error_code - Machine-readable error code
 * @property {Object<string, string[]>} [errors] - Validation errors (for 422 responses)
 * @property {string} [redirect] - Redirect URL (for authentication errors)
 */

/**
 * Paginated Response Metadata
 * @typedef {Object} PaginationMeta
 * @property {number} total - Total number of items
 * @property {number} per_page - Items per page
 * @property {number} current_page - Current page number
 * @property {number} last_page - Last page number
 * @property {number|null} from - First item number
 * @property {number|null} to - Last item number
 */

/**
 * Paginated API Response
 * @template T
 * @typedef {Object} PaginatedApiResponse
 * @property {boolean} success - Always true
 * @property {string} message - Success message
 * @property {T[]} data - Array of items
 * @property {PaginationMeta} pagination - Pagination metadata
 */

/**
 * Department Resource
 * @typedef {Object} Department
 * @property {number} id - Department ID
 * @property {string} name - Department name
 * @property {string|null} description - Department description
 * @property {boolean} is_active - Active status
 * @property {string} created_at - Creation timestamp
 * @property {string} updated_at - Update timestamp
 * @property {string|null} deleted_at - Deletion timestamp (if soft deleted)
 */

/**
 * Designation Resource
 * @typedef {Object} Designation
 * @property {number} id - Designation ID
 * @property {string} title - Designation title
 * @property {number|null} hierarchy_level - Hierarchy level (lower = higher rank)
 */

/**
 * Attendance Type Resource
 * @typedef {Object} AttendanceType
 * @property {number} id - Attendance type ID
 * @property {string} name - Attendance type name
 * @property {string|null} slug - URL-friendly slug
 */

/**
 * Role Resource
 * @typedef {Object} Role
 * @property {number} id - Role ID
 * @property {string} name - Role name
 */

/**
 * User/Employee Resource
 * @typedef {Object} User
 * @property {number} id - User ID
 * @property {string} name - Full name
 * @property {string} email - Email address
 * @property {string|null} phone - Phone number
 * @property {string|null} employee_id - Employee ID code
 * @property {string|null} profile_image_url - Profile image URL
 * @property {string|null} user_name - Username
 * @property {string|null} birthday - Birthday
 * @property {string|null} gender - Gender (male, female, other)
 * @property {string|null} address - Address
 * @property {string|null} about - About/bio
 * @property {string|null} date_of_joining - Joining date
 * @property {number|null} report_to - Manager user ID
 * @property {number|null} salary_amount - Salary (only if authorized)
 * @property {boolean} active - Active status
 * @property {boolean} single_device_login_enabled - Single device login enabled
 * @property {Department|null} department - Department info
 * @property {Designation|null} designation - Designation info
 * @property {AttendanceType|null} attendance_type - Attendance type info
 * @property {string[]} roles - Array of role names
 * @property {Object|null} reports_to - Manager info
 * @property {Object|null} active_device - Current device info
 * @property {string} created_at - Creation timestamp
 * @property {string} updated_at - Update timestamp
 * @property {string|null} deleted_at - Deletion timestamp
 * @property {Object|null} can - Authorization flags
 */

/**
 * Employee Statistics
 * @typedef {Object} EmployeeStats
 * @property {Object} overview - Overview statistics
 * @property {number} overview.total_employees - Total employees
 * @property {number} overview.active_employees - Active employees
 * @property {number} overview.inactive_employees - Inactive employees
 * @property {number} overview.total_departments - Total departments
 * @property {number} overview.total_designations - Total designations
 * @property {number} overview.total_attendance_types - Total attendance types
 * @property {Object} distribution - Distribution statistics
 * @property {Array<{name: string, count: number, percentage: number}>} distribution.by_department - By department
 * @property {Array<{name: string, count: number, percentage: number}>} distribution.by_designation - By designation
 * @property {Object} hiring_trends - Hiring trends
 * @property {Object} hiring_trends.recent_hires - Recent hires
 * @property {number} hiring_trends.recent_hires.last_30_days - Last 30 days
 * @property {number} hiring_trends.recent_hires.last_90_days - Last 90 days
 * @property {number} hiring_trends.recent_hires.last_year - Last year
 * @property {Object} workforce_health - Workforce health metrics
 * @property {Object} workforce_health.status_ratio - Status ratio
 * @property {number} workforce_health.status_ratio.active_percentage - Active percentage
 * @property {number} workforce_health.status_ratio.inactive_percentage - Inactive percentage
 * @property {number} workforce_health.retention_rate - Retention rate
 * @property {number} workforce_health.turnover_rate - Turnover rate
 */

/**
 * Leave Resource
 * @typedef {Object} Leave
 * @property {number} id - Leave ID
 * @property {number} user_id - User ID
 * @property {string} start_date - Start date
 * @property {string} end_date - End date
 * @property {string} reason - Leave reason
 * @property {string} status - Status (pending, approved, rejected)
 * @property {string|null} rejection_reason - Rejection reason
 * @property {string} created_at - Creation timestamp
 * @property {string} updated_at - Update timestamp
 */

/**
 * Attendance Resource
 * @typedef {Object} Attendance
 * @property {number} id - Attendance ID
 * @property {number} user_id - User ID
 * @property {string} date - Date
 * @property {string|null} check_in - Check-in time
 * @property {string|null} check_out - Check-out time
 * @property {string|null} work_hours - Work hours
 * @property {string|null} status - Status
 * @property {string} created_at - Creation timestamp
 * @property {string} updated_at - Update timestamp
 */

/**
 * Holiday Resource
 * @typedef {Object} Holiday
 * @property {number} id - Holiday ID
 * @property {string} name - Holiday name
 * @property {string} date - Holiday date
 * @property {string|null} description - Description
 * @property {boolean} is_recurring - Is recurring yearly
 * @property {string} created_at - Creation timestamp
 * @property {string} updated_at - Update timestamp
 */
