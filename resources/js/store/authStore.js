import { create } from 'zustand';

export const useAuthStore = create((set) => ({
  user: null,
  isAuthenticated: false,
  token: null,
  
  setUser: (user) => set({ 
    user, 
    isAuthenticated: !!user 
  }),
  
  setToken: (token) => set({ token }),
  
  logout: () => set({ 
    user: null, 
    isAuthenticated: false, 
    token: null 
  }),
}));

export default useAuthStore;
