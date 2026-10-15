import { initDisclosures } from './core/helpers.js';
import { initDialogs } from './core/dialogs.js';
import { initAdminNavigation } from './layouts/admin-navigation.js';
import { initPublicNavigation } from './layouts/public-navigation.js';
import { initForms } from './pages/admin/forms.js';
import { initReports } from './pages/admin/reports.js';
import { initStaffLogin } from './pages/staff-login.js';
import { initUserInvitations } from './pages/user-invitations.js';

initDisclosures();
initDialogs();
initAdminNavigation();
initPublicNavigation();
initForms();
initReports();
initStaffLogin();
initUserInvitations();

const gisRoot = document.querySelector('[data-gis-workspace], [data-gis-editor]');
if (gisRoot) {
    const module = gisRoot.hasAttribute('data-gis-editor') ? import('./pages/gis-editor.js') : import('./pages/gis-workspace.js');
    module.then(({ initGis }) => initGis(gisRoot)).catch(() => {
        const status = gisRoot.querySelector('[data-gis-status], [data-gis-editor-status]');
        status.textContent = 'GIS is unavailable. Reload or try again later.';
        status.classList.add('notice-error');
        status.setAttribute('role', 'alert');
    });
}
