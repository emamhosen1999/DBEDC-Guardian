import { create } from 'zustand';

export const useUIStore = create((set) => ({
  // Sidebar state
  sidebarOpen: true,
  setSidebarOpen: (open) => set({ sidebarOpen: open }),
  toggleSidebar: () => set((state) => ({ sidebarOpen: !state.sidebarOpen })),
  
  // Mobile menu state
  mobileMenuOpen: false,
  setMobileMenuOpen: (open) => set({ mobileMenuOpen: open }),
  toggleMobileMenu: () => set((state) => ({ mobileMenuOpen: !state.mobileMenuOpen })),
  
  // Theme state
  theme: 'dark',
  setTheme: (theme) => set({ theme }),
  
  // Modal state
  activeModal: null,
  setActiveModal: (modal) => set({ activeModal: modal }),
  closeModal: () => set({ activeModal: null }),
  
  // Notification state
  notification: null,
  showNotification: (message, type) => set({ 
    notification: { show: true, message, type } 
  }),
  hideNotification: () => set({ notification: null }),
}));

export default useUIStore;
