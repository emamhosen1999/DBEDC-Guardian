import { create } from 'zustand';

// Global store for app-wide state
export const useAppStore = create((set) => ({
  // App initialization state
  isInitialized: false,
  setIsInitialized: (isInitialized) => set({ isInitialized }),
  
  // Global loading state
  isLoading: false,
  setIsLoading: (isLoading) => set({ isLoading }),
  
  // Global error state
  error: null,
  setError: (error) => set({ error }),
  clearError: () => set({ error: null }),
}));

export default useAppStore;
