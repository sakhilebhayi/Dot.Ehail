import './bootstrap';
import { initInlineActions } from './eh/inline-actions.js';
import { initDashboardCounts } from './eh/dashboard.js';
import { initHeader } from './eh/header.js';

initInlineActions();
initHeader();
initDashboardCounts();
