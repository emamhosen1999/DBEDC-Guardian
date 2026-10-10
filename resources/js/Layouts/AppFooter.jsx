import React from 'react';
import { usePage } from '@inertiajs/react';

/**
 * Cyber's fixed app footer (.app-footer.cyber-footer): one 34px strip pinned to the bottom of the content column, from
 * the sidebar's edge to the window's edge, always visible while the page scrolls above it. Real values only: the
 * copyright holder (config app.copyright, owner: Emam Hosen) and the running Guardian version.
 */
export default function AppFooter() {
  const { app } = usePage().props;
  const year = new Date().getFullYear();

  return (
    <footer className="dl-footer" role="contentinfo">
      <span className="dl-footer__copy">© {year} {app?.copyright || app?.name || 'DBEDC'}</span>
      {app?.version && <span className="dl-footer__meta">Guardian v{app.version}</span>}
    </footer>
  );
}
