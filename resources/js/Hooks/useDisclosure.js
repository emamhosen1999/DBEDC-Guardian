import { useState, useCallback } from 'react';

/**
 * Lightweight modal/dialog open state (replaces legacy HeroUI useDisclosure).
 */
export function useDisclosure(initialOpen = false) {
    const [isOpen, setIsOpen] = useState(initialOpen);

    const onOpen = useCallback(() => setIsOpen(true), []);
    const onClose = useCallback(() => setIsOpen(false), []);
    const onToggle = useCallback(() => setIsOpen((open) => !open), []);

    return { isOpen, onOpen, onClose, onToggle, setIsOpen };
}

export default useDisclosure;
