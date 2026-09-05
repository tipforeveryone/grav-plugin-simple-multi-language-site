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
 * (see _renderCombo). So unlike
 * the classic-admin Twig version (which reads admin.page() directly), this
 * derives the page being edited from the URL itself — Admin2's page editor
 * route is `{basePath}/pages/edit{route}` (confirmed by hand in the
 * browser) — then fetches that page + the plugin's language config once on
 * mount.
 *
 * Once the page's saved language is known, this field also swaps its own
 * body for a plain "Không áp dụng" note when it's the row for that same
 * language (a page isn't a "translation" of itself) — see
 * _renderNotApplicable(). Earlier this tried to hide the entire row
 * (label included) by reaching up into admin2's compiled field-wrapper
 * DOM, which turned out unreliable — that wrapper structure is owned by
 * a vendored, auto-updated third-party bundle, not something this plugin
 * controls. Swapping the row's own *content* instead needs nothing outside
 * this element.
 *
 * This applicable/not-applicable state DOES react live to the user flipping
 * the "Ngôn ngữ" select without saving first — see
 * _bindLanguageSelectWatcher(). That's a best-effort addition on top of a
 * contract that doesn't officially support it (no sibling-field
 * notifications at all), so it's written to silently do nothing if its DOM
 * fingerprinting ever fails to find that select — worst case, this row
 * falls back to reflecting the page as last saved, same as before this
 * existed. What that live toggle does NOT do is change which translation
 * this row is linked to (`header.smls_translations.{targetCode}`) — that
 * stays anchored to the page's last-*saved* language, exactly like the
 * "Add Translation" flow already did, so an unsaved language flip can never
 * write a translation link under the wrong language.
 *
 * The picker itself is a type-ahead combobox (text input + suggestion
 * list) rather than a plain <select>: an upfront "list every page sharing
 * this template" fetch made both an unusably wide native dropdown (long
 * titles + full routes) AND, since it wasn't filtered by language at all,
 * let you pick a page in the WRONG language as a "translation". Search is
 * server-side (GET /pages?template=&search=, already supported by
 * PagesController — see indexViaFlex()) so nothing is fetched until the
 * user types; results are then filtered client-side to
 * header.smls_language === targetCode, since the API has no query filter
 * for arbitrary header fields.
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
const SEARCH_DEBOUNCE_MS = 280;

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
        this._selectedTitle = '';
        this._ctx = null;
        this._activeIndex = -1;
        this._searchSeq = 0;
        this._debounceTimer = null;
        this._liveCurrentCode = '';
        this._langSelect = null;
        this._onLangChange = null;
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }

    set value(v) { this._value = v ?? ''; }
    get value() { return this._value; }

    connectedCallback() {
        this._renderMessage('Đang tải…');
        this._load();
    }

    disconnectedCallback() {
        if (this._debounceTimer) clearTimeout(this._debounceTimer);
        if (this._langSelect && this._onLangChange) {
            this._langSelect.removeEventListener('change', this._onLangChange);
        }
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
            throw new Error(body?.detail || body?.error?.message || body?.message || `Request failed (${res.status})`);
        }
        return body.data ?? body;
    }

    async _load() {
        const sourceRoute = currentPageRouteFromLocation();
        if (!sourceRoute) {
            this._renderMessage('Không xác định được trang đang sửa.');
            return;
        }

        // target_code is set on the blueprint field in PHP (onBlueprintCreated)
        // but the api plugin's blueprint serializer only forwards a fixed
        // whitelist of standard field properties to the SPA and silently
        // drops custom ones — so it never reaches here. Derive it instead
        // from data that IS preserved: the field's own dotted `name`
        // (header.smls_translations.{code}).
        const targetCode = String(this._field?.name || '').split('.').pop();

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
            this._ctx = { page, sourceRoute, currentCode, targetCode, languages };
            this._liveCurrentCode = currentCode;

            // Best-effort: react live if the user flips the "Ngôn ngữ" select
            // without saving, so this row doesn't keep showing a stale
            // applicable/not-applicable state mid-edit. See _bindLanguageSelectWatcher.
            this._bindLanguageSelectWatcher(languages);

            this._renderForCurrentCode();
        } catch (err) {
            this._renderMessage(err.message || 'Load failed', true);
        }
    }

    _renderMessage(text, isError) {
        this.innerHTML = `${this._styles()}<em class="smls-pending${isError ? ' smls-error' : ''}">${this._escape(text)}</em>`;
    }

    _renderNotApplicable() {
        this.innerHTML = `${this._styles()}<span class="smls-na">Không áp dụng</span>`;
    }

    /**
     * A page's own language isn't a "translation" of itself, so this row
     * shows a plain note instead of the picker whenever the live-selected
     * language equals this row's own target language.
     */
    _renderForCurrentCode() {
        if (!this._ctx) return;
        if (this._liveCurrentCode === this._ctx.targetCode) {
            this._renderNotApplicable();
        } else {
            this._showApplicableView();
        }
    }

    async _showApplicableView() {
        // Pre-fill the box with the already-linked translation's title —
        // only once (a live language-select flip re-enters this method
        // without needing to re-fetch a title we already resolved).
        if (this._value && !this._selectedTitle) {
            try {
                const selected = await this._fetch(`/pages${this._value}`);
                this._selectedTitle = selected?.title || this._value;
            } catch {
                // Stale/deleted reference — drop it rather than show a
                // route with no known title.
                this._value = '';
                this._selectedTitle = '';
            }
        }
        this._renderCombo();
    }

    /**
     * Admin2's custom-field contract hands a component only its own `field`
     * + `value` — no sibling field values, no change notifications for
     * other fields (see the file header comment). There is no official way
     * for this field to know when the sibling "Ngôn ngữ" <select> changes.
     * As a best-effort, plugin-side-only enhancement (no core/admin2 files
     * touched — this only reads the DOM admin2 already rendered and adds an
     * event listener), fingerprint that <select> by its option *values*: it's
     * the one native select whose options are exactly this plugin's
     * configured language codes, which no unrelated field is likely to
     * duplicate. If that match ever fails (e.g. a future admin2 build
     * changes how it renders fields), this silently no-ops — the row simply
     * falls back to reflecting the page as last saved, same as before this
     * existed.
     */
    _bindLanguageSelectWatcher(languages) {
        const codes = languages.map((l) => l.code).slice().sort();
        if (codes.length < 2) return;

        const select = Array.from(document.querySelectorAll('select')).find((el) => {
            const values = Array.from(el.options).map((o) => o.value).sort();
            return values.length === codes.length && values.every((v, i) => v === codes[i]);
        });
        if (!select) return;

        this._langSelect = select;
        this._onLangChange = () => {
            const code = select.value;
            if (code === this._liveCurrentCode) return;
            this._liveCurrentCode = code;
            this._renderForCurrentCode();
        };
        select.addEventListener('change', this._onLangChange);
    }

    _renderCombo() {
        const { page, sourceRoute, currentCode, targetCode, languages } = this._ctx;
        const targetLabel = languages.find((l) => l.code === targetCode)?.label || targetCode;

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
                <div class="smls-combo-wrap">
                    <input type="text" class="smls-combo-input" autocomplete="off"
                        placeholder="Gõ tiêu đề để tìm trang…"
                        value="${this._escape(this._selectedTitle)}" />
                    ${this._value ? '<button type="button" class="smls-combo-clear" title="Bỏ chọn">&times;</button>' : ''}
                    <ul class="smls-combo-suggestions" hidden></ul>
                </div>
                ${addButtonHtml}
            </div>
            <div class="smls-status"></div>
        `;

        const input = this.querySelector('.smls-combo-input');
        const list = this.querySelector('.smls-combo-suggestions');

        input.addEventListener('input', () => this._onComboInput(input.value));
        input.addEventListener('keydown', (e) => this._onComboKeydown(e));
        input.addEventListener('blur', () => this._onComboBlur(input));
        input.addEventListener('focus', () => input.select());

        // A plain click on a suggestion would blur the input first (closing
        // the list before the click lands) — preventDefault on mousedown
        // keeps focus on the input so the click handler below still fires.
        list.addEventListener('mousedown', (e) => e.preventDefault());
        list.addEventListener('click', (e) => {
            const li = e.target.closest('[data-route]');
            if (!li) return;
            this._selectSuggestion(li.dataset.route, li.dataset.title);
        });

        this.querySelector('.smls-combo-clear')?.addEventListener('click', () => this._clearSelection());

        this.querySelector('.smls-add-btn')?.addEventListener('click', () => {
            this._addTranslation({ page, sourceRoute, currentCode, targetCode, targetLabel, languages });
        });
    }

    _onComboInput(query) {
        if (this._debounceTimer) clearTimeout(this._debounceTimer);
        const q = query.trim();
        if (q === '') {
            this._renderSuggestions([]);
            return;
        }
        this._debounceTimer = setTimeout(() => this._search(q), SEARCH_DEBOUNCE_MS);
    }

    async _search(query) {
        const seq = ++this._searchSeq;
        const { sourceRoute, targetCode, page } = this._ctx;

        try {
            const rows = await this._fetch(
                `/pages?template=${encodeURIComponent(page.template)}&search=${encodeURIComponent(query)}&per_page=20`
            );
            if (seq !== this._searchSeq) return; // a newer keystroke already superseded this request

            const results = (Array.isArray(rows) ? rows : rows.data || [])
                .filter((p) => p.route !== sourceRoute && String(p.header?.smls_language || '').trim() === targetCode)
                .map((p) => ({ route: p.route, title: p.title }));

            this._renderSuggestions(results);
        } catch (err) {
            if (seq !== this._searchSeq) return;
            this._renderSuggestions([], err.message || 'Tìm kiếm thất bại');
        }
    }

    _renderSuggestions(list, errorMessage) {
        const ul = this.querySelector('.smls-combo-suggestions');
        if (!ul) return;
        this._activeIndex = -1;

        if (errorMessage) {
            ul.innerHTML = `<li class="smls-combo-empty smls-error">${this._escape(errorMessage)}</li>`;
            ul.hidden = false;
            return;
        }

        if (list.length === 0) {
            ul.innerHTML = '';
            ul.hidden = true;
            return;
        }

        ul.innerHTML = list.map((c) => `
            <li data-route="${this._escape(c.route)}" data-title="${this._escape(c.title)}">
                ${this._escape(c.title)} <small>${this._escape(c.route)}</small>
            </li>
        `).join('');
        ul.hidden = false;
    }

    _onComboKeydown(e) {
        if (e.key === 'Escape') {
            this._closeSuggestions();
            return;
        }

        const ul = this.querySelector('.smls-combo-suggestions');
        const items = ul && !ul.hidden ? Array.from(ul.querySelectorAll('[data-route]')) : [];

        if (e.key === 'ArrowDown' && items.length) {
            e.preventDefault();
            this._activeIndex = Math.min(this._activeIndex + 1, items.length - 1);
            this._highlightActive(items);
        } else if (e.key === 'ArrowUp' && items.length) {
            e.preventDefault();
            this._activeIndex = Math.max(this._activeIndex - 1, 0);
            this._highlightActive(items);
        } else if (e.key === 'Enter') {
            // Always swallow Enter here — this input sits inside the page's
            // main edit <form>, and letting it fall through would submit
            // (save) the whole page instead of just picking a suggestion.
            e.preventDefault();
            if (items.length) {
                const active = items[this._activeIndex] ?? items[0];
                this._selectSuggestion(active.dataset.route, active.dataset.title);
            }
        }
    }

    _highlightActive(items) {
        items.forEach((li, i) => li.classList.toggle('is-active', i === this._activeIndex));
        items[this._activeIndex]?.scrollIntoView({ block: 'nearest' });
    }

    _closeSuggestions() {
        const ul = this.querySelector('.smls-combo-suggestions');
        if (ul) {
            ul.hidden = true;
            ul.innerHTML = '';
        }
    }

    _onComboBlur(input) {
        this._closeSuggestions();
        input.value = this._selectedTitle;
    }

    _selectSuggestion(route, title) {
        this._value = route;
        this._selectedTitle = title;
        this.dispatchEvent(new CustomEvent('change', { detail: this._value, bubbles: true }));
        this._renderCombo();
    }

    _clearSelection() {
        this._value = '';
        this._selectedTitle = '';
        this.dispatchEvent(new CustomEvent('change', { detail: this._value, bubbles: true }));
        this._renderCombo();
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
            this._selectedTitle = created.title || page.title;
            this.dispatchEvent(new CustomEvent('change', { detail: this._value, bubbles: true }));
            this._renderCombo();

            const status = this.querySelector('.smls-status');
            if (status) {
                status.innerHTML = `Đã tạo bản dịch (${this._escape(targetLabel)}) tại <a href="${this._escape(APP_BASE)}/pages/edit${this._escape(created.route)}">${this._escape(created.route)}</a>`;
            }
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
                .smls-row { display: flex; align-items: flex-start; gap: 8px; flex-wrap: nowrap; }
                .smls-combo-wrap { position: relative; flex: 1 1 auto; min-width: 0; }
                .smls-combo-input { width: 100%; box-sizing: border-box; border: 1px solid var(--border, #e5e7eb); border-radius: 4px; padding: 4px 28px 4px 8px; font-size: 13px; background: var(--card, #fff); color: var(--foreground, #1f2937); }
                .smls-combo-clear { position: absolute; right: 2px; top: 50%; transform: translateY(-50%); border: none; background: transparent; color: var(--muted-foreground, #6b7280); cursor: pointer; font-size: 16px; line-height: 1; padding: 4px 6px; }
                .smls-combo-clear:hover { color: var(--foreground, #1f2937); }
                .smls-combo-suggestions { position: absolute; z-index: 20; top: calc(100% + 2px); left: 0; right: 0; margin: 0; padding: 4px 0; list-style: none; max-height: 240px; overflow-y: auto; background: var(--card, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,.12); }
                .smls-combo-suggestions li { padding: 6px 10px; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 13px; }
                .smls-combo-suggestions li small { display: block; color: var(--muted-foreground, #6b7280); font-size: 11px; }
                .smls-combo-suggestions li:hover, .smls-combo-suggestions li.is-active { background: var(--accent, #f3f4f6); }
                .smls-combo-suggestions li.smls-combo-empty { cursor: default; color: var(--muted-foreground, #6b7280); font-style: italic; }
                .smls-add-btn { flex: 0 0 auto; white-space: nowrap; border: 1px solid var(--primary, #3b82f6); background: var(--card, #fff); color: var(--primary, #3b82f6); border-radius: 4px; padding: 4px 10px; font-size: 12.5px; cursor: pointer; }
                .smls-add-btn:hover:not(:disabled) { background: var(--accent, #f3f4f6); }
                .smls-add-btn:disabled { opacity: 0.6; cursor: default; }
                .smls-status { font-size: 12px; color: var(--muted-foreground, #6b7280); margin-top: 4px; }
                .smls-pending, .smls-na { color: var(--muted-foreground, #6b7280); font-style: italic; font-size: 13px; }
                .smls-error { color: var(--destructive, #dc2626); }
            </style>
        `;
    }
}

customElements.define(TAG, SmlsTranslateField);
