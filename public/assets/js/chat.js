import { initializeApp } from 'https://www.gstatic.com/firebasejs/11.0.2/firebase-app.js';
import {
    getFirestore,
    collection,
    doc,
    addDoc,
    setDoc,
    query,
    where,
    orderBy,
    onSnapshot,
    getDocs,
    getDoc,
    serverTimestamp,
    updateDoc,
    writeBatch,
} from 'https://www.gstatic.com/firebasejs/11.0.2/firebase-firestore.js';
import { firebaseConfig, COLLECTIONS } from './firebase-config.js';
import {
    CURRENT_USER,
    DUMMY_USERS,
    ENDPOINT_CATALOG,
    ALL_ENDPOINT_IDS,
    loadAcl,
    saveAcl,
    updateUserName,
    displayName,
    isSuperAdmin,
    fetchCrmUsers,
} from './access-config.js';

const app = initializeApp(firebaseConfig);
const db = getFirestore(app);

const messagesEl = document.getElementById('messages');
const messagesScrollEl = document.getElementById('messages-scroll');
const form = document.getElementById('chat-form');
const input = document.getElementById('message-input');
const sendBtn = document.getElementById('send-btn');
const loadingEl = document.getElementById('loading');
const sessionListEl = document.getElementById('session-list');
const newChatBtn = document.getElementById('new-chat-btn');
const settingsBtn = document.getElementById('settings-btn');
const settingsModal = document.getElementById('settings-modal');
const headerUserEl = document.getElementById('header-user');

const SESSION_KEY = 'ecotech_chat_session_id';
const BROWSER_KEY = 'ecotech_browser_id';

const SUGGESTIONS = [
    'Who has appointments tomorrow?',
    'How did sales perform this month?',
    'Follow-ups due in YYZ',
    'Pie chart of lead statuses in YYZ',
];

const ARTIFACT_MARKER = '<!--ecotech-artifacts:';
const CHART_COLORS = ['#2f7a38', '#e8912a', '#4a9a52', '#d4781a', '#1a5c24', '#f0b35a', '#7ab87f', '#8a5a20'];

let browserId = localStorage.getItem(BROWSER_KEY);
if (!browserId) {
    browserId = 'browser_' + crypto.randomUUID();
    localStorage.setItem(BROWSER_KEY, browserId);
}

let currentSessionId = localStorage.getItem(SESSION_KEY);
let localHistory = [];
let unsubscribeMessages = null;

/** @type {Record<string, string[]>} */
let acl = loadAcl();
let selectedManageUserId = null;
/** @type {Set<string>|null} */
let draftEndpoints = null;
let aclDirty = false;
let profileDirty = false;

function generateSessionId() {
    return 'sess_' + crypto.randomUUID();
}

function setSessionId(id) {
    currentSessionId = id;
    localStorage.setItem(SESSION_KEY, id);
}

function showWelcome() {
    const name = CURRENT_USER.first_name || 'there';
    messagesEl.innerHTML = `
        <div class="welcome-hero">
            <h2>Hey, ${escapeHtml(name)}</h2>
            <p>Ask about leads, appointments, follow-ups, sales performance, or door knockers</p>
            <div class="welcome-suggestions">
                ${SUGGESTIONS.map((s) => `<button type="button" class="welcome-chip" data-prompt="${escapeHtml(s)}">${escapeHtml(s)}</button>`).join('')}
            </div>
        </div>`;

    messagesEl.querySelectorAll('.welcome-chip').forEach((btn) => {
        btn.addEventListener('click', () => {
            const prompt = btn.getAttribute('data-prompt') || '';
            input.value = prompt;
            input.focus();
        });
    });
}

function packArtifactsIntoContent(reply, artifacts) {
    if (!artifacts || ((!artifacts.charts || !artifacts.charts.length) && (!artifacts.reports || !artifacts.reports.length))) {
        return reply;
    }
    return `${reply}\n\n${ARTIFACT_MARKER}${JSON.stringify(artifacts)}-->`;
}

function unpackContent(content) {
    const idx = content.indexOf(ARTIFACT_MARKER);
    if (idx === -1) {
        return { text: content, artifacts: null };
    }
    const jsonStart = idx + ARTIFACT_MARKER.length;
    const jsonEnd = content.indexOf('-->', jsonStart);
    if (jsonEnd === -1) {
        return { text: content, artifacts: null };
    }
    let artifacts = null;
    try {
        artifacts = JSON.parse(content.slice(jsonStart, jsonEnd));
    } catch {
        artifacts = null;
    }
    return {
        text: content.slice(0, idx).trimEnd(),
        artifacts,
    };
}

function renderMarkdown(text) {
    const raw = marked.parse(text || '', { breaks: true });
    const clean = DOMPurify.sanitize(raw, {
        ADD_ATTR: ['target', 'rel'],
        FORBID_TAGS: ['img'],
    });
    const wrap = document.createElement('div');
    wrap.innerHTML = clean;
    wrap.querySelectorAll('a[href]').forEach((a) => {
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
        a.classList.add('crm-link');
    });
    return wrap.innerHTML;
}

function scrollToBottom() {
    if (messagesScrollEl) {
        messagesScrollEl.scrollTop = messagesScrollEl.scrollHeight;
    }
}

function openReport(report) {
    const id = report.id || ('report_' + crypto.randomUUID());
    const payload = { ...report, id };
    localStorage.setItem('ecotech_report_' + id, JSON.stringify(payload));
    try {
        const keys = Object.keys(localStorage).filter((k) => k.startsWith('ecotech_report_'));
        if (keys.length > 10) {
            keys.sort();
            keys.slice(0, keys.length - 10).forEach((k) => localStorage.removeItem(k));
        }
    } catch {
    }
    window.open('/report.html?id=' + encodeURIComponent(id), '_blank', 'noopener,noreferrer');
}

function mountCharts(container, charts) {
    if (!charts?.length || typeof Chart === 'undefined') return;
    charts.forEach((chart) => {
        const wrap = document.createElement('div');
        wrap.className = 'chart-card';
        if (chart.title) {
            const h = document.createElement('div');
            h.className = 'chart-title';
            h.textContent = chart.title;
            wrap.appendChild(h);
        }
        const canvas = document.createElement('canvas');
        wrap.appendChild(canvas);
        container.appendChild(wrap);

        const datasets = (chart.datasets || []).map((ds, i) => ({
            label: ds.label || '',
            data: ds.data || [],
            backgroundColor: chart.type === 'line'
                ? CHART_COLORS[i % CHART_COLORS.length]
                : (ds.data || []).map((_, j) => CHART_COLORS[j % CHART_COLORS.length]),
            borderColor: CHART_COLORS[i % CHART_COLORS.length],
            borderWidth: chart.type === 'line' ? 2 : 1,
            fill: chart.type === 'line' ? false : undefined,
            tension: chart.type === 'line' ? 0.25 : undefined,
        }));

        new Chart(canvas, {
            type: chart.type || 'bar',
            data: {
                labels: chart.labels || [],
                datasets,
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: chart.type === 'pie' || (chart.datasets || []).length > 1 },
                },
            },
        });
    });
}

function mountReports(container, reports) {
    if (!reports?.length) return;
    reports.forEach((report) => {
        const bar = document.createElement('div');
        bar.className = 'report-actions';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-accent';
        btn.textContent = `Open report: ${report.title || 'PDF'}`;
        btn.addEventListener('click', () => openReport(report));
        bar.appendChild(btn);
        container.appendChild(bar);
    });
}

function appendMessage(role, content, meta = '') {
    const div = document.createElement('div');
    div.className = `message ${role}`;

    if (role === 'assistant') {
        const { text, artifacts } = unpackContent(content);
        const body = document.createElement('div');
        body.className = 'body';
        body.innerHTML = renderMarkdown(text);
        if (artifacts?.reports?.length) {
            body.querySelectorAll('a.crm-link').forEach((a) => {
                const label = (a.textContent || '').toLowerCase();
                if (label.includes('open') && label.includes('report')) {
                    const span = document.createElement('span');
                    span.textContent = a.textContent || '';
                    a.replaceWith(span);
                }
            });
        }
        div.innerHTML = `<div class="meta">${meta || 'Assistant'}</div>`;
        div.appendChild(body);
        if (artifacts) {
            mountCharts(body, artifacts.charts || []);
            mountReports(body, artifacts.reports || []);
        }
    } else {
        div.innerHTML = `
            <div class="meta">${meta || 'You'}</div>
            <div class="body">${escapeHtml(content)}</div>`;
    }

    messagesEl.appendChild(div);
    scrollToBottom();
    return div;
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function setLoading(on) {
    loadingEl.classList.toggle('hidden', !on);
    sendBtn.disabled = on;
    input.disabled = on;
}

async function ensureSession() {
    if (!currentSessionId) {
        currentSessionId = generateSessionId();
        setSessionId(currentSessionId);
    }

    const sessionRef = doc(db, COLLECTIONS.sessions, currentSessionId);
    const existing = await getDoc(sessionRef);

    if (!existing.exists()) {
        await setDoc(sessionRef, {
            id: currentSessionId,
            browserId,
            title: null,
            titleGenerated: false,
            updatedAt: serverTimestamp(),
            createdAt: serverTimestamp(),
        });
    }
}

async function setSessionTitle(title) {
    if (!currentSessionId || !title) return;
    const sessionRef = doc(db, COLLECTIONS.sessions, currentSessionId);
    const existing = await getDoc(sessionRef);
    if (existing.exists() && existing.data().titleGenerated) {
        return;
    }

    await setDoc(sessionRef, {
        title,
        titleGenerated: true,
        updatedAt: serverTimestamp(),
    }, { merge: true });
    loadSessionList();
}

async function saveMessage(role, content) {
    if (!currentSessionId) return;

    await addDoc(collection(db, COLLECTIONS.messages), {
        sessionId: currentSessionId,
        browserId,
        role,
        content,
        createdAt: serverTimestamp(),
    });

    if (role === 'user' || role === 'assistant') {
        await updateDoc(doc(db, COLLECTIONS.sessions, currentSessionId), {
            updatedAt: serverTimestamp(),
        });
    }
}

function subscribeToSession(sessionId) {
    if (unsubscribeMessages) {
        unsubscribeMessages();
    }

    messagesEl.innerHTML = '';
    localHistory = [];

    const q = query(
        collection(db, COLLECTIONS.messages),
        where('sessionId', '==', sessionId),
        orderBy('createdAt', 'asc'),
    );

    unsubscribeMessages = onSnapshot(q, (snap) => {
        if (snap.empty) {
            showWelcome();
            return;
        }

        messagesEl.innerHTML = '';
        localHistory = [];

        snap.forEach((docSnap) => {
            const data = docSnap.data();
            const role = data.role;
            const content = data.content || '';
            const ts = data.createdAt?.toDate?.();
            const meta = ts ? ts.toLocaleString() : (role === 'user' ? 'You' : 'Assistant');
            appendMessage(role, content, meta);
            if (role === 'user' || role === 'assistant') {
                const { text } = role === 'assistant' ? unpackContent(content) : { text: content };
                localHistory.push({ role, content: text });
            }
        });
        scrollToBottom();
        requestAnimationFrame(scrollToBottom);
    }, (err) => {
        console.error('Firestore listener error', err);
        showWelcome();
    });
}

async function deleteSession(sessionId) {
    try {
        const messagesQ = query(
            collection(db, COLLECTIONS.messages),
            where('sessionId', '==', sessionId),
        );
        const snap = await getDocs(messagesQ);

        const refs = snap.docs.map((d) => d.ref);
        const sessionRef = doc(db, COLLECTIONS.sessions, sessionId);
        refs.push(sessionRef);

        if (refs.length > 0) {
            const chunkSize = 450;
            for (let i = 0; i < refs.length; i += chunkSize) {
                const batch = writeBatch(db);
                refs.slice(i, i + chunkSize).forEach((ref) => batch.delete(ref));
                await batch.commit();
            }
        }
    } catch (err) {
        console.error('Delete failed', err);
        throw err;
    }

    const wasCurrent = sessionId === currentSessionId;

    if (unsubscribeMessages) {
        unsubscribeMessages();
        unsubscribeMessages = null;
    }

    await loadSessionList();

    if (!wasCurrent) {
        return;
    }

    const remaining = [...sessionListEl.querySelectorAll('.session-item')];
    if (remaining.length > 0) {
        const nextId = remaining[0].querySelector('.session-select')?.dataset.sessionId;
        if (nextId) {
            setSessionId(nextId);
            subscribeToSession(nextId);
            loadSessionList();
            return;
        }
    }

    currentSessionId = null;
    localStorage.removeItem(SESSION_KEY);
    localHistory = [];
    messagesEl.innerHTML = '';
    showWelcome();
}

async function loadSessionList() {
    try {
        const q = query(
            collection(db, COLLECTIONS.sessions),
            where('browserId', '==', browserId),
        );

        const snap = await getDocs(q);
        const sessions = snap.docs
            .map((docSnap) => ({ id: docSnap.id, ...docSnap.data() }))
            .sort((a, b) => {
                const ta = a.updatedAt?.toDate?.()?.getTime() ?? 0;
                const tb = b.updatedAt?.toDate?.()?.getTime() ?? 0;
                return tb - ta;
            });

        sessionListEl.innerHTML = '';

        sessions.forEach((data) => {
            const li = document.createElement('li');
            li.className = 'session-item' + (data.id === currentSessionId ? ' active' : '');

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'session-select';
            btn.dataset.sessionId = data.id;

            const title = document.createElement('span');
            title.className = 'session-title';
            title.textContent = data.title || 'New chat';

            const date = document.createElement('span');
            date.className = 'session-date';
            const d = data.updatedAt?.toDate?.();
            date.textContent = d ? d.toLocaleDateString() : '';

            btn.appendChild(title);
            btn.appendChild(date);
            btn.addEventListener('click', () => {
                setSessionId(data.id);
                subscribeToSession(data.id);
                loadSessionList();
            });

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'session-delete';
            deleteBtn.setAttribute('aria-label', 'Delete conversation');
            deleteBtn.textContent = '×';
            deleteBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                deleteBtn.disabled = true;
                try {
                    await deleteSession(data.id);
                } catch (err) {
                    console.error('Delete failed', err);
                    deleteBtn.disabled = false;
                }
            });

            li.appendChild(btn);
            li.appendChild(deleteBtn);
            sessionListEl.appendChild(li);
        });
    } catch (err) {
        console.warn('Could not load sessions:', err);
    }
}

async function sendMessage(text) {
    const isFirstMessage = !localHistory.some((m) => m.role === 'user');

    await ensureSession();
    await saveMessage('user', text);
    setLoading(true);

    try {
        const historyForApi = localHistory
            .slice(-20)
            .filter((m) => m.role === 'user' || m.role === 'assistant');

        const res = await fetch('api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                message: text,
                history: historyForApi,
                sessionId: currentSessionId,
                isFirstMessage,
            }),
        });

        const raw = await res.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch {
            throw new Error(
                res.ok
                    ? 'Server returned an invalid response. Try narrowing the date range.'
                    : `Request failed (${res.status}). Try again with a smaller date range.`,
            );
        }

        if (!res.ok) {
            throw new Error(data.error || 'Request failed');
        }

        if (data.sessionTitle) {
            await setSessionTitle(data.sessionTitle);
        } else if (isFirstMessage) {
            await setSessionTitle(text.length > 60 ? text.slice(0, 57) + '…' : text);
        }

        const packed = packArtifactsIntoContent(data.reply, data.artifacts);
        await saveMessage('assistant', packed);

        if (data.artifacts?.reports?.length) {
            openReport(data.artifacts.reports[data.artifacts.reports.length - 1]);
        }
    } catch (err) {
        const msg = `Sorry, something went wrong: ${err.message}`;
        await saveMessage('assistant', msg);
    } finally {
        setLoading(false);
        loadSessionList();
    }
}

function endpointsByGroup() {
    /** @type {Record<string, typeof ENDPOINT_CATALOG>} */
    const groups = {};
    for (const ep of ENDPOINT_CATALOG) {
        if (!groups[ep.group]) groups[ep.group] = [];
        groups[ep.group].push(ep);
    }
    return groups;
}

function renderEndpointGroups(container, allowedIds, { editable = false } = {}) {
    const allowed = new Set(allowedIds);
    const groups = endpointsByGroup();
    container.innerHTML = '';

    for (const [group, eps] of Object.entries(groups)) {
        const section = document.createElement('div');
        section.className = 'endpoint-group';
        section.innerHTML = `<h4>${escapeHtml(group)}</h4>`;

        for (const ep of eps) {
            const on = allowed.has(ep.id);
            const row = document.createElement('div');
            row.className = 'endpoint-row' + (editable ? '' : ' readonly');

            if (editable) {
                const id = `ep_${ep.id}`;
                row.innerHTML = `
                    <input type="checkbox" id="${id}" data-endpoint="${escapeHtml(ep.id)}" ${on ? 'checked' : ''}>
                    <label for="${id}">
                        <span class="ep-name">${escapeHtml(ep.label)}</span>
                        <span class="ep-desc">${escapeHtml(ep.description)}</span>
                    </label>`;
                row.querySelector('input')?.addEventListener('change', (e) => {
                    const target = /** @type {HTMLInputElement} */ (e.target);
                    if (!draftEndpoints) return;
                    if (target.checked) draftEndpoints.add(ep.id);
                    else draftEndpoints.delete(ep.id);
                    markAclDirty(true);
                });
            } else {
                row.innerHTML = `
                    <label>
                        <span class="ep-name">${escapeHtml(ep.label)}</span>
                        <span class="ep-desc">${escapeHtml(ep.description)}</span>
                    </label>
                    <span class="ep-badge ${on ? '' : 'off'}">${on ? 'Enabled' : 'No access'}</span>`;
            }

            section.appendChild(row);
        }

        container.appendChild(section);
    }
}

function markAclDirty(dirty) {
    aclDirty = dirty;
    const hint = document.getElementById('acl-dirty-hint');
    const saveBtn = document.getElementById('save-acl-btn');
    if (hint) hint.hidden = !dirty;
    if (saveBtn) saveBtn.disabled = !dirty;
}

function markProfileDirty(dirty) {
    profileDirty = dirty;
    const hint = document.getElementById('profile-dirty-hint');
    const saveBtn = document.getElementById('save-profile-btn');
    if (hint) hint.hidden = !dirty;
    if (saveBtn) saveBtn.disabled = !dirty;
}

function refreshIdentityUi() {
    headerUserEl.textContent = displayName(CURRENT_USER);
    const myAccessName = document.getElementById('my-access-name');
    if (myAccessName) myAccessName.textContent = displayName(CURRENT_USER);

    const welcome = messagesEl.querySelector('.welcome-hero h2');
    if (welcome) {
        welcome.textContent = `Hey, ${CURRENT_USER.first_name || 'there'}`;
    }
}

function fillMyProfileFields() {
    const first = document.getElementById('my-first-name');
    const last = document.getElementById('my-last-name');
    if (first) first.value = CURRENT_USER.first_name || '';
    if (last) last.value = CURRENT_USER.last_name || '';
    markProfileDirty(false);
}

function fillManageProfileFields(user) {
    const wrap = document.getElementById('manage-profile');
    const first = document.getElementById('manage-first-name');
    const last = document.getElementById('manage-last-name');
    if (!wrap || !first || !last) return;
    wrap.hidden = false;
    first.value = user.first_name || '';
    last.value = user.last_name || '';
}

function openSettings() {
    acl = loadAcl();
    draftEndpoints = null;
    markAclDirty(false);
    fillMyProfileFields();

    const search = /** @type {HTMLInputElement|null} */ (document.getElementById('user-search'));
    if (search) search.value = '';

    document.getElementById('my-access-name').textContent = displayName(CURRENT_USER);
    const myAccess = acl[CURRENT_USER.id] || ALL_ENDPOINT_IDS;
    renderEndpointGroups(document.getElementById('my-access-list'), myAccess, { editable: false });

    const manageTab = document.getElementById('manage-users-tab');
    if (isSuperAdmin()) {
        manageTab.hidden = false;
        selectedManageUserId = DUMMY_USERS.find((u) => u.id !== CURRENT_USER.id)?.id || DUMMY_USERS[0].id;
        renderUserList();
        const user = DUMMY_USERS.find((u) => u.id === selectedManageUserId);
        if (user) {
            draftEndpoints = new Set(acl[user.id] || ALL_ENDPOINT_IDS);
            document.getElementById('selected-user-label').textContent = displayName(user);
            document.getElementById('endpoint-bulk').hidden = false;
            document.getElementById('acl-footer').hidden = false;
            fillManageProfileFields(user);
            renderEndpointGroups(document.getElementById('user-endpoint-list'), [...draftEndpoints], { editable: true });
        }
    } else {
        manageTab.hidden = true;
    }

    switchSettingsTab('my-access');
    settingsModal.hidden = false;
    document.body.style.overflow = 'hidden';

    fetchCrmUsers()
        .then(() => {
            headerUserEl.textContent = displayName(CURRENT_USER);
            document.getElementById('my-access-name').textContent = displayName(CURRENT_USER);
            fillMyProfileFields();
            if (isSuperAdmin()) {
                document.getElementById('manage-users-tab').hidden = false;
                if (!selectedManageUserId || !DUMMY_USERS.find((u) => u.id === selectedManageUserId)) {
                    selectedManageUserId = DUMMY_USERS.find((u) => u.id !== CURRENT_USER.id)?.id || DUMMY_USERS[0]?.id;
                }
                renderUserList();
                const user = DUMMY_USERS.find((u) => u.id === selectedManageUserId);
                if (user) {
                    draftEndpoints = new Set(acl[user.id] || ALL_ENDPOINT_IDS);
                    document.getElementById('selected-user-label').textContent = displayName(user);
                    document.getElementById('endpoint-bulk').hidden = false;
                    document.getElementById('acl-footer').hidden = false;
                    fillManageProfileFields(user);
                    renderEndpointGroups(document.getElementById('user-endpoint-list'), [...draftEndpoints], { editable: true });
                }
            }
            refreshIdentityUi();
        })
        .catch((err) => {
            console.warn('CRM users unavailable, using local list', err);
        });
}

function closeSettings() {
    if ((aclDirty || profileDirty) && !confirm('Discard unsaved changes?')) {
        return;
    }
    settingsModal.hidden = true;
    document.body.style.overflow = '';
    draftEndpoints = null;
    markAclDirty(false);
    markProfileDirty(false);
}

function switchSettingsTab(tabId) {
    document.querySelectorAll('.settings-tab').forEach((btn) => {
        const active = btn.dataset.tab === tabId;
        btn.classList.toggle('active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.getElementById('tab-my-access').hidden = tabId !== 'my-access';
    document.getElementById('tab-manage-users').hidden = tabId !== 'manage-users';
}

function getUserSearchQuery() {
    const search = /** @type {HTMLInputElement|null} */ (document.getElementById('user-search'));
    return search?.value || '';
}

function renderUserList(filterText = getUserSearchQuery()) {
    const list = document.getElementById('user-list');
    list.innerHTML = '';

    const q = filterText.trim().toLowerCase();
    const users = DUMMY_USERS.filter((user) => {
        if (!q) return true;
        const hay = [
            user.first_name,
            user.last_name,
            displayName(user),
            user.email || '',
            ...(user.role_names || []),
            user.id,
        ].join(' ').toLowerCase();
        return hay.includes(q);
    });

    if (users.length === 0) {
        const empty = document.createElement('li');
        empty.className = 'user-list-empty';
        empty.textContent = q ? 'No users match that search.' : 'No users found.';
        list.appendChild(empty);
        return;
    }

    users.forEach((user) => {
        const li = document.createElement('li');
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'user-list-item' + (user.id === selectedManageUserId ? ' active' : '');
        btn.innerHTML = `
            <span class="u-name">${escapeHtml(displayName(user))}</span>
            <span class="u-meta">${escapeHtml(
                (user.role_names && user.role_names.length)
                    ? user.role_names.join(', ')
                    : (user.role === 'super_admin' ? 'Super admin' : 'User')
            )}${user.email ? ` · ${escapeHtml(user.email)}` : ''}</span>`;
        btn.addEventListener('click', () => {
            if (aclDirty && !confirm('Discard unsaved changes for this user?')) return;
            selectManageUser(user.id);
        });
        li.appendChild(btn);
        list.appendChild(li);
    });
}

function selectManageUser(userId) {
    selectedManageUserId = userId;
    const user = DUMMY_USERS.find((u) => u.id === userId);
    if (!user) return;

    draftEndpoints = new Set(acl[userId] || ALL_ENDPOINT_IDS);
    markAclDirty(false);

    document.getElementById('selected-user-label').textContent = displayName(user);
    document.getElementById('endpoint-bulk').hidden = false;
    document.getElementById('acl-footer').hidden = false;
    fillManageProfileFields(user);

    renderEndpointGroups(document.getElementById('user-endpoint-list'), [...draftEndpoints], { editable: true });
    const search = /** @type {HTMLInputElement|null} */ (document.getElementById('user-search'));
    renderUserList(search?.value || '');
    switchSettingsTab('manage-users');
}

function saveMyProfile() {
    const first = /** @type {HTMLInputElement} */ (document.getElementById('my-first-name'));
    const last = /** @type {HTMLInputElement} */ (document.getElementById('my-last-name'));
    const firstName = (first?.value || '').trim();
    if (!firstName) {
        first?.focus();
        return;
    }

    updateUserName(CURRENT_USER.id, {
        first_name: firstName,
        last_name: (last?.value || '').trim(),
    });
    markProfileDirty(false);
    refreshIdentityUi();
    fillMyProfileFields();
    renderUserList();
}

function saveSelectedUserAcl() {
    if (!selectedManageUserId || !draftEndpoints) return;

    const first = /** @type {HTMLInputElement} */ (document.getElementById('manage-first-name'));
    const last = /** @type {HTMLInputElement} */ (document.getElementById('manage-last-name'));
    const firstName = (first?.value || '').trim();
    if (!firstName) {
        first?.focus();
        return;
    }

    updateUserName(selectedManageUserId, {
        first_name: firstName,
        last_name: (last?.value || '').trim(),
    });

    acl = { ...acl, [selectedManageUserId]: [...draftEndpoints] };
    saveAcl(acl);
    markAclDirty(false);

    const user = DUMMY_USERS.find((u) => u.id === selectedManageUserId);
    if (user) {
        document.getElementById('selected-user-label').textContent = displayName(user);
    }

    if (selectedManageUserId === CURRENT_USER.id) {
        fillMyProfileFields();
        refreshIdentityUi();
        renderEndpointGroups(document.getElementById('my-access-list'), acl[CURRENT_USER.id], { editable: false });
    }

    renderUserList();
}

function setupSettingsUi() {
    headerUserEl.hidden = false;
    headerUserEl.textContent = displayName(CURRENT_USER);

    settingsBtn.addEventListener('click', openSettings);
    settingsModal.querySelectorAll('[data-close-settings]').forEach((el) => {
        el.addEventListener('click', closeSettings);
    });

    document.querySelectorAll('.settings-tab').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (btn.hidden) return;
            switchSettingsTab(btn.dataset.tab);
        });
    });

    document.getElementById('my-first-name')?.addEventListener('input', () => markProfileDirty(true));
    document.getElementById('my-last-name')?.addEventListener('input', () => markProfileDirty(true));
    document.getElementById('save-profile-btn')?.addEventListener('click', saveMyProfile);

    document.getElementById('manage-first-name')?.addEventListener('input', () => markAclDirty(true));
    document.getElementById('manage-last-name')?.addEventListener('input', () => markAclDirty(true));

    document.getElementById('user-search')?.addEventListener('input', (e) => {
        const target = /** @type {HTMLInputElement} */ (e.target);
        renderUserList(target.value);
    });

    document.getElementById('select-all-endpoints')?.addEventListener('click', () => {
        if (!draftEndpoints) return;
        draftEndpoints = new Set(ALL_ENDPOINT_IDS);
        renderEndpointGroups(document.getElementById('user-endpoint-list'), [...draftEndpoints], { editable: true });
        markAclDirty(true);
    });

    document.getElementById('clear-all-endpoints')?.addEventListener('click', () => {
        if (!draftEndpoints) return;
        draftEndpoints = new Set();
        renderEndpointGroups(document.getElementById('user-endpoint-list'), [], { editable: true });
        markAclDirty(true);
    });

    document.getElementById('save-acl-btn')?.addEventListener('click', saveSelectedUserAcl);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !settingsModal.hidden) {
            closeSettings();
        }
    });
}

async function initChat() {
    setupSettingsUi();

    try {
        await fetchCrmUsers();
        headerUserEl.textContent = displayName(CURRENT_USER);
        refreshIdentityUi();
    } catch (err) {
        console.warn('CRM users unavailable', err);
    }

    if (currentSessionId) {
        const sessionRef = doc(db, COLLECTIONS.sessions, currentSessionId);
        const existing = await getDoc(sessionRef);
        if (existing.exists()) {
            subscribeToSession(currentSessionId);
        } else {
            currentSessionId = null;
            localStorage.removeItem(SESSION_KEY);
            showWelcome();
        }
    } else {
        showWelcome();
    }
    loadSessionList();
}

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    await sendMessage(text);
});

newChatBtn.addEventListener('click', async () => {
    if (unsubscribeMessages) {
        unsubscribeMessages();
        unsubscribeMessages = null;
    }
    currentSessionId = generateSessionId();
    setSessionId(currentSessionId);
    localHistory = [];
    messagesEl.innerHTML = '';
    showWelcome();
    subscribeToSession(currentSessionId);
    loadSessionList();
});

input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        form.requestSubmit();
    }
});

initChat();
