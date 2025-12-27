/**
 * Daily Work Module Constants
 * Aligned with backend: App\Models\DailyWork
 */

// Status constants - must match backend exactly (DailyWork.php L16-28)
export const DAILY_WORK_STATUS = {
  NEW: 'new',
  IN_PROGRESS: 'in-progress',
  COMPLETED: 'completed',
  REJECTED: 'rejected',
  RESUBMISSION: 'resubmission',
  PENDING: 'pending',
  EMERGENCY: 'emergency',
};

// Inspection result constants - must match backend exactly (DailyWork.php L30-38)
export const INSPECTION_RESULT = {
  PASS: 'pass',
  FAIL: 'fail',
  CONDITIONAL: 'conditional',
  PENDING: 'pending',
  APPROVED: 'approved',
  REJECTED: 'rejected',
};

// Status display configuration for UI
export const STATUS_CONFIG = {
  [DAILY_WORK_STATUS.NEW]: {
    label: 'New',
    color: 'primary',
    description: 'New work entry',
  },
  [DAILY_WORK_STATUS.IN_PROGRESS]: {
    label: 'In Progress',
    color: 'secondary',
    description: 'Work in progress',
  },
  [DAILY_WORK_STATUS.COMPLETED]: {
    label: 'Completed',
    color: 'success',
    description: 'Work completed',
  },
  [DAILY_WORK_STATUS.REJECTED]: {
    label: 'Rejected',
    color: 'danger',
    description: 'Work rejected',
  },
  [DAILY_WORK_STATUS.RESUBMISSION]: {
    label: 'Resubmission',
    color: 'warning',
    description: 'Requires resubmission',
  },
  [DAILY_WORK_STATUS.PENDING]: {
    label: 'Pending',
    color: 'default',
    description: 'Pending review',
  },
  [DAILY_WORK_STATUS.EMERGENCY]: {
    label: 'Emergency',
    color: 'danger',
    description: 'Emergency work',
  },
};

// Inspection result display configuration for UI
export const INSPECTION_CONFIG = {
  [INSPECTION_RESULT.PASS]: {
    label: 'Passed',
    color: 'success',
    description: 'Inspection passed',
  },
  [INSPECTION_RESULT.FAIL]: {
    label: 'Failed',
    color: 'danger',
    description: 'Inspection failed',
  },
  [INSPECTION_RESULT.CONDITIONAL]: {
    label: 'Conditional',
    color: 'warning',
    description: 'Conditional approval',
  },
  [INSPECTION_RESULT.PENDING]: {
    label: 'Pending',
    color: 'default',
    description: 'Inspection pending',
  },
  [INSPECTION_RESULT.APPROVED]: {
    label: 'Approved',
    color: 'success',
    description: 'Inspection approved',
  },
  [INSPECTION_RESULT.REJECTED]: {
    label: 'Rejected',
    color: 'danger',
    description: 'Inspection rejected',
  },
};

// Composite status for completed works with inspection results
export const COMPOSITE_STATUS = {
  COMPLETED_PASS: `${DAILY_WORK_STATUS.COMPLETED}:${INSPECTION_RESULT.PASS}`,
  COMPLETED_FAIL: `${DAILY_WORK_STATUS.COMPLETED}:${INSPECTION_RESULT.FAIL}`,
  COMPLETED_CONDITIONAL: `${DAILY_WORK_STATUS.COMPLETED}:${INSPECTION_RESULT.CONDITIONAL}`,
  COMPLETED_PENDING: `${DAILY_WORK_STATUS.COMPLETED}:${INSPECTION_RESULT.PENDING}`,
  COMPLETED_APPROVED: `${DAILY_WORK_STATUS.COMPLETED}:${INSPECTION_RESULT.APPROVED}`,
  COMPLETED_REJECTED: `${DAILY_WORK_STATUS.COMPLETED}:${INSPECTION_RESULT.REJECTED}`,
};

// Work type constants
export const WORK_TYPE = {
  STRUCTURE: 'Structure',
  EMBANKMENT: 'Embankment',
  PAVEMENT: 'Pavement',
};

// Road type constants
export const ROAD_TYPE = {
  SR_R: 'SR-R', // Service Road - Right
  SR_L: 'SR-L', // Service Road - Left
  TR_R: 'TR-R', // Through Road - Right
  TR_L: 'TR-L', // Through Road - Left
  BOTH: 'Both',
};

// Validation helpers
export const isValidStatus = (status) => {
  return Object.values(DAILY_WORK_STATUS).includes(status);
};

export const isValidInspectionResult = (result) => {
  return Object.values(INSPECTION_RESULT).includes(result);
};

export const isValidWorkType = (type) => {
  return Object.values(WORK_TYPE).includes(type);
};

export const isValidRoadType = (type) => {
  return Object.values(ROAD_TYPE).includes(type);
};

// Get all status options for dropdowns
export const getStatusOptions = () => {
  return Object.entries(STATUS_CONFIG).map(([value, config]) => ({
    value,
    label: config.label,
    color: config.color,
    description: config.description,
  }));
};

// Get all inspection result options for dropdowns
export const getInspectionOptions = () => {
  return Object.entries(INSPECTION_CONFIG).map(([value, config]) => ({
    value,
    label: config.label,
    color: config.color,
    description: config.description,
  }));
};

// Get work type options for dropdowns
export const getWorkTypeOptions = () => {
  return Object.values(WORK_TYPE).map((type) => ({
    value: type,
    label: type,
  }));
};

// Get road type options for dropdowns
export const getRoadTypeOptions = () => {
  return Object.entries(ROAD_TYPE).map(([key, value]) => ({
    value,
    label: value,
    key,
  }));
};

export default {
  DAILY_WORK_STATUS,
  INSPECTION_RESULT,
  STATUS_CONFIG,
  INSPECTION_CONFIG,
  COMPOSITE_STATUS,
  WORK_TYPE,
  ROAD_TYPE,
  isValidStatus,
  isValidInspectionResult,
  isValidWorkType,
  isValidRoadType,
  getStatusOptions,
  getInspectionOptions,
  getWorkTypeOptions,
  getRoadTypeOptions,
};
