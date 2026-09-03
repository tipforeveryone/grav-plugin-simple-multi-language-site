/**
 * "Bản dịch: {language}" — Admin2 custom field for
 * simple-multi-language-site's per-page translation picker + "Add
 * Translation" quick-create button (ported from
 * admin/templates/forms/fields/simple-multi-language-site-translate/).
 *
 * Admin2's custom-field contract only hands a component its own `field`
 * (blueprint def) and `value` — no sibling field values, no page object
 * (confirmed against the admin2 bundle's field-mount code, which sets
 * exactly `.field` and `.value`). The blueprint's `target_code`/
 * `target_label` custom keys don't even survive that trip — the api
 * plugin's blueprint serializer only forwards a fixed whitelist of
 * standard field properties, so both are re-derived client-side instead
 * (see _renderPicker). So unlike
 * the classic-admin Twig version (which reads admin.page() directly), this
 * derives the page being edited from the URL itself — Admin2's page editor
 * route is `{basePath}/pages/edit{route}` (confirmed by hand in the
 * browser) — then fetches that page + the plugin's language config once on
 * mount. That means, unlike the classic field, it does NOT live-update if
 * the user changes the "Ngôn ngữ" select without saving — it reflects the
 * page as last saved, same as this field already did in a fresh page load.
 */

const TAG = window.__GRAV_FIELD_TAG;
const API_BASE = (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1');
// window.__GRAV_API_TOKEN is only a one-time snapshot from when admin2 first
// mounts this field component — it's never updated afterwards even though
// the host app keeps rotating the real access token in localStorage on
// every silent refresh, so a form left open across a token rotation would
// send a now-stale token and get a bare 401. currentAccessToken() re-reads
// the live token from the same localStorage key the host app itself writes
// to (see ftp-sync's admin-next/pages/ftp-sync.js for the fuller writeup —
// this plugin's own page component hit the same bug).
const API_TOKEN_FALLBACK = window.__GRAV_API_TOKEN;
const APP_BASE = window.__GRAV_CONFIG__?.basePath || '/admin2';

function currentAccessToken() {
    try {
        const keys = ['grav_admin_auth::/admin2', 'grav_admin_auth'];
        for (const key of keys) {
            const raw = localStorage.getItem(key);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed.accessToken === 'string' && parsed.accessToken) {
                    return parsed.accessToken;
                }
            }
        }
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && key.indexOf('grav_admin_auth') === 0) {
                const raw = localStorage.getItem(key);
                const parsed = raw ? JSON.parse(raw) : null;
                if (parsed && typeof parsed.accessToken === 'string' && parsed.accessToken) {
                    return parsed.accessToken;
                }
            }
        }
    } catch (e) {
        // localStorage unavailable -> fall back to the load-time snapshot.
    }
    return API_TOKEN_FALLBACK;
}

function slugify(str) {
    return String(str)
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/đ/g, 'd')
        .replace(/Đ/g, 'D')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
}

function computeTargetParent(sourceRoute, sourceCode, targetCode, languages) {
    const sourceLang = languages.find((l) => l.code === sourceCode);
    const targetLang = languages.find((l) => l.code === targetCode);
    if (!sourceLang || !targetLang) return null;

    const root = sourceLang.root_path;
    let relative;
    if (sourceRoute === root) relative = '';
    else if (sourceRoute.indexOf(root + '/') === 0) relative = sourceRoute.slice(root.length);
    else relative = sourceRoute;

    const segments = relative.replace(/^\/+|\/+$/g, '').split('/').filter(Boolean);
    segments.pop();

    let parent = targetLang.root_path;
    if (segments.length) parent += '/' + segments.join('/');
    return parent;
}

/** Extracts the page route being edited from the current URL: {basePath}/pages/edit/{route...} */
function currentPageRouteFromLocation() {
    const prefix = APP_BASE + '/pages/edit/';
    const path = window.location.pathname;
    if (path.indexOf(prefix) !== 0) return null;
    return '/' + path.slice(prefix.length).replace(/^\/+/, '');
}

class SmlsTranslateField extends HTMLElement {
    constructor() {
        super();
        this._field = null;
        this._value = '';
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }

    set value(v) { this._value = v ?? ''; }
    get value() { return this._value; }

    connectedCallback() {
        this._renderMessage('Đang tải…');
        this._load();
    }

    async _fetch(path, options = {}) {
        const token = currentAccessToken();
        const res = await fetch(API_BASE + path, {
            ...options,
            headers: {
                'Content-Type': 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
                ...(options.headers || {}),
            },
        });
        const body = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(body?.error?.message || body?.message || `Request failed (${res.status})`);
        }
        return body.data ?? body;
    }

    async _load() {
        const sourceRoute = currentPageRouteFromLocation();
        if (!sourceRoute) {
            this._renderMessage('Không xác định được trang đang sửa.');
            return;
        }

        try {
            const [page, config] = await Promise.all([
                this._fetch(`/pages${sourceRoute}`),
                this._fetch('/config/plugins/simple-multi-language-site'),
            ]);

            const currentCode = String(page.header?.smls_language || '').trim();
            if (currentCode === '') {
                this._renderMessage('Lưu trang và xác định Ngôn ngữ trước, rồi quay lại đây để thêm bản dịch.');
                return;
            }

            const languages = Array.isArray(config.languages) ? config.languages : [];
            const rows = await this._fetch(`/pages?template=${encodeURIComponent(page.template)}`);
            const candidates = (Array.isArray(rows) ? rows : rows.data || [])
                .filter((p) => p.route !== sourceRoute)
                .map((p) => ({ route: p.route, title: p.title }))
                .sort((a, b) => a.title.localeCompare(b.title));

            this._renderPicker({ page, sourceRoute, currentCode, languages, candidates });
        } catch (err) {
            this._renderMessage(err.message || 'Load failed', true);
        }
    }

    _renderMessage(text, isError) {
        this.innerHTML = `${this._styles()}<em class="smls-pending${isError ? ' smls-error' : ''}">${this._escape(text)}</em>`;
    }

    _renderPicker({ page, sourceRoute, currentCode, languages, candidates }) {
        // target_code/target_label are set on the blueprint field in PHP
        // (onBlueprintCreated) but the api plugin's blueprint serializer only
        // forwards a fixed whitelist of standard field properties to the SPA
        // and silently drops custom ones — so they never reach here. Derive
        // both from data that IS preserved: the field's own dotted `name`
        // (header.smls_translations.{code}) for the code, and the language
        // config (already fetched) for the label.
        const targetCode = String(this._field?.name || '').split('.').pop();
        const targetLabel = languages.find((l) => l.code === targetCode)?.label || targetCode;

        const options = ['<option value="">Chưa có bản dịch</option>']
            .concat(candidates.map((c) => `<option value="${this._escape(c.route)}" ${c.route === this._value ? 'selected' : ''}>${this._escape(c.title)} (${this._escape(c.route)})</option>`))
            .join('');

        let addButtonHtml = '';
        if (targetCode !== currentCode && !this._value) {
            const targetParent = computeTargetParent(sourceRoute, currentCode, targetCode, languages);
            if (targetParent !== null) {
                addButtonHtml = `<button type="button" class="smls-add-btn"><i class="fa fa-plus"></i> Add Translation</button>`;
            }
        }

        this.innerHTML = `
            ${this._styles()}
            <div class="smls-row">
                <select class="smls-select">${options}</select>
                ${addButtonHtml}
            </div>
            <div class="smls-status"></div>
        `;

        this.querySelector('.smls-select')?.addEventListener('change', (e) => {
            this._value = e.target.value;
            this.dispatchEvent(new CustomEvent('change', { detail: this._value, bubbles: true }));
        });

        this.querySelector('.smls-add-btn')?.addEventListener('click', () => {
            this._addTranslation({ page, sourceRoute, currentCode, targetCode, targetLabel, languages });
        });
    }

    async _addTranslation({ page, sourceRoute, currentCode, targetCode, targetLabel, languages }) {
        const btn = this.querySelector('.smls-add-btn');
        const statusEl = this.querySelector('.smls-status');
        const targetParent = computeTargetParent(sourceRoute, currentCode, targetCode, languages);
        const sourceSlug = sourceRoute.split('/').filter(Boolean).pop() || slugify(page.title);
        const newRoute = (targetParent === '/' ? '' : targetParent) + '/' + sourceSlug;

        btn.disabled = true;
        statusEl.textContent = 'Đang tạo…';

        try {
            const created = await this._fetch('/pages', {
                method: 'POST',
                body: JSON.stringify({
                    route: newRoute,
                    title: page.title,
                    template: page.template,
                    header: {
                        smls_language: targetCode,
                        smls_translations: { [currentCode]: sourceRoute },
                    },
                }),
            });
            this._value = created.route;
            statusEl.innerHTML = `Đã tạo bản dịch (${this._escape(targetLabel)}) tại <a href="${this._escape(APP_BASE)}/pages/edit${this._escape(created.route)}">${this._escape(created.route)}</a>`;
            this.dispatchEvent(new CustomEvent('change', { detail: this._value, bubbles: true }));
            btn.remove();
        } catch (err) {
            statusEl.textContent = 'Lỗi: ' + (err.message || 'Tạo bản dịch thất bại');
            btn.disabled = false;
        }
    }

    _escape(str) {
        const div = document.createElement('div');
        div.textContent = String(str ?? '');
        return div.innerHTML;
    }

    _styles() {
        return `
            <style>
                .smls-row { display: flex; align-items: center; gap: 8px; flex-wrap: nowrap; }
                .smls-select { flex: 1 1 auto; min-width: 0; border: 1px solid var(--border, #e5e7eb); border-radius: 4px; padding: 4px 8px; font-size: 13px; background: var(--card, #fff); color: var(--foreground, #1f2937); }
                .smls-add-btn { flex: 0 0 auto; white-space: nowrap; border: 1px solid var(--primary, #3b82f6); background: var(--card, #fff); color: var(--primary, #3b82f6); border-radius: 4px; padding: 4px 10px; font-size: 12.5px; cursor: pointer; }
                .smls-add-btn:hover:not(:disabled) { background: var(--accent, #f3f4f6); }
                .smls-add-btn:disabled { opacity: 0.6; cursor: default; }
                .smls-status { font-size: 12px; color: var(--muted-foreground, #6b7280); margin-top: 4px; }
                .smls-pending { color: var(--muted-foreground, #6b7280); font-style: italic; font-size: 13px; }
                .smls-error { color: var(--destructive, #dc2626); }
            </style>
        `;
    }
}

customElements.define(TAG, SmlsTranslateField);
