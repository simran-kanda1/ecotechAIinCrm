const STORAGE_PREFIX = 'ecotech_report_';

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
}

function renderSection(section) {
    const heading = section.heading
        ? `<h2>${escapeHtml(section.heading)}</h2>`
        : '';

    switch (section.type) {
        case 'table': {
            const cols = Array.isArray(section.columns) ? section.columns : [];
            const rows = Array.isArray(section.rows) ? section.rows : [];
            const thead = cols.length
                ? `<thead><tr>${cols.map((c) => `<th>${escapeHtml(c)}</th>`).join('')}</tr></thead>`
                : '';
            const tbody = rows.map((row) => {
                const cells = Array.isArray(row) ? row : [row];
                return `<tr>${cells.map((c) => `<td>${escapeHtml(c)}</td>`).join('')}</tr>`;
            }).join('');
            return `${heading}<table>${thead}<tbody>${tbody}</tbody></table>`;
        }
        case 'metrics': {
            const items = Array.isArray(section.items) ? section.items : [];
            const cards = items.map((item) => {
                const label = typeof item === 'object' ? (item.label ?? '') : '';
                const value = typeof item === 'object' ? (item.value ?? '') : item;
                return `<div class="metric"><div class="label">${escapeHtml(label)}</div><div class="value">${escapeHtml(value)}</div></div>`;
            }).join('');
            return `${heading}<div class="metrics">${cards}</div>`;
        }
        case 'list': {
            const items = Array.isArray(section.items) ? section.items : [];
            const lis = items.map((item) => {
                if (typeof item === 'object' && item !== null) {
                    return `<li><strong>${escapeHtml(item.label ?? '')}</strong>: ${escapeHtml(item.value ?? '')}</li>`;
                }
                return `<li>${escapeHtml(item)}</li>`;
            }).join('');
            return `${heading}<ul>${lis}</ul>`;
        }
        default:
            return `${heading}<p>${escapeHtml(section.content || '').replace(/\n/g, '<br>')}</p>`;
    }
}

function loadReport() {
    const params = new URLSearchParams(window.location.search);
    const id = params.get('id');
    if (!id) return null;

    try {
        const raw = localStorage.getItem(STORAGE_PREFIX + id);
        if (!raw) return null;
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function main() {
    const root = document.getElementById('report-root');
    const report = loadReport();

    if (!report || !report.title) {
        root.className = 'error';
        root.innerHTML = 'No report data found. Go back to the assistant and click <strong>Open report</strong> again.';
        return;
    }

    document.title = report.title;
    root.className = 'sheet';
    root.innerHTML = `
        <div class="sheet-accent"></div>
        <h1>${escapeHtml(report.title)}</h1>
        ${report.subtitle ? `<p class="subtitle">${escapeHtml(report.subtitle)}</p>` : ''}
        <p class="meta">Generated ${escapeHtml(report.generated_at || '')}</p>
        ${(report.sections || []).map(renderSection).join('')}
    `;

    document.getElementById('print-btn')?.addEventListener('click', () => window.print());
    document.getElementById('close-btn')?.addEventListener('click', () => window.close());
}

main();
