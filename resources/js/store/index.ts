import { create } from 'zustand';

// Global store for app-wide state
export const useAppStore = create((set) => ({
  // App initialization state
  isInitialized: false,
  setIsInitialized: (isInitialized: boolean) => set({ isInitialized }),
  
  // Global loading state
  isLoading: false,
  setIsLoading: (isLoading: boolean) => set({ isLoading }),
  
  // Global error state
  error: null as string | null,
  setError: (error: string | null) => set({ error }),
  clearError: () => set({ error: null }),
}));

export default useAppStore;
