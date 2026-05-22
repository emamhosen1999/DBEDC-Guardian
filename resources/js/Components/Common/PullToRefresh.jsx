import React, { useState, useRef, useCallback } from 'react';
import { ArrowPathIcon } from '@heroicons/react/24/outline';

/**
 * Pull-to-refresh component for mobile devices
 * Wrap your scrollable content with this component to enable pull-to-refresh gesture
 * 
 * @param {Object} props
 * @param {Function} props.onRefresh - Async function to call when refresh is triggered
 * @param {React.ReactNode} props.children - Content to wrap
 * @param {boolean} props.disabled - Whether pull-to-refresh is disabled
 * @param {number} props.threshold - Pull distance threshold to trigger refresh (default: 80)
 * @param {string} props.className - Additional CSS classes
 */
const PullToRefresh = ({ 
    onRefresh, 
    children, 
    disabled = false, 
    threshold = 80,
    className = '' 
}) => {
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [pullDistance, setPullDistance] = useState(0);
    const containerRef = useRef(null);
    const startY = useRef(0);
    const currentY = useRef(0);
    const isAtTop = useRef(true);

    // Calculate rotation, opacity, and scale based on pull distance
    const rotate = Math.min(360, (pullDistance / threshold) * 360);
    const opacity = Math.min(1, Math.max(0, (pullDistance - threshold / 2) / (threshold / 2)));
    const scale = Math.min(1, Math.max(0.5, pullDistance / threshold));

    const handleTouchStart = useCallback((e) => {
        if (disabled || isRefreshing) return;

        // Check if we're at the top of scroll
        const container = containerRef.current;
        if (container && container.scrollTop > 0) {
            isAtTop.current = false;
            return;
        }

        isAtTop.current = true;
        startY.current = e.touches[0].clientY;
    }, [disabled, isRefreshing]);

    const handleTouchMove = useCallback((e) => {
        if (disabled || isRefreshing || !isAtTop.current) return;

        currentY.current = e.touches[0].clientY;
        const diff = currentY.current - startY.current;

        // Only allow pulling down
        if (diff > 0) {
            // Apply resistance to the pull
            const resistance = 0.4;
            const newPullDistance = Math.min(diff * resistance, threshold * 1.5);
            setPullDistance(newPullDistance);

            // Prevent default scroll behavior when pulling
            if (newPullDistance > 10) {
                e.preventDefault();
            }
        }
    }, [disabled, isRefreshing, threshold]);

    const handleTouchEnd = useCallback(async () => {
        if (disabled || isRefreshing || !isAtTop.current) return;

        if (pullDistance >= threshold) {
            // Trigger refresh
            setIsRefreshing(true);
            try {
                await onRefresh?.();
            } finally {
                setIsRefreshing(false);
            }
        }

        // Reset pull distance
        setPullDistance(0);
    }, [disabled, isRefreshing, pullDistance, threshold, onRefresh]);

    return (
        <div 
            ref={containerRef}
            className={`relative overflow-auto ${className}`}
            onTouchStart={handleTouchStart}
            onTouchMove={handleTouchMove}
            onTouchEnd={handleTouchEnd}
            style={{ touchAction: pullDistance > 10 ? 'none' : 'auto' }}
        >
            {/* Pull indicator */}
            {(pullDistance > 0 || isRefreshing) && (
                <div
                    className="absolute left-0 right-0 flex items-center justify-center z-50"
                    style={{
                        top: Math.min(pullDistance, threshold) - 40,
                        height: 40,
                    }}
                >
                    <div
                        className={`
                            w-10 h-10 rounded-full flex items-center justify-center
                            ${isRefreshing
                                ? 'bg-primary/20 border-2 border-primary'
                                : pullDistance >= threshold
                                    ? 'bg-success/20 border-2 border-success'
                                    : 'bg-default-100 border-2 border-default-300'
                            }
                        `}
                        style={{
                            transform: `scale(${isRefreshing ? 1 : scale})`,
                            opacity: isRefreshing ? 1 : opacity
                        }}
                    >
                        <div
                            style={{
                                transform: `rotate(${isRefreshing ? 0 : rotate}deg)`,
                                animation: isRefreshing ? 'spin 1s linear infinite' : 'none'
                            }}
                        >
                            <ArrowPathIcon
                                className={`w-5 h-5 ${
                                    isRefreshing
                                        ? 'text-primary'
                                        : pullDistance >= threshold
                                            ? 'text-success'
                                            : 'text-default-500'
                                }`}
                            />
                        </div>
                    </div>
                </div>
            )}

            {/* Content */}
            <div
                style={{
                    transform: `translateY(${isRefreshing ? threshold / 2 : 0}px)`,
                    transition: isRefreshing ? 'transform 0.2s ease-out' : undefined
                }}
            >
                {children}
            </div>
        </div>
    );
};

export default PullToRefresh;
