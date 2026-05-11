import React, { useState, useCallback, useMemo, useEffect } from 'react';
import { Head, router } from "@inertiajs/react";
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import {
    Badge, Box, Button, Callout, Card, Flex, Grid,
    Heading, Separator, Spinner, Text, TextField,
} from '@radix-ui/themes';
import {
    EnvelopeClosedIcon, MagnifyingGlassIcon, MixerHorizontalIcon,
    Pencil1Icon, PersonIcon, PlusIcon,
    StackIcon, TableIcon,
} from '@radix-ui/react-icons';
import App from "@/Layouts/App.jsx";
import StatsCards from "@/Components/StatsCards.jsx";
import EmployeeTable from "@/Tables/EmployeeTable.jsx";
import ProfileAvatar from "@/Components/ProfileAvatar.jsx";
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';


const EmployeesList = ({ title, departments, designations, attendanceTypes }) => {

  const isMobile = useMediaQuery('(max-width: 640px)');
  const isTablet = useMediaQuery('(max-width: 768px)');
  
  // State for employee data with server-side pagination
  const [employees, setEmployees] = useState([]);
  const [allManagers, setAllManagers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [totalRows, setTotalRows] = useState(0);
  const [lastPage, setLastPage] = useState(1);

  // Filters
  const [filters, setFilters] = useState({
    search: '',
    department: 'all',
    designation: 'all',
    attendanceType: 'all'
  });
  
  // View mode (table or grid)
  const [viewMode, setViewMode] = useState('table');
  const [showFilters, setShowFilters] = useState(false);
  
  // Pagination
  const [pagination, setPagination] = useState({
    currentPage: 1,
    perPage: 10,
    total: 0
  });

  // Stats - Updated to match comprehensive backend stats structure
  const [stats, setStats] = useState({
    overview: {
      total_employees: 0,
      active_employees: 0,
      inactive_employees: 0,
      total_departments: 0,
      total_designations: 0,
      total_attendance_types: 0
    },
    distribution: {
      by_department: [],
      by_designation: [],
      by_attendance_type: []
    },
    hiring_trends: {
      recent_hires: {
        last_30_days: 0,
        last_90_days: 0,
        last_year: 0
      },
      monthly_growth_rate: 0,
      current_month_hires: 0
    },
    workforce_health: {
      status_ratio: {
        active_percentage: 0,
        inactive_percentage: 0,
        retention_rate: 0
      },
      retention_rate: 0,
      turnover_rate: 0
    },
    quick_metrics: {
      headcount: 0,
      active_ratio: 0,
      department_diversity: 0,
      role_diversity: 0,
      recent_activity: 0
    }
  });

  // Fetch employees with pagination and filters
  const fetchEmployees = useCallback(async () => {
    setLoading(true);
    try {
      const { data } = await axios.get(route('employees.paginate'), {
        params: {
          page: pagination.currentPage,
          perPage: pagination.perPage,
          search: filters.search,
          department: filters.department,
          designation: filters.designation,
          attendanceType: filters.attendanceType
        }
      });
      
      setEmployees(data.employees.data);
      setTotalRows(data.employees.total);
      setLastPage(data.employees.last_page);
      setPagination(prev => ({
        ...prev,
        total: data.employees.total
      }));
      
      // Update allManagers for Report To dropdown
      if (data.allManagers) {
        setAllManagers(data.allManagers);
      }
      
      // Update stats if included in response
      if (data.stats) {
        setStats(data.stats);
      }
    } catch (error) {
      console.error('Error fetching employees:', error);
      showToast.error('Failed to load employees. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [pagination.currentPage, pagination.perPage, filters]);

  // Fetch employee stats separately
  const fetchStats = useCallback(async () => {
    try {
      const { data } = await axios.get(route('employees.stats'));
      if (data.stats) {
        setStats(data.stats);
      }
    } catch (error) {
      console.error('Error fetching employee stats:', error);
    }
  }, []);

  // Initial data fetch
  useEffect(() => {
    fetchEmployees();
    fetchStats();
  }, [fetchEmployees, fetchStats]);

  // Handle filter changes
  const handleSearchChange = useCallback((value) => {
    setFilters(prev => ({ ...prev, search: value }));
    setPagination(prev => ({ ...prev, currentPage: 1 })); // Reset to first page on search
  }, []);

  const handleDepartmentFilterChange = useCallback((value) => {
    setFilters(prev => ({ 
      ...prev, 
      department: value,
      designation: value !== 'all' ? 'all' : prev.designation // Reset designation when department changes
    }));
    setPagination(prev => ({ ...prev, currentPage: 1 }));
  }, []);

  const handleDesignationFilterChange = useCallback((value) => {
    setFilters(prev => ({ ...prev, designation: value }));
    setPagination(prev => ({ ...prev, currentPage: 1 }));
  }, []);

  const handleAttendanceTypeFilterChange = useCallback((value) => {
    setFilters(prev => ({ ...prev, attendanceType: value }));
    setPagination(prev => ({ ...prev, currentPage: 1 }));
  }, []);

  // Handle pagination changes
  const handlePageChange = useCallback((page) => {
    setPagination(prev => ({ ...prev, currentPage: page }));
  }, []);

  const handleRowsPerPageChange = useCallback((perPage) => {
    setPagination(prev => ({ ...prev, perPage: perPage, currentPage: 1 }));
  }, []);

  // Optimistic updates
  const updateEmployeeOptimized = useCallback((id, updatedFields) => {
    setEmployees(prev => 
      prev.map(employee => 
        employee.id === id ? { ...employee, ...updatedFields } : employee
      )
    );
  }, []);

  const deleteEmployeeOptimized = useCallback((id) => {
    setEmployees(prev => prev.filter(employee => employee.id !== id));
    setTotalRows(prev => prev - 1);
    setPagination(prev => ({
      ...prev,
      total: prev.total - 1
    }));
    fetchStats(); // Refresh stats after deletion
  }, [fetchStats]);



  // Get filtered designations based on selected department
  const filteredDesignations = useMemo(() => {
    if (filters.department === 'all') return designations;
    return designations.filter(d => d.department_id === parseInt(filters.department));
  }, [designations, filters.department]);

  // Prepare comprehensive stats data for StatsCards component
  const statsData = useMemo(() => [
    {
      title: "Total Employees",
      value: stats.overview?.total_employees || 0,
      icon: <PersonIcon />,
      color: "text-blue-400",
      iconBg: "bg-blue-500/20",
      description: "Total Headcount"
    },
    {
      title: "Active Employees", 
      value: stats.overview?.active_employees || 0,
      icon: <PersonIcon />,
      color: "text-green-400",
      iconBg: "bg-green-500/20", 
      description: `${stats.workforce_health?.status_ratio?.active_percentage || 0}% Active`
    },
    {
      title: "Departments",
      value: stats.overview?.total_departments || 0,
      icon: <StackIcon />,
      color: "text-purple-400", 
      iconBg: "bg-purple-500/20",
      description: "Department Diversity"
    },
    {
      title: "Designations",
      value: stats.overview?.total_designations || 0,
      icon: <TableIcon />,
      color: "text-orange-400",
      iconBg: "bg-orange-500/20",
      description: "Role Diversity"
    },
    {
      title: "Retention Rate",
      value: `${stats.workforce_health?.retention_rate || 0}%`,
      icon: <PlusIcon />,
      color: "text-emerald-400",
      iconBg: "bg-emerald-500/20",
      description: "Employee Retention"
    },
    {
      title: "Recent Hires",
      value: stats.hiring_trends?.recent_hires?.last_30_days || 0,
      icon: <PlusIcon />,
      color: "text-cyan-400",
      iconBg: "bg-cyan-500/20",
      description: "Last 30 Days"
    },
    {
      title: "Growth Rate",
      value: `${stats.hiring_trends?.monthly_growth_rate || 0}%`,
      icon: <MixerHorizontalIcon />,
      color: "text-pink-400",
      iconBg: "bg-pink-500/20",
      description: "Monthly Growth"
    },
    {
      title: "Attendance Types",
      value: stats.overview?.total_attendance_types || 0,
      icon: <TableIcon />,
      color: "text-indigo-400",
      iconBg: "bg-indigo-500/20",
      description: "Available Types"
    }
  ], [stats]);

  const EmployeeCard = ({ user }) => {
    const department = departments?.find(d => d.id === user.department_id);
    const designation = designations?.find(d => d.id === user.designation_id);
    const attendanceType = attendanceTypes?.find(a => a.id === user.attendance_type_id);
    return (
      <Card size="2" style={{ cursor: 'pointer' }} onClick={() => router.visit(route('profile', { user: user.id }))}>
        <Flex align="start" gap="3" pb="3" mb="3" style={{ borderBottom: '1px solid var(--gray-a4)' }}>
          <Box style={{ flexShrink: 0 }}>
            <ProfileAvatar src={user?.profile_image_url || user?.profile_image} name={user?.name} size="md" />
          </Box>
          <Box style={{ minWidth: 0, flex: 1 }}>
            <Text weight="medium" size="2" style={{ display: 'block' }}>{user?.name}</Text>
            <Text size="1" color="gray">ID: {user?.employee_id || 'N/A'}</Text>
          </Box>
          <Button size="1" variant="ghost" color="gray" style={{ cursor: 'pointer', flexShrink: 0 }}
            onClick={e => { e.stopPropagation(); router.visit(route('profile', { user: user.id })); }}>
            <Pencil1Icon />
          </Button>
        </Flex>
        <Flex direction="column" gap="1" mb="3">
          <Flex align="center" gap="2">
            <EnvelopeClosedIcon style={{ color: 'var(--gray-9)', flexShrink: 0 }} />
            <Text size="1" color="gray" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{user?.email}</Text>
          </Flex>
          {user?.phone && (
            <Flex align="center" gap="2">
              <PersonIcon style={{ color: 'var(--gray-9)', flexShrink: 0 }} />
              <Text size="1" color="gray">{user?.phone}</Text>
            </Flex>
          )}
        </Flex>
        <Flex gap="1" wrap="wrap" pt="3" style={{ borderTop: '1px solid var(--gray-a4)' }}>
          {department && <Badge color="blue" variant="soft">{department.name}</Badge>}
          {designation && <Badge color="violet" variant="soft">{designation.title}</Badge>}
          {attendanceType && <Badge color="gray" variant="outline">{attendanceType.name}</Badge>}
        </Flex>
      </Card>
    );
  };

  return (
    <>
      <Head title={title || "Employee Directory"} />
      <Box p="4">
        <Card size="3">
          {/* Header */}
          <Flex align="center" justify="between" wrap="wrap" gap="3" pb="4" mb="4"
            style={{ borderBottom: '1px solid var(--gray-a4)' }}
          >
            <Flex align="center" gap="3">
              <Box style={{
                padding: 10, background: 'var(--accent-a3)', borderRadius: 'var(--radius-2)',
                border: '1px solid var(--accent-a6)', display: 'flex', alignItems: 'center', justifyContent: 'center',
              }}>
                <PersonIcon style={{ width: 22, height: 22, color: 'var(--accent-9)' }} />
              </Box>
              <Box>
                <Heading size="5">Employee Directory</Heading>
                <Text size="2" color="gray">Manage employee information and organizational structure</Text>
              </Box>
            </Flex>
            <Button size="2" style={{ cursor: 'pointer' }}>
              <PlusIcon /> {isMobile ? 'Add' : 'Add Employee'}
            </Button>
          </Flex>

          {/* Stats */}
          <StatsCards stats={statsData} className="mb-6" />

          {/* Analytics */}
          <Grid columns={{ initial: '1', lg: '2' }} gap="4" mb="5">
            <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
              <Text size="3" weight="medium" mb="3" style={{ display: 'block' }}>Department Distribution</Text>
              <Flex direction="column" gap="2">
                {stats.distribution?.by_department?.slice(0, 5).map((dept, i) => (
                  <Flex key={i} align="center" justify="between">
                    <Text size="2" color="gray">{dept.name}</Text>
                    <Flex align="center" gap="2">
                      <Box style={{ width: 80, height: 6, borderRadius: 'var(--radius-1)', background: 'var(--gray-a4)', overflow: 'hidden' }}>
                        <Box style={{ height: '100%', width: `${dept.percentage}%`, background: 'var(--accent-9)', borderRadius: 'var(--radius-1)' }} />
                      </Box>
                      <Text size="1" color="gray" style={{ width: 24, textAlign: 'right' }}>{dept.count}</Text>
                    </Flex>
                  </Flex>
                ))}
              </Flex>
            </Box>
            <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
              <Text size="3" weight="medium" mb="3" style={{ display: 'block' }}>Hiring Trends</Text>
              <Flex direction="column" gap="2">
                {[['Last 30 Days', stats.hiring_trends?.recent_hires?.last_30_days || 0], ['Last 90 Days', stats.hiring_trends?.recent_hires?.last_90_days || 0], ['This Year', stats.hiring_trends?.recent_hires?.last_year || 0]].map(([label, val]) => (
                  <Flex key={label} justify="between">
                    <Text size="2" color="gray">{label}</Text>
                    <Text size="2" weight="medium">{val}</Text>
                  </Flex>
                ))}
                <Separator size="4" />
                <Flex justify="between">
                  <Text size="2" color="gray">Monthly Growth</Text>
                  <Text size="2" weight="medium" color={(stats.hiring_trends?.monthly_growth_rate || 0) >= 0 ? 'green' : 'red'}>
                    {stats.hiring_trends?.monthly_growth_rate || 0}%
                  </Text>
                </Flex>
              </Flex>
            </Box>
            <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
              <Text size="3" weight="medium" mb="3" style={{ display: 'block' }}>Workforce Health</Text>
              <Flex direction="column" gap="2">
                {[['Retention Rate', `${stats.workforce_health?.retention_rate || 0}%`, 'green'], ['Turnover Rate', `${stats.workforce_health?.turnover_rate || 0}%`, 'orange'], ['Active Employees', `${stats.workforce_health?.status_ratio?.active_percentage || 0}%`, 'blue']].map(([label, val, color]) => (
                  <Flex key={label} justify="between">
                    <Text size="2" color="gray">{label}</Text>
                    <Text size="2" weight="medium" color={color}>{val}</Text>
                  </Flex>
                ))}
              </Flex>
            </Box>
            <Box p="4" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
              <Text size="3" weight="medium" mb="3" style={{ display: 'block' }}>Attendance Types</Text>
              <Flex direction="column" gap="2">
                {stats.distribution?.by_attendance_type?.map((type, i) => (
                  <Flex key={i} align="center" justify="between">
                    <Text size="2" color="gray">{type.name}</Text>
                    <Flex align="center" gap="2">
                      <Box style={{ width: 64, height: 6, borderRadius: 'var(--radius-1)', background: 'var(--gray-a4)', overflow: 'hidden' }}>
                        <Box style={{ height: '100%', width: `${type.percentage}%`, background: 'var(--green-9)', borderRadius: 'var(--radius-1)' }} />
                      </Box>
                      <Text size="1" color="gray" style={{ width: 24, textAlign: 'right' }}>{type.count}</Text>
                    </Flex>
                  </Flex>
                ))}
              </Flex>
            </Box>
          </Grid>

          {/* Filters */}
          <Box mb="4">
            <Flex gap="2" wrap="wrap" align="end" mb="3">
              <Box style={{ flex: 1, minWidth: 200 }}>
                <TextField.Root
                  placeholder="Search by name, email, or employee ID..."
                  value={filters.search}
                  onChange={e => handleSearchChange(e.target.value)}
                  size="2"
                >
                  <TextField.Slot><MagnifyingGlassIcon /></TextField.Slot>
                </TextField.Root>
              </Box>
              <Flex gap="2">
                <Button variant={viewMode === 'table' ? 'solid' : 'soft'} color={viewMode === 'table' ? undefined : 'gray'} size="2" onClick={() => setViewMode('table')} style={{ cursor: 'pointer' }}>
                  <TableIcon /> {!isMobile && 'Table'}
                </Button>
                <Button variant={viewMode === 'grid' ? 'solid' : 'soft'} color={viewMode === 'grid' ? undefined : 'gray'} size="2" onClick={() => setViewMode('grid')} style={{ cursor: 'pointer' }}>
                  <StackIcon /> {!isMobile && 'Grid'}
                </Button>
                <Button variant={showFilters ? 'solid' : 'soft'} color={showFilters ? undefined : 'gray'} size="2" onClick={() => setShowFilters(v => !v)} style={{ cursor: 'pointer' }}>
                  <MixerHorizontalIcon />
                </Button>
              </Flex>
            </Flex>
            {showFilters && (
              <Box p="3" mb="3" style={{ background: 'var(--gray-a2)', borderRadius: 'var(--radius-2)', border: '1px solid var(--gray-a4)' }}>
                <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="3">
                  <Box>
                    <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Department</Text>
                    <select value={filters.department} onChange={e => handleDepartmentFilterChange(e.target.value)}
                      style={{ width: '100%', padding: '6px 10px', background: 'var(--color-surface)', border: '1px solid var(--gray-a7)', borderRadius: 'var(--radius-2)', color: 'var(--gray-12)', fontSize: 14 }}>
                      <option value="all">All Departments</option>
                      {departments?.map(d => <option key={d.id} value={String(d.id)}>{d.name}</option>)}
                    </select>
                  </Box>
                  <Box>
                    <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Designation</Text>
                    <select value={filters.designation} onChange={e => handleDesignationFilterChange(e.target.value)} disabled={filters.department === 'all'}
                      style={{ width: '100%', padding: '6px 10px', background: 'var(--color-surface)', border: '1px solid var(--gray-a7)', borderRadius: 'var(--radius-2)', color: 'var(--gray-12)', fontSize: 14, opacity: filters.department === 'all' ? 0.5 : 1 }}>
                      <option value="all">All Designations</option>
                      {filteredDesignations?.map(d => <option key={d.id} value={String(d.id)}>{d.title}</option>)}
                    </select>
                  </Box>
                  <Box>
                    <Text as="label" size="1" weight="medium" style={{ display: 'block', marginBottom: 4 }}>Attendance Type</Text>
                    <select value={filters.attendanceType} onChange={e => handleAttendanceTypeFilterChange(e.target.value)}
                      style={{ width: '100%', padding: '6px 10px', background: 'var(--color-surface)', border: '1px solid var(--gray-a7)', borderRadius: 'var(--radius-2)', color: 'var(--gray-12)', fontSize: 14 }}>
                      <option value="all">All Attendance Types</option>
                      {attendanceTypes?.map(t => <option key={t.id} value={String(t.id)}>{t.name}</option>)}
                    </select>
                  </Box>
                  <Flex align="end">
                    <Button variant="soft" color="red" size="2" style={{ width: '100%', cursor: 'pointer' }}
                      disabled={filters.search === '' && filters.department === 'all' && filters.designation === 'all' && filters.attendanceType === 'all'}
                      onClick={() => { setFilters({ search: '', department: 'all', designation: 'all', attendanceType: 'all' }); setPagination(prev => ({ ...prev, currentPage: 1 })); }}>
                      Clear Filters
                    </Button>
                  </Flex>
                </Grid>
              </Box>
            )}
          </Box>

          <Separator size="4" mb="4" />

          {/* Table / Grid */}
          <Box>
            <Flex align="center" gap="2" mb="3">
              <TableIcon style={{ width: 16, height: 16 }} />
              <Text size="3" weight="medium">Employee Directory</Text>
            </Flex>
            {loading ? (
              <Flex direction="column" align="center" py="8" gap="3">
                <Spinner size="3" />
                <Text color="gray">Loading employee data...</Text>
              </Flex>
            ) : viewMode === 'table' ? (
              <EmployeeTable
                allUsers={employees}
                allManagers={allManagers}
                departments={departments}
                designations={designations}
                attendanceTypes={attendanceTypes}
                setUsers={setEmployees}
                isMobile={isMobile}
                isTablet={isTablet}
                pagination={pagination}
                onPageChange={handlePageChange}
                onRowsPerPageChange={handleRowsPerPageChange}
                totalUsers={totalRows}
                loading={loading}
                updateEmployeeOptimized={updateEmployeeOptimized}
                deleteEmployeeOptimized={deleteEmployeeOptimized}
              />
            ) : employees.length > 0 ? (
              <Box>
                <Grid columns={{ initial: '1', sm: '2', lg: '3', xl: '4' }} gap="4" mb="4">
                  {employees.map(user => <EmployeeCard key={user.id} user={user} />)}
                </Grid>
                <Flex justify="between" align="center" pt="3" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                  <Text size="2" color="gray">
                    Showing {((pagination.currentPage - 1) * pagination.perPage) + 1}–{Math.min(pagination.currentPage * pagination.perPage, pagination.total)} of {pagination.total}
                  </Text>
                  <Flex gap="1">
                    <Button variant="soft" color="gray" size="2" disabled={pagination.currentPage <= 1} onClick={() => handlePageChange(pagination.currentPage - 1)} style={{ cursor: 'pointer' }}>‹ Prev</Button>
                    <Button variant="soft" color="gray" size="2" disabled={pagination.currentPage >= Math.ceil(pagination.total / pagination.perPage)} onClick={() => handlePageChange(pagination.currentPage + 1)} style={{ cursor: 'pointer' }}>Next ›</Button>
                  </Flex>
                </Flex>
              </Box>
            ) : (
              <Flex direction="column" align="center" py="8" gap="2">
                <PersonIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                <Heading size="3">No Employees Found</Heading>
                <Text color="gray">Try adjusting your search or filters.</Text>
              </Flex>
            )}
          </Box>
        </Card>
      </Box>
    </>
  );
};

EmployeesList.layout = (page) => <App>{page}</App>;
export default EmployeesList;
