/**
 * Shared CRM tool catalog for settings UI + access control preview.
 * Keep in sync with ChatOrchestrator::APPROVED_ENDPOINTS.
 *
 * Users / current-user APIs are still in progress on the CRM side —
 * seed users stand in until those land. Edits (names, ACL) persist in localStorage.
 */

/** @typedef {{ id: string, first_name: string, last_name: string, email: string, role: 'super_admin'|'user' }} AppUser */

/** @type {AppUser[]} */
const SEED_USERS = [
    {
        id: 'u_admin',
        first_name: 'Alex',
        last_name: 'Chen',
        email: 'alex.chen@ecotech.ca',
        role: 'super_admin',
    },
    {
        id: 'u_jordan',
        first_name: 'Jordan',
        last_name: 'Patel',
        email: 'jordan.patel@ecotech.ca',
        role: 'user',
    },
    {
        id: 'u_sam',
        first_name: 'Sam',
        last_name: 'Nguyen',
        email: 'sam.nguyen@ecotech.ca',
        role: 'user',
    },
    {
        id: 'u_morgan',
        first_name: 'Morgan',
        last_name: 'Reid',
        email: 'morgan.reid@ecotech.ca',
        role: 'user',
    },
    {
        id: 'u_casey',
        first_name: 'Casey',
        last_name: 'Brooks',
        email: 'casey.brooks@ecotech.ca',
        role: 'user',
    },
];

const CURRENT_USER_ID = 'u_admin';
const USERS_STORAGE_KEY = 'ecotech_users_v1';
const ACL_STORAGE_KEY = 'ecotech_endpoint_acl_v1';

/** @returns {AppUser[]} */
function loadUsers() {
    try {
        const raw = localStorage.getItem(USERS_STORAGE_KEY);
        if (!raw) return SEED_USERS.map((u) => ({ ...u }));
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed) || parsed.length === 0) {
            return SEED_USERS.map((u) => ({ ...u }));
        }
        // Merge seed (for new fields/users) with saved name edits
        return SEED_USERS.map((seed) => {
            const saved = parsed.find((p) => p.id === seed.id);
            return saved
                ? {
                    ...seed,
                    first_name: String(saved.first_name ?? seed.first_name),
                    last_name: String(saved.last_name ?? seed.last_name),
                }
                : { ...seed };
        });
    } catch {
        return SEED_USERS.map((u) => ({ ...u }));
    }
}

/** @type {AppUser[]} */
export let DUMMY_USERS = loadUsers();

/** @type {AppUser} */
export let CURRENT_USER = DUMMY_USERS.find((u) => u.id === CURRENT_USER_ID) || DUMMY_USERS[0];

/** @param {AppUser[]} users */
export function saveUsers(users) {
    localStorage.setItem(USERS_STORAGE_KEY, JSON.stringify(users));
    DUMMY_USERS = users.map((u) => ({ ...u }));
    CURRENT_USER = DUMMY_USERS.find((u) => u.id === CURRENT_USER_ID) || DUMMY_USERS[0];
}

/**
 * @param {string} userId
 * @param {{ first_name?: string, last_name?: string }} patch
 */
export function updateUserName(userId, patch) {
    const next = DUMMY_USERS.map((u) => {
        if (u.id !== userId) return { ...u };
        return {
            ...u,
            first_name: patch.first_name !== undefined ? patch.first_name.trim() : u.first_name,
            last_name: patch.last_name !== undefined ? patch.last_name.trim() : u.last_name,
        };
    });
    saveUsers(next);
    return DUMMY_USERS.find((u) => u.id === userId) || null;
}

/** @typedef {{ id: string, label: string, description: string, group: string }} EndpointMeta */

/** @type {EndpointMeta[]} */
export const ENDPOINT_CATALOG = [
    { id: 'getOpportunity', label: 'Get Opportunity', description: 'Lookup opportunity by ID, phone, or email', group: 'Core' },
    { id: 'insertLead', label: 'Insert Lead', description: 'Create a new lead', group: 'Core' },
    { id: 'addLeadComment', label: 'Add Lead Comment', description: 'Comment on an existing lead', group: 'Core' },
    { id: 'getSalespersonServiceArea', label: 'Salesperson Service Area', description: 'List salespeople and service areas', group: 'Sales' },
    { id: 'getSalespersonAvailability', label: 'Salesperson Availability', description: 'Appointments / availability by date', group: 'Sales' },
    { id: 'getSalespersonPerformance', label: 'Salesperson Performance', description: 'Monthly performance metrics', group: 'Sales' },
    { id: 'getLeads', label: 'Get Leads', description: 'Search leads by filters', group: 'Pipeline' },
    { id: 'getAppointments', label: 'Get Appointments', description: 'Booked appointments by date/status/rep', group: 'Pipeline' },
    { id: 'getFollowUpsDue', label: 'Follow-ups Due', description: 'Outstanding follow-ups', group: 'Pipeline' },
    { id: 'getCanvassers', label: 'Get Canvassers', description: 'Door knockers / canvassers list', group: 'Canvassing' },
    { id: 'getSalesRabbitOrganizations', label: 'SalesRabbit Orgs', description: 'SalesRabbit organizations', group: 'Canvassing' },
    { id: 'getCanvassingDoorKnocks', label: 'Door Knocks', description: 'Paginated door-knock records', group: 'Canvassing' },
    { id: 'getCanvassingOpportunities', label: 'Canvassing Opportunities', description: 'Opportunities from door knocking', group: 'Canvassing' },
    { id: 'getCanvassingLeads', label: 'Canvassing Leads', description: 'Leads created by canvassers', group: 'Canvassing' },
    { id: 'getCanvassingQuotedLeads', label: 'Canvassing Quoted Leads', description: 'Quoted canvasser leads', group: 'Canvassing' },
    { id: 'getCanvassingClients', label: 'Canvassing Clients', description: 'Sold clients attributed to canvassers', group: 'Canvassing' },
    { id: 'getCanvassingBonus', label: 'Canvassing Bonus', description: 'Bonus totals per canvasser', group: 'Canvassing' },
    { id: 'getCanvassingPerformance', label: 'Canvassing Performance', description: 'Door-knock metrics & conversion', group: 'Canvassing' },
];

export const ALL_ENDPOINT_IDS = ENDPOINT_CATALOG.map((e) => e.id);

/** @returns {Record<string, string[]>} */
export function loadAcl() {
    try {
        const raw = localStorage.getItem(ACL_STORAGE_KEY);
        if (!raw) return defaultAcl();
        const parsed = JSON.parse(raw);
        return { ...defaultAcl(), ...parsed };
    } catch {
        return defaultAcl();
    }
}

/** @param {Record<string, string[]>} acl */
export function saveAcl(acl) {
    localStorage.setItem(ACL_STORAGE_KEY, JSON.stringify(acl));
}

function defaultAcl() {
    /** @type {Record<string, string[]>} */
    const acl = {};
    for (const u of DUMMY_USERS) {
        acl[u.id] = [...ALL_ENDPOINT_IDS];
    }
    return acl;
}

export function displayName(user) {
    return `${user.first_name} ${user.last_name}`.trim();
}

export function isSuperAdmin(user = CURRENT_USER) {
    return user.role === 'super_admin';
}
