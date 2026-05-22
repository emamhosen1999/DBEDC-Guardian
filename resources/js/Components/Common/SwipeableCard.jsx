import React, { useState, useRef, useCallback } from 'react';
import {
    PencilIcon,
    TrashIcon,
    CheckCircleIcon,
    ArrowPathIcon
} from '@heroicons/react/24/outline';

/**
 * Swipeable wrapper component for mobile cards
 * Enables swipe left/right gestures to reveal quick actions
 * 
 * @param {Object} props
 * @param {React.ReactNode} props.children - Card content
 * @param {Function} props.onEdit - Callback when edit action is triggered
 * @param {Function} props.onDelete - Callback when delete action is triggered
 * @param {Function} props.onStatusChange - Callback when status change is triggered
 * @param {boolean} props.disabled - Whether swipe actions are disabled
 * @param {string} props.className - Additional CSS classes
 */
const SwipeableCard = ({ 
    children, 
    onEdit, 
    onDelete, 
    onStatusChange,
    disabled = false,
    className = '' 
}) => {
    const [isRevealed, setIsRevealed] = useState(false);
    const [swipeDirection, setSwipeDirection] = useState(null); // 'left' or 'right'
    const [swipeX, setSwipeX] = useState(0);
    const containerRef = useRef(null);
    const startX = useRef(0);

    const swipeThreshold = 80;

    // Action button opacity based on swipe distance
    const leftActionsOpacity = Math.max(0, Math.min(1, (Math.abs(swipeX) - 40) / (swipeThreshold - 40)));
    const rightActionsOpacity = Math.max(0, Math.min(1, (Math.abs(swipeX) - 40) / (swipeThreshold - 40)));

    const handleTouchStart = useCallback((e) => {
        if (disabled) return;
        startX.current = e.touches[0].clientX;
    }, [disabled]);

    const handleTouchMove = useCallback((e) => {
        if (disabled) return;

        const currentX = e.touches[0].clientX;
        const diff = currentX - startX.current;

        // Limit the swipe distance
        const clampedDiff = Math.max(-swipeThreshold * 1.5, Math.min(swipeThreshold * 1.5, diff));
        setSwipeX(clampedDiff);

        if (Math.abs(clampedDiff) > 20) {
            setSwipeDirection(clampedDiff < 0 ? 'left' : 'right');
        }
    }, [disabled]);

    const handleTouchEnd = useCallback(() => {
        if (disabled) return;

        if (Math.abs(swipeX) >= swipeThreshold) {
            // Snap to revealed position
            if (swipeX < 0) {
                setSwipeX(-swipeThreshold);
                setIsRevealed(true);
                setSwipeDirection('left');
            } else {
                setSwipeX(swipeThreshold);
                setIsRevealed(true);
                setSwipeDirection('right');
            }
        } else {
            // Snap back to closed
            setSwipeX(0);
            setIsRevealed(false);
            setSwipeDirection(null);
        }
    }, [disabled, swipeX]);

    const handleReset = useCallback(() => {
        setSwipeX(0);
        setIsRevealed(false);
        setSwipeDirection(null);
    }, []);

    const handleAction = useCallback((action) => {
        action?.();
        handleReset();
    }, [handleReset]);

    // Close when clicking outside
    const handleClickOutside = useCallback((e) => {
        if (isRevealed && containerRef.current && !containerRef.current.contains(e.target)) {
            handleReset();
        }
    }, [isRevealed, handleReset]);

    // Add click outside listener
    React.useEffect(() => {
        if (isRevealed) {
            document.addEventListener('touchstart', handleClickOutside);
            return () => document.removeEventListener('touchstart', handleClickOutside);
        }
    }, [isRevealed, handleClickOutside]);

    return (
        <div
            ref={containerRef}
            className={`relative overflow-hidden ${className}`}
        >
            {/* Left actions (revealed on swipe right) - Edit/Status */}
            {(swipeDirection === 'right' || swipeX > 0) && (
                <div
                    className="absolute left-0 top-0 bottom-0 flex items-stretch"
                    style={{ width: swipeThreshold, opacity: rightActionsOpacity }}
                >
                    <button
                        onClick={() => handleAction(onEdit)}
                        className="flex-1 flex flex-col items-center justify-center gap-1 bg-primary text-white active:bg-primary/80 transition-colors"
                        aria-label="Edit"
                    >
                        <PencilIcon className="w-5 h-5" />
                        <span className="text-xs font-medium">Edit</span>
                    </button>
                </div>
            )}

            {/* Right actions (revealed on swipe left) - Delete/Status */}
            {(swipeDirection === 'left' || swipeX < 0) && (
                <div
                    className="absolute right-0 top-0 bottom-0 flex items-stretch"
                    style={{ width: swipeThreshold, opacity: leftActionsOpacity }}
                >
                    {onStatusChange && (
                        <button
                            onClick={() => handleAction(onStatusChange)}
                            className="flex-1 flex flex-col items-center justify-center gap-1 bg-success text-white active:bg-success/80 transition-colors"
                            aria-label="Complete"
                        >
                            <CheckCircleIcon className="w-5 h-5" />
                            <span className="text-xs font-medium">Done</span>
                        </button>
                    )}
                    {onDelete && (
                        <button
                            onClick={() => handleAction(onDelete)}
                            className="flex-1 flex flex-col items-center justify-center gap-1 bg-danger text-white active:bg-danger/80 transition-colors"
                            aria-label="Delete"
                        >
                            <TrashIcon className="w-5 h-5" />
                            <span className="text-xs font-medium">Delete</span>
                        </button>
                    )}
                </div>
            )}

            {/* Main content */}
            <div
                style={{ transform: `translateX(${swipeX}px)` }}
                onTouchStart={handleTouchStart}
                onTouchMove={handleTouchMove}
                onTouchEnd={handleTouchEnd}
                className="relative bg-content1"
            >
                {children}
            </div>

            {/* Swipe hint indicator */}
            {!isRevealed && !disabled && (
                <div className="absolute right-2 top-1/2 -translate-y-1/2 opacity-20 pointer-events-none">
                    <ArrowPathIcon className="w-4 h-4 text-default-400" />
                </div>
            )}
        </div>
    );
};

export default SwipeableCard;
