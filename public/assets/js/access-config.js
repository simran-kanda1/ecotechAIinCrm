/**
 * Shared CRM tool catalog for settings UI + access control preview.
 * Keep in sync with ChatOrchestrator::APPROVED_ENDPOINTS.
 */

/** @typedef {{ id: string, first_name: string, last_name: string, email?: string, role: 'super_admin'|'user', roles?: string[], role_names?: string[], active?: boolean }} AppUser */

/** @type {AppUser[]} */
const SEED_USERS = [
    {
        id: 'preview_mark',
        first_name: 'Mark',
        last_name: 'Ginzburg',
        email: '',
        role: 'super_admin',
    },
];

/** Preview identity until CRM login is wired up. */
const DEFAULT_USER_NAME = { first_name: 'Mark', last_name: 'Ginzburg' };

const CURRENT_USER_KEY = 'ecotech_current_user_id';
const USERS_STORAGE_KEY = 'ecotech_users_v1';
const NAME_OVERRIDES_KEY = 'ecotech_name_overrides_v1';
const ACL_STORAGE_KEY = 'ecotech_endpoint_acl_v1';

/** @returns {Record<string, { first_name?: string, last_name?: string }>} */
function loadNameOverrides() {
    try {
        const raw = localStorage.getItem(NAME_OVERRIDES_KEY);
        return raw ? JSON.parse(raw) : {};
    } catch {
        return {};
    }
}

/** @param {AppUser[]} users */
function applyNameOverrides(users) {
    const overrides = loadNameOverrides();
    return users.map((u) => {
        const o = overrides[u.id];
        if (!o) return { ...u };
        return {
            ...u,
            first_name: o.first_name !== undefined ? o.first_name : u.first_name,
            last_name: o.last_name !== undefined ? o.last_name : u.last_name,
        };
    });
}

function namesMatch(user, first, last) {
    return user.first_name.trim().toLowerCase() === first.toLowerCase()
        && user.last_name.trim().toLowerCase() === last.toLowerCase();
}

function findDefaultUser(users) {
    return users.find((u) => namesMatch(u, DEFAULT_USER_NAME.first_name, DEFAULT_USER_NAME.last_name)) || null;
}

/** @type {AppUser[]} */
export let DUMMY_USERS = applyNameOverrides(SEED_USERS.map((u) => ({ ...u })));

/** @type {AppUser} */
export let CURRENT_USER = DUMMY_USERS[0];

function pickCurrentUser(users) {
    // Until CRM session login is connected, always preview as Mark Ginzburg.
    const mark = findDefaultUser(users);
    if (mark) {
        return { ...mark, role: 'super_admin' };
    }
    return {
        id: 'preview_mark',
        first_name: DEFAULT_USER_NAME.first_name,
        last_name: DEFAULT_USER_NAME.last_name,
        email: '',
        role: 'super_admin',
    };
}

/** @param {AppUser[]} users */
export function setUsersFromCrm(users) {
    let list = applyNameOverrides(users.map((u) => {
        const copy = { ...u };
        if (namesMatch(copy, DEFAULT_USER_NAME.first_name, DEFAULT_USER_NAME.last_name)) {
            copy.role = 'super_admin';
        }
        return copy;
    }));

    if (!findDefaultUser(list)) {
        list = [
            {
                id: 'preview_mark',
                first_name: DEFAULT_USER_NAME.first_name,
                last_name: DEFAULT_USER_NAME.last_name,
                email: '',
                role: 'super_admin',
            },
            ...list,
        ];
    }

    DUMMY_USERS = list.length ? list : applyNameOverrides(SEED_USERS.map((u) => ({ ...u })));
    CURRENT_USER = pickCurrentUser(DUMMY_USERS);
    localStorage.setItem(CURRENT_USER_KEY, CURRENT_USER.id);
    localStorage.setItem(USERS_STORAGE_KEY, JSON.stringify(DUMMY_USERS));
}

export async function fetchCrmUsers() {
    const res = await fetch('api/crm-users.php');
    const data = await res.json();
    if (!res.ok) {
        throw new Error(data.error || 'Failed to load CRM users');
    }
    const users = Array.isArray(data.users) ? data.users : [];
    if (users.length) {
        setUsersFromCrm(users);
    }
    return { users: DUMMY_USERS, roles: data.roles || [] };
}

/**
 * @param {string} userId
 * @param {{ first_name?: string, last_name?: string }} patch
 */
export function updateUserName(userId, patch) {
    const overrides = loadNameOverrides();
    const existing = DUMMY_USERS.find((u) => u.id === userId);
    overrides[userId] = {
        first_name: patch.first_name !== undefined
            ? patch.first_name.trim()
            : (overrides[userId]?.first_name ?? existing?.first_name ?? ''),
        last_name: patch.last_name !== undefined
            ? patch.last_name.trim()
            : (overrides[userId]?.last_name ?? existing?.last_name ?? ''),
    };
    localStorage.setItem(NAME_OVERRIDES_KEY, JSON.stringify(overrides));

    DUMMY_USERS = applyNameOverrides(DUMMY_USERS.map((u) => ({ ...u })));
    CURRENT_USER = pickCurrentUser(DUMMY_USERS);
    return DUMMY_USERS.find((u) => u.id === userId) || null;
}

/** @typedef {{ id: string, label: string, description: string, group: string }} EndpointMeta */

/** @type {EndpointMeta[]} */
export const ENDPOINT_CATALOG = [
    { id: 'getOpportunity', label: 'Get Opportunity', description: 'Lookup opportunity by ID, phone, or email', group: 'Core' },
    { id: 'insertLead', label: 'Insert Lead', description: 'Create a new lead', group: 'Core' },
    { id: 'addLeadComment', label: 'Add Lead Comment', description: 'Comment on an existing lead', group: 'Core' },
    { id: 'getEntityUrl', label: 'Entity URL', description: 'Deep-link into a CRM opportunity/lead/client/service', group: 'Core' },
    { id: 'resolveEntityLinks', label: 'Resolve Entity Links', description: 'Batch CRM deep-links for tables', group: 'Core' },
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
    { id: 'getUsers', label: 'Get Users', description: 'CRM users list', group: 'Admin' },
    { id: 'getRoles', label: 'Get Roles', description: 'CRM roles list', group: 'Admin' },
    { id: 'createChart', label: 'Create Chart', description: 'Pie / bar / line charts in chat', group: 'Reports' },
    { id: 'createReport', label: 'Create PDF Report', description: 'Printable report opened in a new tab', group: 'Reports' },
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
