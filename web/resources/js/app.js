import { initDisclosures } from './core/helpers.js';
import { initDialogs } from './core/dialogs.js';
import { initAdminNavigation } from './layouts/admin-navigation.js';
import { initPublicNavigation } from './layouts/public-navigation.js';
import { initForms } from './pages/admin/forms.js';
import { initReports } from './pages/admin/reports.js';
import { initStaffLogin } from './pages/staff-login.js';

initDisclosures();
initDialogs();
initAdminNavigation();
initPublicNavigation();
initForms();
initReports();
initStaffLogin();
