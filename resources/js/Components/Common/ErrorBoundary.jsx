import React, { Component } from 'react';
import { Button, Box, Flex, Text } from '@radix-ui/themes';
import { ExclamationTriangleIcon, ReloadIcon } from '@radix-ui/react-icons';

/**
 * Error Boundary component for catching and handling React errors gracefully
 * Prevents the entire app from crashing when a component throws an error
 * 
 * @example
 * <ErrorBoundary fallbackTitle="Table Error" onRetry={() => refreshData()}>
 *   <DataTable data={data} />
 * </ErrorBoundary>
 */
class ErrorBoundary extends Component {
    constructor(props) {
        super(props);
        this.state = { 
            hasError: false, 
            error: null, 
            errorInfo: null 
        };
    }

    static getDerivedStateFromError(error) {
        // Update state so the next render will show the fallback UI
        return { hasError: true, error };
    }

    componentDidCatch(error, errorInfo) {
        // Log the error to console in development
        console.error('ErrorBoundary caught an error:', error, errorInfo);
        
        this.setState({ errorInfo });
        
        // Call optional error callback
        if (this.props.onError) {
            this.props.onError(error, errorInfo);
        }
    }

    handleRetry = () => {
        // Reset error state
        this.setState({ hasError: false, error: null, errorInfo: null });
        
        // Call optional retry callback
        if (this.props.onRetry) {
            this.props.onRetry();
        }
    };

    render() {
        if (this.state.hasError) {
            // Custom fallback UI
            if (this.props.fallback) {
                return this.props.fallback;
            }

            const {
                fallbackTitle = 'Something went wrong',
                fallbackDescription = 'An unexpected error occurred. Please try again.',
                showDetails = process.env.NODE_ENV === 'development',
                showRetry = true,
            } = this.props;

            return (
                <Box p="6" style={{ border: '1px solid var(--red-a6)', background: 'var(--red-a2)', borderRadius: 'var(--radius-3)' }}>
                    <Flex direction="column" align="center" justify="center" gap="4" style={{ textAlign: 'center' }}>
                        <Box p="3" style={{ borderRadius: '50%', background: 'var(--red-a3)' }}>
                            <ExclamationTriangleIcon style={{ width: 32, height: 32, color: 'var(--red-9)' }} />
                        </Box>
                        <Text size="4" weight="bold" color="red">{fallbackTitle}</Text>
                        <Text size="2" color="gray">{fallbackDescription}</Text>
                        {showDetails && this.state.error && (
                            <Box style={{ width: '100%', maxWidth: 480, textAlign: 'left' }}>
                                <details>
                                    <summary style={{ fontSize: 12, cursor: 'pointer', color: 'var(--gray-9)' }}>Error Details</summary>
                                    <pre style={{ marginTop: 8, padding: 12, background: 'var(--gray-a3)', borderRadius: 'var(--radius-2)', fontSize: 11, color: 'var(--red-11)', overflowX: 'auto' }}>
                                        {this.state.error.toString()}
                                        {this.state.errorInfo?.componentStack}
                                    </pre>
                                </details>
                            </Box>
                        )}
                        {showRetry && (
                            <Button color="red" variant="soft" onClick={this.handleRetry}>
                                <ReloadIcon /> Try Again
                            </Button>
                        )}
                    </Flex>
                </Box>
            );
        }

        return this.props.children;
    }
}

export default ErrorBoundary;
