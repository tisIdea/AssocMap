import { initDisclosures } from './core/helpers.js';
import { initDialogs } from './core/dialogs.js';
import { initAdminNavigation } from './layouts/admin-navigation.js';
import { initWorkspaceSidebar } from './layouts/workspace-sidebar.js';
import { initPublicNavigation } from './layouts/public-navigation.js';
import { initForms } from './pages/admin/forms.js';
import { initUserMenus } from './pages/admin/users.js';
import { initAssociationUi } from './pages/admin/associations.js';
import { initReports } from './pages/admin/reports.js';
import { initStaffLogin } from './pages/staff-login.js';
import { initUserInvitations } from './pages/user-invitations.js';
import { initProjectClosure } from './pages/project-closure.js';
import { initProgramLookups } from './pages/program-lookups.js';
import { initProgramActivities } from './pages/program-activities.js';
import { initFeedback, showFeedback } from './core/feedback.js';
import { mountSummaryCounters } from './components/summary-counter.js';
import { initContextualReports } from './components/contextual-report.js';

initFeedback();
initDisclosures();
initDialogs();
const sidebar = initWorkspaceSidebar();
initAdminNavigation(sidebar);
initPublicNavigation();
initForms();
initUserMenus();
initAssociationUi();
initReports();
initContextualReports();
mountSummaryCounters();
initStaffLogin();
initUserInvitations();
initProjectClosure();
initProgramLookups();
initProgramActivities();

const gisRoot = document.querySelector('[data-gis-workspace], [data-gis-editor]');
if (gisRoot) {
    const module = gisRoot.hasAttribute('data-gis-editor') ? import('./pages/gis-editor.js') : import('./pages/gis-workspace.js');
    module.then(({ initGis }) => initGis(gisRoot)).catch(() => {
        const status = gisRoot.querySelector('[data-gis-status], [data-gis-editor-status]');
        status.textContent = 'GIS is unavailable. Reload or try again later.';
        status.classList.add('notice-error');
        status.setAttribute('role', 'alert');
        void showFeedback({ type: 'error', title: 'GIS Unavailable', message: 'GIS could not be loaded. Reload the page or try again later.' });
        status.dataset.feedbackFallback = '';
    });
}
