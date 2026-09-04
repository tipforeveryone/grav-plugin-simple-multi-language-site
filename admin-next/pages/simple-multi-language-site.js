/**
 * Simple Multi Language Site — Admin2 "Multi Language" page (classic-admin's
 * "Language Converter"). Two sections: bulk-assign languages to pages that
 * predate the plugin, and a report of pages missing a translation. Both
 * against classes/SimpleMultiLanguageSiteApiController.php — same
 * LanguageManager/TranslationLinker logic as the original.
 */

const TAG = window.__GRAV_PAGE_TAG;
const API_BASE = (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1');
// window.__GRAV_API_TOKEN is only a one-time snapshot from when admin2 first
// imports this page component — it's never updated afterwards even though
// the host app keeps rotating the real access token in localStorage on
// every silent refresh, so a page left open across a token rotation would
// send a now-stale token and get a bare 401. currentAccessToken() re-reads
// the live token from the same localStorage key the host app itself writes
// to (see ftp-sync's admin-next/pages/ftp-sync.js for the fuller writeup —
// this plugin hit the same bug).
const API_TOKEN_FALLBACK = window.__GRAV_API_TOKEN;

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
const APP_BASE = window.__GRAV_CONFIG__?.basePath || '/admin2';

class SimpleMultiLanguageSitePage extends HTMLElement {
    connectedCallback() {
        this.dispatchEvent(new CustomEvent('page-state', {
            detail: { title: 'Multi Language', icon: 'fa-language' },
        }));
        this._render();
        this._loadMissing();
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

    _render() {
        this.innerHTML = `
            ${this._styles()}
            <div class="smlsp-wrapper">
                <section>
                    <h3>1. Gán ngôn ngữ hàng loạt cho trang cũ</h3>
                    <p class="smlsp-hint">Quét toàn bộ trang chưa có field <code>header.smls_language</code>, so route với <code>root_path</code> của từng ngôn ngữ đã cấu hình, và tự động gán — an toàn 100% vì chỉ dựa trên path prefix, không đoán mò.</p>
                    <button type="button" class="smlsp-btn smlsp-btn-primary" data-action="assign"><i class="fa fa-magic"></i> Chạy gán ngôn ngữ hàng loạt</button>
                    <div class="smlsp-assign-status"></div>
                </section>
                <section>
                    <h3>2. Trang còn thiếu bản dịch</h3>
                    <p class="smlsp-hint">Các trang đã có ngôn ngữ nhưng chưa khai báo counterpart cho ít nhất 1 ngôn ngữ khác. Bấm "Mở trang" rồi dùng nút "Add Translation" trong tab Content của trang đó.</p>
                    <div class="smlsp-missing"></div>
                </section>
            </div>
        `;

        this.querySelector('[data-action="assign"]')?.addEventListener('click', () => this._runAssign());
    }

    async _runAssign() {
        const btn = this.querySelector('[data-action="assign"]');
        const statusEl = this.querySelector('.smlsp-assign-status');
        btn.disabled = true;
        statusEl.textContent = 'Đang chạy...';

        try {
            const data = await this._fetch('/simple-multi-language-site/assign-languages', { method: 'POST', body: '{}' });
            statusEl.textContent = `Đã gán ngôn ngữ cho ${data.assigned} trang.`;
            this._loadMissing();
        } catch (err) {
            statusEl.textContent = 'Lỗi: ' + (err.message || 'unknown');
        } finally {
            btn.disabled = false;
        }
    }

    async _loadMissing() {
        const box = this.querySelector('.smlsp-missing');
        box.innerHTML = '<p class="smlsp-hint">Đang tải…</p>';

        try {
            const data = await this._fetch('/simple-multi-language-site/missing-translations');
            const rows = data.rows || [];

            if (rows.length === 0) {
                box.innerHTML = '<p class="smlsp-hint">Không có trang nào thiếu bản dịch.</p>';
                return;
            }

            box.innerHTML = `
                <table class="smlsp-table">
                    <thead><tr><th>Trang</th><th>Ngôn ngữ hiện tại</th><th>Còn thiếu</th><th></th></tr></thead>
                    <tbody>
                        ${rows.map((row) => `
                            <tr>
                                <td>${this._escape(row.title)}<br><code>${this._escape(row.route)}</code></td>
                                <td>${this._escape(row.language)}</td>
                                <td>${this._escape(row.missing_label)}</td>
                                <td><a class="smlsp-btn" href="${this._escape(APP_BASE)}/pages/edit${this._escape(row.route)}"><i class="fa fa-pencil"></i> Mở trang</a></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        } catch (err) {
            box.innerHTML = `<p class="smlsp-hint smlsp-error">${this._escape(err.message || 'Load failed')}</p>`;
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
                .smlsp-wrapper { display: flex; flex-direction: column; gap: 28px; font-family: inherit; padding: 4px; font-size: 13px; color: var(--foreground, #1f2937); }
                .smlsp-wrapper h3 { margin: 0 0 6px; font-size: 14px; }
                .smlsp-hint { color: var(--muted-foreground, #6b7280); font-size: 12.5px; margin: 0 0 10px; }
                .smlsp-error { color: var(--destructive, #dc2626); }
                .smlsp-btn { display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--border, #e5e7eb); background: var(--card, #fff); border-radius: 6px; padding: 6px 12px; font-size: 12.5px; cursor: pointer; color: var(--foreground, #1f2937); text-decoration: none; }
                .smlsp-btn:hover:not(:disabled) { background: var(--accent, #f3f4f6); }
                .smlsp-btn-primary { background: var(--primary, #3b82f6); color: var(--primary-foreground, #fff); border-color: var(--primary, #3b82f6); }
                .smlsp-assign-status { font-size: 12.5px; color: var(--muted-foreground, #6b7280); margin-top: 8px; }
                .smlsp-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
                .smlsp-table th { text-align: left; padding: 6px 8px; color: var(--muted-foreground, #6b7280); border-bottom: 1px solid var(--border, #e5e7eb); }
                .smlsp-table td { padding: 6px 8px; border-bottom: 1px solid var(--border, #e5e7eb); vertical-align: middle; }
                .smlsp-table code { font-size: 11px; color: var(--muted-foreground, #6b7280); }
            </style>
        `;
    }
}

customElements.define(TAG, SimpleMultiLanguageSitePage);
