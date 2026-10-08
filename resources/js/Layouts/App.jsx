import React, { useEffect, useState, useCallback, useRef, useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { TooltipProvider } from '@radix-ui/react-tooltip';
import RadixToaster from '@/Components/RadixToaster';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';

import { getPages } from '@/Props/pages.jsx';
import Header from '@/Layouts/Header.jsx';
import Sidebar from '@/Layouts/Sidebar.jsx';
import Breadcrumb from '@/Components/Breadcrumb.jsx';
import BottomNav from '@/Layouts/BottomNav.jsx';
import RadixThemeDrawer from '@/Components/RadixThemeDrawer.jsx';
import UpdateNotification from '@/Components/UpdateNotification.jsx';
import AuthGuard from '@/Components/AuthGuard.jsx';
import { TranslationProvider } from '@/Contexts/TranslationContext';
import { GlobalAutoTranslator } from '@/Contexts/GlobalAutoTranslator';
import { AppStateProvider } from '@/Contexts/AppStateContext';
import { useVersionManager } from '@/Hooks/useVersionManager.js';
import FloatingAeon from '@/aeon/FloatingAeon';

import '@/utils/serviceWorkerManager.js';

import queryClient from '@/api/reactQueryClient';

/* Cyber moves the sidebar off-canvas below 1200px; Guardian's bottom
   navigation stays a phone-only (≤768px) feature. */
const COMPACT_QUERY = '(max-width: 1199.98px)';
const PHONE_QUERY = '(max-width: 768px)';

const PageContent = React.memo(({ children, url }) => (
  <div key={url} className="page-enter">
    {children}
  </div>
));
PageContent.displayName = 'PageContent';

/*
 * Desktop (≥1200px): shown or hidden (Cyber .app-sidebar-collapsed),
 * remembered in localStorage. With nothing stored yet it starts shown on
 * desktop (Cyber's default) and closed on smaller screens.
 */
function initialSidebarOpen() {
  try {
    const stored = localStorage.getItem('sidebarOpen');
    if (stored !== null) return JSON.parse(stored);
  } catch { /* storage unavailable */ }
  return !(typeof window !== 'undefined' && window.matchMedia?.(COMPACT_QUERY).matches);
}

const App = React.memo(({ children }) => {
  const { auth, app, url, features } = usePage().props;
  const isMobile = useMediaQuery(COMPACT_QUERY);
  const isPhone = useMediaQuery(PHONE_QUERY);

  const [sideBarOpen, setSideBarOpen] = useState(initialSidebarOpen);
  // Which desktop animation to play (Cyber appSidebarCollapse / appSidebarExpand);
  // null on first render so a page load never animates.
  const [sidebarMotion, setSidebarMotion] = useState(null);
  const [themeDrawerOpen, setThemeDrawerOpen] = useState(false);
  const [isUpdating, setIsUpdating] = useState(false);
  const layoutInitialized = useRef(false);

  const { currentVersion, isUpdateAvailable, forceUpdate, dismissUpdate } = useVersionManager();

  // Build nav pages once per auth identity
  const pages = useMemo(() => {
    const permissions = auth?.permissions || [];
    const roles = auth?.roles || [];
    return getPages(roles, permissions, auth, features);
  }, [auth?.user?.id, features?.hr_payroll]); // eslint-disable-line react-hooks/exhaustive-deps

  const sideBarOpenRef = useRef(sideBarOpen);
  sideBarOpenRef.current = sideBarOpen;

  const toggleSideBar = useCallback(() => {
    const next = !sideBarOpenRef.current;
    try { localStorage.setItem('sidebarOpen', JSON.stringify(next)); } catch {}
    setSideBarOpen(next);
    setSidebarMotion(next ? 'expand' : 'collapse');
  }, []);

  const toggleThemeDrawer = useCallback(() => setThemeDrawerOpen(p => !p), []);
  const closeThemeDrawer = useCallback(() => setThemeDrawerOpen(false), []);

  const handleUpdate = useCallback(async () => {
    setIsUpdating(true);
    try { await forceUpdate(); } catch { setIsUpdating(false); }
  }, [forceUpdate]);

  // Auto-close sidebar on mobile
  useEffect(() => {
    if (isMobile && sideBarOpen) {
      setSideBarOpen(false);
      try { localStorage.setItem('sidebarOpen', 'false'); } catch {}
    }
    setSidebarMotion(null);
  }, [isMobile]); // eslint-disable-line react-hooks/exhaustive-deps

  // Escape closes the mobile navigation drawer
  useEffect(() => {
    if (!isMobile || !sideBarOpen) return undefined;
    const onKeyDown = (event) => {
      if (event.key === 'Escape') toggleSideBar();
    };
    document.addEventListener('keydown', onKeyDown);
    return () => document.removeEventListener('keydown', onKeyDown);
  }, [isMobile, sideBarOpen, toggleSideBar]);

  // Firebase init (lazy)
  useEffect(() => {
    if (!auth?.user || layoutInitialized.current) return;
    let alive = true;
    import('@/utils/firebaseInit.js')
      .then(({ initFirebase }) => { if (alive) { initFirebase(); layoutInitialized.current = true; } })
      .catch(() => {});
    return () => { alive = false; };
  }, [auth?.user?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  // Hide loading screen
  useEffect(() => {
    if (auth?.user && window.AppLoader) {
      const t = setTimeout(() => window.AppLoader.hideLoading(), 400);
      return () => clearTimeout(t);
    }
  }, [auth?.user]); // eslint-disable-line react-hooks/exhaustive-deps

  // Clear React Query cache when user ID changes (prevent cache leakage between sessions)
  useEffect(() => {
    queryClient.clear();
  }, [auth?.user?.id]);

  const shellClass = [
    'dl-app',
    isMobile ? 'dl-app--mobile' : '',
    isPhone ? 'dl-app--phone' : '',
    isMobile && sideBarOpen ? 'dl-app--nav-open' : '',
    !isMobile && !sideBarOpen ? 'dl-app--collapsed' : '',
    !isMobile && sidebarMotion === 'collapse' ? 'dl-app--collapsing' : '',
    !isMobile && sidebarMotion === 'expand' ? 'dl-app--expanding' : '',
  ].filter(Boolean).join(' ');

  return (
    <TooltipProvider>
      <TranslationProvider>
        <GlobalAutoTranslator>
          <AppStateProvider>
            {/* Theme drawer (portal, always mounted) */}
            <RadixThemeDrawer open={themeDrawerOpen} onClose={closeThemeDrawer} />

            <AuthGuard auth={auth} url={url}>
              <div className={shellClass}>

                {/* Update notification */}
                <UpdateNotification
                  isVisible={isUpdateAvailable}
                  onUpdate={handleUpdate}
                  onDismiss={dismissUpdate}
                  isUpdating={isUpdating}
                  version={currentVersion}
                />

                <RadixToaster
                  position="top-right"
                  duration={4000}
                  richColors
                  closeButton
                />

                <Header
                  toggleSideBar={toggleSideBar}
                  sideBarOpen={sideBarOpen}
                  toggleThemeDrawer={toggleThemeDrawer}
                />

                <div className="dl-app__body">
                  {/* Mobile drawer backdrop (Escape and the header toggle also close it) */}
                  {isMobile && (
                    <div className="dl-app__backdrop" aria-hidden="true" onClick={toggleSideBar} />
                  )}

                  <Sidebar
                    toggleSideBar={toggleSideBar}
                    pages={pages}
                    url={url}
                    sideBarOpen={sideBarOpen}
                  />

                  <div className="dl-content">
                    <Breadcrumb />

                    <main
                      id="main-content"
                      className="dl-main"
                      role="main"
                      aria-label="Main content"
                    >
                      <ErrorBoundary>
                        <PageContent url={url}>
                          {children}
                        </PageContent>
                      </ErrorBoundary>
                    </main>
                  </div>
                </div>

                {/* Phone bottom nav */}
                {isPhone && auth?.user && (
                  <BottomNav toggleThemeDrawer={toggleThemeDrawer} />
                )}

                {/* Floating Aeon AI Copilot launcher */}
                <FloatingAeon />
              </div>
            </AuthGuard>

          </AppStateProvider>
        </GlobalAutoTranslator>
      </TranslationProvider>
    </TooltipProvider>
  );
});

App.displayName = 'App';
export default App;
