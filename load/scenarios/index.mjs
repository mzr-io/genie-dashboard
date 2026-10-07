import dashboardResultsFanIn from './dashboard-results-fan-in.mjs';
import reverbFanOut from './reverb-fan-out.mjs';
import syncDispatchHotCold from './sync-dispatch-hot-cold.mjs';
import workspaceBudgets from './workspace-budgets.mjs';

export const placeholders = [
    dashboardResultsFanIn,
    syncDispatchHotCold,
    workspaceBudgets,
    reverbFanOut,
];

export const skippedReport = () =>
    placeholders.map(({ id, name, status, reason }) => ({
        id,
        name,
        status,
        reason,
    }));
