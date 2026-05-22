import { create } from 'zustand';

interface User {
  id: number;
  name: string;
  email: string;
  employee_id?: string;
  department_id?: number;
  designation_id?: number;
  profile_image?: string;
  is_active?: boolean;
  roles?: Array<{ id: number; name: string }>;
}

interface AuthState {
  user: User | null;
  isAuthenticated: boolean;
  token: string | null;
  setUser: (user: User | null) => void;
  setToken: (token: string | null) => void;
  logout: () => void;
}

export const useAuthStore = create<AuthState>((set) => ({
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
