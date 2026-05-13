import React, { useEffect, useRef, useState, useMemo, useCallback } from 'react';
import { usePage, router } from '@inertiajs/react';
import { Toaster } from 'sonner';
import { Box, Flex, IconButton, Tooltip } from '@radix-ui/themes';
import { TooltipProvider } from '@radix-ui/react-tooltip';
import { MixerHorizontalIcon,  } from '@radix-ui/react-icons';

import { getPages } from '@/Props/pages.jsx';
import Header from '@/Layouts/Header.jsx';
import Sidebar from '@/Layouts/Sidebar.jsx';
import Breadcrumb from '@/Components/Breadcrumb.jsx';
import BottomNav from '@/Layouts/BottomNav.jsx';
import RadixThemeDrawer from '@/Components/RadixThemeDrawer.jsx';
import UpdateNotification from '@/Components/UpdateNotification.jsx';
import AuthGuard from '@/Components/AuthGuard.jsx';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { TranslationProvider } from '@/Contexts/TranslationContext';
import { GlobalAutoTranslator } from '@/Contexts/GlobalAutoTranslator';
import { AppStateProvider } from '@/Contexts/AppStateContext';
import { useVersionManager } from '@/Hooks/useVersionManager.js';
import { useRadixTheme } from '@/Contexts/RadixThemeContext';
import NavigationProgress from '@/Components/NavigationProgress.jsx';

import '@/utils/serviceWorkerManager.js';

const PageContent = React.memo(({ children, url }) => (
  <div key={url} className="page-enter">
    {children}
  </div>
));
PageContent.displayName = 'PageContent';

const App = React.memo(({ children }) => {
  const { auth, app, url } = usePage().props;
  const isMobile = useMediaQuery('(max-width: 768px)');

  const [sideBarOpen, setSideBarOpen] = useState(() => {
    try { return JSON.parse(localStorage.getItem('sidebarOpen') ?? 'false'); }
    catch { return false; }
  });
  const [themeDrawerOpen, setThemeDrawerOpen] = useState(false);
  const [isUpdating, setIsUpdating] = useState(false);
  const layoutInitialized = useRef(false);

  const { currentVersion, isUpdateAvailable, forceUpdate, dismissUpdate } = useVersionManager();
  const { settings } = useRadixTheme();

  // Build nav pages once per auth identity
  const pages = useMemo(() => {
    const permissions = auth?.permissions || [];
    const roles = auth?.roles || [];
    return getPages(roles, permissions, auth);
  }, [auth?.user?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const toggleSideBar = useCallback(() => {
    setSideBarOpen(prev => {
      const next = !prev;
      try { localStorage.setItem('sidebarOpen', JSON.stringify(next)); } catch {}
      return next;
    });
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
  }, [isMobile]); // eslint-disable-line react-hooks/exhaustive-deps

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


  return (
    <TooltipProvider>
      <TranslationProvider>
        <GlobalAutoTranslator>
          <AppStateProvider>
            {/* Theme drawer (portal, always mounted) */}
            <RadixThemeDrawer open={themeDrawerOpen} onClose={closeThemeDrawer} />

            <AuthGuard auth={auth} url={url}>
              <NavigationProgress />
              <Box style={{ display: 'flex', height: '100vh', overflow: 'hidden', position: 'relative' }}>

                {/* Update notification */}
                <UpdateNotification
                  isVisible={isUpdateAvailable}
                  onUpdate={handleUpdate}
                  onDismiss={dismissUpdate}
                  isUpdating={isUpdating}
                  version={currentVersion}
                />

              <Toaster
                richColors
                closeButton
                position="top-right"
                duration={4000}
                theme={settings.appearance}
                style={{
                  '--border-radius': 'var(--radius-3)',
                  '--normal-bg':      'var(--color-panel-solid)',
                  '--normal-border':  'var(--gray-a5)',
                  '--normal-text':    'var(--gray-12)',
                  '--success-bg':     'var(--green-a3)',
                  '--success-border': 'var(--green-a6)',
                  '--success-text':   'var(--green-11)',
                  '--error-bg':       'var(--red-a3)',
                  '--error-border':   'var(--red-a6)',
                  '--error-text':     'var(--red-11)',
                  '--warning-bg':     'var(--amber-a3)',
                  '--warning-border': 'var(--amber-a6)',
                  '--warning-text':   'var(--amber-11)',
                  '--info-bg':        'var(--accent-a3)',
                  '--info-border':    'var(--accent-a6)',
                  '--info-text':      'var(--accent-11)',
                }}
                toastOptions={{
                  style: { fontFamily: 'inherit' },
                }}
              />

              {/* Mobile sidebar overlay — CSS opacity transition */}
              {isMobile && (
                <Box
                  onClick={toggleSideBar}
                  style={{
                    position: 'fixed', inset: 0,
                    background: 'rgba(0,0,0,0.45)',
                    zIndex: 40,
                    backdropFilter: 'blur(2px)',
                    opacity: sideBarOpen ? 1 : 0,
                    pointerEvents: sideBarOpen ? 'auto' : 'none',
                    transition: 'opacity 200ms ease',
                  }}
                />
              )}

              {/* Sidebar — CSS transform/width transition */}
              <Box
                as="aside"
                style={isMobile ? {
                  position: 'fixed', top: 0, left: 0, height: '100%', zIndex: 50,
                  transform: sideBarOpen ? 'translateX(0)' : 'translateX(-100%)',
                  transition: 'transform 250ms cubic-bezier(0.4,0,0.2,1)',
                  flexShrink: 0,
                } : {
                  width: sideBarOpen ? 240 : 56,
                  flexShrink: 0,
                  transition: 'width 250ms cubic-bezier(0.4,0,0.2,1)',
                  overflow: 'hidden',
                }}
              >
                <Sidebar
                  toggleSideBar={toggleSideBar}
                  pages={pages}
                  url={url}
                  sideBarOpen={sideBarOpen}
                />
              </Box>

              {/* Main column */}
              <Flex
                direction="column"
                style={{ flex: 1, minWidth: 0, height: '100vh', overflow: 'hidden' }}
              >
                {/* Header */}
                <Header
                  toggleSideBar={toggleSideBar}
                  sideBarOpen={sideBarOpen}
                  toggleThemeDrawer={toggleThemeDrawer}
                />

                {/* Breadcrumb */}
                <Breadcrumb />

                {/* Page content */}
                <Box
                  as="main"
                  id="main-content"
                  style={{
                    flex: 1,
                    overflowY: 'auto',
                    overflowX: 'hidden',
                    paddingBottom: isMobile && auth?.user ? 68 : 0,
                  }}
                  role="main"
                  aria-label="Main content"
                >
                  <PageContent url={url}>
                    {children}
                  </PageContent>
                </Box>

                {/* Mobile bottom nav */}
                {isMobile && auth?.user && (
                  <BottomNav toggleThemeDrawer={toggleThemeDrawer} />
                )}
              </Flex>

              {/* Floating theme FAB — desktop only */}
              {!isMobile && auth?.user && (
                <Box style={{ position: 'fixed', bottom: 24, right: 24, zIndex: 50 }}>
                  <Tooltip content="Customize theme" side="left">
                    <IconButton
                      size="4"
                      variant="solid"
                      onClick={toggleThemeDrawer}
                      aria-label="Open theme settings"
                      style={{ borderRadius: '50%', boxShadow: 'var(--shadow-6)' }}
                    >
                      <MixerHorizontalIcon style={{ width: 20, height: 20 }} />
                    </IconButton>
                  </Tooltip>
                </Box>
              )}

            </Box>
          </AuthGuard>
        </AppStateProvider>
      </GlobalAutoTranslator>
    </TranslationProvider>
    </TooltipProvider>
  );
});

App.displayName = 'App';
export default App;
