import { create } from 'zustand';

interface UIState {
  // Sidebar state
  sidebarOpen: boolean;
  setSidebarOpen: (open: boolean) => void;
  toggleSidebar: () => void;
  
  // Mobile menu state
  mobileMenuOpen: boolean;
  setMobileMenuOpen: (open: boolean) => void;
  toggleMobileMenu: () => void;
  
  // Theme state (managed by RadixThemeContext, but stored here for persistence)
  theme: 'light' | 'dark';
  setTheme: (theme: 'light' | 'dark') => void;
  
  // Modal state
  activeModal: string | null;
  setActiveModal: (modal: string | null) => void;
  closeModal: () => void;
  
  // Notification state
  notification: {
    show: boolean;
    message: string;
    type: 'success' | 'error' | 'warning' | 'info';
  } | null;
  showNotification: (message: string, type: 'success' | 'error' | 'warning' | 'info') => void;
  hideNotification: () => void;
}

export const useUIStore = create<UIState>((set) => ({
  sidebarOpen: true,
  setSidebarOpen: (open) => set({ sidebarOpen: open }),
  toggleSidebar: () => set((state) => ({ sidebarOpen: !state.sidebarOpen })),
  
  mobileMenuOpen: false,
  setMobileMenuOpen: (open) => set({ mobileMenuOpen: open }),
  toggleMobileMenu: () => set((state) => ({ mobileMenuOpen: !state.mobileMenuOpen })),
  
  theme: 'dark',
  setTheme: (theme) => set({ theme }),
  
  activeModal: null,
  setActiveModal: (modal) => set({ activeModal: modal }),
  closeModal: () => set({ activeModal: null }),
  
  notification: null,
  showNotification: (message, type) => set({ 
    notification: { show: true, message, type } 
  }),
  hideNotification: () => set({ notification: null }),
}));

export default useUIStore;
