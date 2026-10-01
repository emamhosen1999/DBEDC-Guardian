/**
 * Biometric terminals offered for an employee at a work location.
 *
 * Devices are infrastructure reference data. The standard (and the rule the server applies in
 * UserManagementService::assignableDevices): the ACTIVE terminals linked to the selected work
 * location (work_location_biometric_device), falling back to every active terminal when the
 * location has none linked or no location is chosen.
 *
 * @param {number|string|null|undefined} workLocationId
 * @param {Array<{id: number|string, biometric_devices?: Array<object>}>} workLocations  page prop, devices eager-loaded
 * @param {Array<object>} biometricDevices  page prop: every active terminal
 * @returns {Array<{id: number, name: string, serial_number?: string, location?: string}>}
 */
export function deviceOptionsForLocation(workLocationId, workLocations = [], biometricDevices = []) {
    const isActive = (device) => device?.is_active !== false;

    if (workLocationId !== null && workLocationId !== undefined && workLocationId !== '') {
        const location = (workLocations || []).find((candidate) => String(candidate.id) === String(workLocationId));
        const linked = (location?.biometric_devices ?? []).filter(isActive);
        if (linked.length > 0) {
            return linked;
        }
    }

    return (biometricDevices || []).filter(isActive);
}

export default deviceOptionsForLocation;
