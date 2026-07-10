import React from 'react';
import { Button, Box, Flex, Text, Card } from '@radix-ui/themes';
import { ExclamationTriangleIcon, ReloadIcon, HomeIcon } from '@radix-ui/react-icons';
import { router } from '@inertiajs/react';

/**
 * Enhanced Error Boundary Component
 * Provides graceful error handling with detailed information and recovery options
 */
class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = {
            hasError: false,
            error: null,
            errorInfo: null,
            errorId: null,
            showDetails: false
        };
    }

    static getDerivedStateFromError(error) {
        return {
            hasError: true,
            errorId: Date.now().toString(36) + Math.random().toString(36).substr(2)
        };
    }

    componentDidCatch(error, errorInfo) {
        this.setState({
            error,
            errorInfo
        });

        // Log error to monitoring service
        this.logErrorToService(error, errorInfo);
    }

    logErrorToService = async (error, errorInfo) => {
        try {
            await fetch('/api/log-error', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                body: JSON.stringify({
                    error_id: this.state.errorId,
                    message: error.message,
                    stack: error.stack,
                    component_stack: errorInfo.componentStack,
                    url: window.location.href,
                    user_agent: navigator.userAgent,
                    timestamp: new Date().toISOString()
                })
            });
        } catch (logError) {
            console.error('Failed to log error:', logError);
        }
    };

    handleRetry = () => {
        this.setState({
            hasError: false,
            error: null,
            errorInfo: null,
            errorId: null,
            showDetails: false
        });
    };

    handleGoHome = () => {
        router.visit('/dashboard');
    };

    handleReload = () => {
        // Instead of full page reload, try to recover gracefully
        // First try to retry the component
        this.handleRetry();
        
        // If that doesn't work, navigate to dashboard instead of reload
        setTimeout(() => {
            if (this.state.hasError) {
                this.handleGoHome();
            }
        }, 1000);
    };    render() {
        if (this.state.hasError) {
            const { error, errorInfo, errorId } = this.state;
            
            // Check if it is a widget-level error (inside Grid rows)
            const isWidget = this.props.compact || (errorInfo?.componentStack && (
                errorInfo.componentStack.includes('PersonalOverviewCard') || 
                errorInfo.componentStack.includes('PunchStatusCard') || 
                errorInfo.componentStack.includes('QuickLinksWidget') || 
                errorInfo.componentStack.includes('AttendanceChartWidget') || 
                errorInfo.componentStack.includes('PendingTasksWidget') || 
                errorInfo.componentStack.includes('UpcomingHolidaysWidget') || 
                errorInfo.componentStack.includes('UpdatesCards') ||
                errorInfo.componentStack.includes('WeatherWidget') ||
                errorInfo.componentStack.includes('ClockWidget')
            ));

            if (isWidget) {
                return (
                    <Card style={{ height: '100%', borderColor: 'var(--red-a7)', background: 'var(--red-a1)' }}>
                        <Flex direction="column" justify="center" align="center" gap="2" style={{ height: '100%', minHeight: 120, textAlign: 'center', padding: 12 }}>
                            <ExclamationTriangleIcon style={{ width: 24, height: 24, color: 'var(--red-9)' }} />
                            <Box>
                                <Text size="2" weight="bold" color="red" style={{ display: 'block' }}>Widget Error</Text>
                                <Text size="1" color="gray" style={{ display: 'block', mt: 1 }}>Failed to load element</Text>
                            </Box>
                            <Button size="1" color="red" variant="soft" onClick={this.handleRetry}>
                                <ReloadIcon style={{ width: 10, height: 10 }} /> Retry Widget
                            </Button>
                        </Flex>
                    </Card>
                );
            }

            return (
                <Flex align="center" justify="center" style={{ minHeight: '100vh', background: 'radial-gradient(circle, var(--gray-1) 0%, var(--gray-3) 100%)', padding: 24 }}>
                    <Box style={{ maxWidth: 520, width: '100%' }}>
                        <Card style={{ padding: 32, borderTop: '4px solid var(--red-9)', boxShadow: 'var(--shadow-4)' }}>
                            <Flex direction="column" align="center" gap="4" style={{ textAlign: 'center' }}>
                                <Box style={{ padding: 16, borderRadius: '50%', background: 'var(--red-a3)' }}>
                                    <ExclamationTriangleIcon style={{ width: 48, height: 48, color: 'var(--red-9)' }} />
                                </Box>
                                <Box>
                                    <Text size="6" weight="bold" style={{ color: 'var(--gray-12)', letterSpacing: '-0.02em' }}>
                                        Application Outage
                                    </Text>
                                    <Text size="2" color="gray" mt="2" style={{ display: 'block', lineHeight: 1.5 }}>
                                        A component encountered an unrecoverable failure. Our system administration is analyzing the logs automatically.
                                    </Text>
                                </Box>

                                <Card style={{ width: '100%', borderLeft: '3px solid var(--blue-9)', background: 'var(--blue-a1)', textAlign: 'left' }}>
                                    <Flex direction="column" gap="1" p="2">
                                        <Text size="1" color="gray">DIAGNOSTIC REFERENCE ID</Text>
                                        <Text size="2" weight="bold" style={{ fontFamily: 'monospace', fontVariantNumeric: 'tabular-nums' }}>
                                            {errorId}
                                        </Text>
                                    </Flex>
                                </Card>

                                <Flex gap="3" justify="center" wrap="wrap" style={{ width: '100%' }}>
                                    <Button color="indigo" size="2" onClick={this.handleRetry} style={{ flex: 1, minWidth: 120 }}>
                                        <ReloadIcon /> Reload State
                                    </Button>
                                    <Button color="gray" variant="soft" size="2" onClick={this.handleGoHome} style={{ flex: 1, minWidth: 120 }}>
                                        <HomeIcon /> Go to Dashboard
                                    </Button>
                                </Flex>

                                {(error || errorInfo) && (
                                    <Box style={{ width: '100%', textAlign: 'left' }}>
                                        <details style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)', padding: 12 }}>
                                            <summary style={{ cursor: 'pointer', fontWeight: 600, fontSize: 13, color: 'var(--gray-11)' }}>
                                                Developer Stack Trace
                                            </summary>
                                            <Box mt="3">
                                                {error && (
                                                    <Box mb="3">
                                                        <Text size="1" weight="bold" color="red" as="p" mb="1">Message</Text>
                                                        <pre style={{ background: 'var(--gray-a2)', padding: 12, borderRadius: 'var(--radius-2)', fontSize: 11, overflow: 'auto', fontFamily: 'monospace' }}>{error.message}</pre>
                                                    </Box>
                                                )}
                                                {errorInfo && (
                                                    <Box>
                                                        <Text size="1" weight="bold" color="red" as="p" mb="1">Stack Trace</Text>
                                                        <pre style={{ background: 'var(--gray-a2)', padding: 12, borderRadius: 'var(--radius-2)', fontSize: 11, overflow: 'auto', fontFamily: 'monospace', maxHeight: 150 }}>{errorInfo.componentStack}</pre>
                                                    </Box>
                                                )}
                                            </Box>
                                        </details>
                                    </Box>
                                )}
                            </Flex>
                        </Card>
                    </Box>
                </Flex>
            );
        }

        return this.props.children;
    }
}

export default ErrorBoundary;
