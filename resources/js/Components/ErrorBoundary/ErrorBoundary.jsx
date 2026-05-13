import React from 'react';
import { Button, Box, Flex, Text, Card } from '@radix-ui/themes';
import { ExclamationTriangleIcon, ReloadIcon, HomeIcon } from '@radix-ui/react-icons';
import { Inertia } from '@inertiajs/inertia';

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
        Inertia.visit('/dashboard');
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
            const { error, errorInfo, errorId, showDetails } = this.state;

            return (
                <Flex align="center" justify="center" style={{ minHeight: '100vh', padding: 24 }}>
                    <Box style={{ maxWidth: 640, width: '100%', textAlign: 'center' }}>
                        <Flex justify="center" mb="5">
                            <ExclamationTriangleIcon style={{ width: 80, height: 80, color: 'var(--red-9)' }} />
                        </Flex>
                        <Text as="p" size="7" weight="bold" color="red" mb="3">Oops! Something went wrong</Text>
                        <Text as="p" size="3" color="gray" mb="5">
                            We encountered an unexpected error. Our team has been notified and will investigate this issue.
                        </Text>
                        <Card mb="5" style={{ borderLeft: '4px solid var(--blue-9)', textAlign: 'left' }}>
                            <Box p="3">
                                <Text as="p" size="2"><strong>Error ID:</strong> <code style={{ fontFamily: 'monospace', background: 'var(--gray-a3)', padding: '1px 4px', borderRadius: 3 }}>{errorId}</code></Text>
                                <Text as="p" size="1" color="gray" mt="1">Please provide this ID when contacting support.</Text>
                            </Box>
                        </Card>
                        <Flex gap="2" justify="center" wrap="wrap" mb="5">
                            <Button color="indigo" onClick={this.handleRetry}><ReloadIcon /> Try Again</Button>
                            <Button color="indigo" variant="outline" onClick={this.handleGoHome}><HomeIcon /> Go to Dashboard</Button>
                            <Button color="gray" variant="outline" onClick={this.handleReload}><ReloadIcon /> Recover</Button>
                        </Flex>
                        {(error || errorInfo) && (
                            <Box mb="5" style={{ textAlign: 'left' }}>
                                <details style={{ border: '1px solid var(--gray-a6)', borderRadius: 'var(--radius-2)', padding: 12 }}>
                                    <summary style={{ cursor: 'pointer', fontWeight: 600, fontSize: 14 }}>Technical Details</summary>
                                    <Box mt="3">
                                        {error && (
                                            <Box mb="3">
                                                <Text size="2" weight="bold" color="red" as="p" mb="1">Error Message:</Text>
                                                <pre style={{ background: 'var(--gray-a3)', padding: 12, borderRadius: 'var(--radius-2)', fontSize: 12, overflow: 'auto', fontFamily: 'monospace' }}>{error.message}</pre>
                                            </Box>
                                        )}
                                        {errorInfo && (
                                            <Box>
                                                <Text size="2" weight="bold" color="red" as="p" mb="1">Component Stack:</Text>
                                                <pre style={{ background: 'var(--gray-a3)', padding: 12, borderRadius: 'var(--radius-2)', fontSize: 12, overflow: 'auto', fontFamily: 'monospace', maxHeight: 192 }}>{errorInfo.componentStack}</pre>
                                            </Box>
                                        )}
                                    </Box>
                                </details>
                            </Box>
                        )}
                        <Card>
                            <Box p="3" style={{ textAlign: 'center' }}>
                                <Text as="p" size="3" weight="bold" mb="1">Need Help?</Text>
                                <Text as="p" size="2" color="gray">If this problem persists, contact our support team at <strong style={{ color: 'var(--accent-9)' }}>support@dbedc.com</strong> with the error ID.</Text>
                            </Box>
                        </Card>
                    </Box>
                </Flex>
            );
        }

        return this.props.children;
    }
}

export default ErrorBoundary;
