(function () {
    function initDrawer() {
        if (!window.SAPM_FRONTEND_BAR) return;

        var data = window.SAPM_FRONTEND_BAR;
        var scope = setupDrawerScope(data);
        if (!scope || !scope.drawer) return;

        var drawer = scope.drawer;

        var toggle = drawer.querySelector('.sapm-fe-drawer-toggle');
        var panel = drawer.querySelector('.sapm-fe-drawer-panel');
        var closeBtn = drawer.querySelector('.sapm-fe-drawer-close');
        var backdrop = drawer.querySelector('.sapm-fe-drawer-backdrop');
        var body = drawer.querySelector('.sapm-fe-drawer-body');
        var s = data.strings || {};

        // Current active scope for rule saving: 'context' or 'override'
        var activeScope = 'context';

        function setupDrawerScope(barData) {
            var lightDrawer = document.getElementById('sapm-fe-drawer');

            // Already isolated in a previous run.
            if (!lightDrawer) {
                var existingHost = document.getElementById('sapm-fe-shadow-host');
                if (existingHost && existingHost.shadowRoot) {
                    var existingDrawer = existingHost.shadowRoot.getElementById('sapm-fe-drawer');
                    if (existingDrawer) {
                        return { drawer: existingDrawer, root: existingHost.shadowRoot };
                    }
                }
                return null;
            }

            // Fallback for very old browsers.
            if (!HTMLElement.prototype.attachShadow) {
                return { drawer: lightDrawer, root: document };
            }

            var host = document.getElementById('sapm-fe-shadow-host');
            if (!host) {
                host = document.createElement('div');
                host.id = 'sapm-fe-shadow-host';
                if (lightDrawer.parentNode) {
                    lightDrawer.parentNode.insertBefore(host, lightDrawer);
                } else {
                    document.body.appendChild(host);
                }
            }

            var shadow = host.shadowRoot || host.attachShadow({ mode: 'open' });

            if (barData && barData.drawerCssUrl) {
                var styleLink = shadow.querySelector('link[data-sapm-drawer-style]');
                if (!styleLink) {
                    styleLink = document.createElement('link');
                    styleLink.setAttribute('data-sapm-drawer-style', '1');
                    styleLink.rel = 'stylesheet';
                    styleLink.href = barData.drawerCssUrl;
                    shadow.appendChild(styleLink);
                } else if (styleLink.getAttribute('href') !== barData.drawerCssUrl) {
                    styleLink.setAttribute('href', barData.drawerCssUrl);
                }
            }

            if (lightDrawer.getRootNode() !== shadow) {
                shadow.appendChild(lightDrawer);
            }

            return { drawer: lightDrawer, root: shadow };
        }

        function isOpen() {
            return drawer.classList.contains('is-open');
        }

        function openDrawer() {
            drawer.classList.add('is-open');
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
            drawer.setAttribute('aria-hidden', 'false');
        }

        function closeDrawer() {
            drawer.classList.remove('is-open');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
            drawer.setAttribute('aria-hidden', 'true');
        }

        function toNumber(value, fallback) {
            var n = Number(value);
            return Number.isFinite(n) ? n : (fallback || 0);
        }

        function round2(value) {
            return Math.round(toNumber(value, 0) * 100) / 100;
        }

        function formatMs(value) {
            var n = toNumber(value, 0);
            if (n <= 0) return '0.00';
            if (n < 0.01) return '0.01';
            return n.toFixed(2);
        }

        function stateLabel(state) {
            if (state === 'blocked') return s.stateDisabled || s.blocked || 'Blocked';
            if (state === 'allowed') return s.stateEnabled || s.allowed || 'Enabled';
            return s.stateDefault || 'Default';
        }

        function getPerfMap() {
            var map = {};
            if (data.perf && data.perf.all_items && typeof data.perf.all_items === 'object') {
                Object.keys(data.perf.all_items).forEach(function (pluginKey) {
                    map[pluginKey] = data.perf.all_items[pluginKey];
                });
                return map;
            }
            if (data.perf && Array.isArray(data.perf.items)) {
                data.perf.items.forEach(function (item) {
                    map[item.plugin] = item;
                });
            }
            return map;
        }

        function getPluginPerf(pluginState, perfMap) {
            var perfInfo = perfMap ? perfMap[pluginState.file] : null;
            var ms = perfInfo ? toNumber(perfInfo.ms, 0) : 0;
            var queries = perfInfo ? toNumber(perfInfo.queries, 0) : 0;

            // In preview mode, blocked plugins should behave as not loaded on this page.
            if (pluginState && pluginState.effective === 'blocked') {
                ms = 0;
                queries = 0;
            }

            return {
                ms: ms,
                queries: Math.max(0, Math.round(queries))
            };
        }

        function getPageMetrics(perfMap) {
            var allPlugins = Array.isArray(data.allPlugins) ? data.allPlugins : [];
            var totalPlugins = allPlugins.length;
            var blockedPlugins = allPlugins.filter(function (p) { return p.effective === 'blocked'; }).length;
            var loadedPlugins = Math.max(0, totalPlugins - blockedPlugins);

            // Always prefer actual page generation time (from WP_START_TIMESTAMP)
            var totalMs = 0;
            if (typeof data.totalTime === 'string') {
                totalMs = toNumber(data.totalTime.replace(/\s*ms\s*/i, '').trim(), 0);
            }
            // Only fall back to plugin load time sum if page time unavailable
            if (!totalMs && data.perf) {
                totalMs = toNumber(data.perf.total_ms, 0);
            }

            // Always use WP total query count (get_num_queries), not just plugin-phase queries
            var totalQueries = toNumber(data.totalQueries, 0);

            // In preview mode, approximate "page after rules" metrics by subtracting blocked plugin cost.
            if (data.isPreview && allPlugins.length > 0 && perfMap) {
                var blockedMs = 0;
                var blockedQueries = 0;

                allPlugins.forEach(function (pluginState) {
                    if (pluginState.effective !== 'blocked') return;
                    var perf = getPluginPerf({ file: pluginState.file, effective: 'allowed' }, perfMap);
                    blockedMs += perf.ms;
                    blockedQueries += perf.queries;
                });

                totalMs = Math.max(0, round2(totalMs - blockedMs));
                totalQueries = Math.max(0, Math.round(totalQueries - blockedQueries));
            }

            return {
                totalMs: round2(totalMs),
                totalQueries: Math.max(0, Math.round(totalQueries)),
                totalPlugins: totalPlugins,
                loadedPlugins: loadedPlugins,
                blockedPlugins: blockedPlugins
            };
        }

        function renderHtmlSafely(target, html) {
            if (!target) return;

            var template = document.createElement('template');
            template.innerHTML = String(html || '');

            var blocked = template.content.querySelectorAll('script,iframe,object,embed,link[rel="import"]');
            for (var i = blocked.length - 1; i >= 0; i--) {
                blocked[i].remove();
            }

            var nodes = template.content.querySelectorAll('*');
            for (var n = 0; n < nodes.length; n++) {
                var node = nodes[n];
                for (var a = node.attributes.length - 1; a >= 0; a--) {
                    var attr = node.attributes[a];
                    var name = String(attr.name || '').toLowerCase();
                    var value = String(attr.value || '').trim();

                    if (name.indexOf('on') === 0) {
                        node.removeAttribute(attr.name);
                        continue;
                    }

                    if ((name === 'href' || name === 'src' || name === 'xlink:href' || name === 'formaction') && /^javascript:/i.test(value)) {
                        node.removeAttribute(attr.name);
                    }
                }
            }

            while (target.firstChild) {
                target.removeChild(target.firstChild);
            }
            target.appendChild(template.content);
        }

        function render() {
            if (!body) return;

            var perfMap = getPerfMap();
            var metrics = getPageMetrics(perfMap);
            var html = '';

            // Must stay immediately under drawer header/context area.
            html += renderPageContextBadges(metrics);

            if (data.safeMode) {
                html += '<div class="sapm-drawer-notice is-warning">⚠️ ' + esc(s.safeMode || 'Safe Mode is active — no filtering') + '</div>';
            }

            if (data.isPreview) {
                html += '<div class="sapm-drawer-notice is-info">👁 ' + esc(s.preview || 'Preview — showing what visitors see') + '</div>';
            }

            if (data.filteringMode && data.enabled && !data.safeMode) {
                var modeLabel = data.filteringMode;
                if (data.filteringMode === 'passthrough') modeLabel = s.modePassthrough || 'Passthrough — nothing filtered';
                else if (data.filteringMode === 'blacklist') modeLabel = s.modeBlacklist || 'Blacklist — selected plugins blocked';
                else if (data.filteringMode === 'whitelist') modeLabel = s.modeWhitelist || 'Whitelist — only selected plugins allowed';
                html += '<div class="sapm-drawer-meta">' + esc(modeLabel) + '</div>';
            }

            html += renderPerfSection();
            html += renderAssetsSection();
            html += renderRulesSection(perfMap);

            renderHtmlSafely(body, html);
            bindActionButtons();
        }

        function renderPageContextBadges(metrics) {
            var html = '<div class="sapm-drawer-section sapm-fe-page-context">';
            html += '<div class="sapm-drawer-section-title section-perf">' + esc((s.perfTitle || 'Performance') + ' — ' + (s.contextLabel || 'Page context')) + '</div>';
            html += '<div class="sapm-fe-drawer-pills">';

            html += '<span class="sapm-fe-drawer-pill pill-info">⏱ ' + Math.round(metrics.totalMs) + ' ms</span>';
            html += '<span class="sapm-fe-drawer-pill pill-info">🗄 ' + metrics.totalQueries + ' ' + esc(s.queries || 'queries') + '</span>';
            html += '<span class="sapm-fe-drawer-pill pill-active" title="' + esc(metrics.loadedPlugins + ' loaded / ' + metrics.blockedPlugins + ' blocked') + '">✓ ' + metrics.totalPlugins + ' ' + esc(s.plugins || 'plugins') + '</span>';

            html += '</div>';
            html += '</div>';

            return html;
        }

        function renderPerfSection() {
            var html = '<div class="sapm-drawer-section">';
            html += '<div class="sapm-drawer-section-title section-perf">' + esc(s.perfTitle || 'Performance') + '</div>';

            var perf = data.perf;
            if (!perf) {
                html += '<div class="sapm-drawer-empty">' + esc(s.perfEmpty || 'No performance data yet') + '</div>';
                html += '</div>';
                return html;
            }

            var metaBits = [];
            if (typeof perf.total_ms !== 'undefined') {
                metaBits.push((s.perfTotal || 'Plugin load total') + ': ' + Number(perf.total_ms).toFixed(2) + ' ms');
            }
            if (typeof perf.total_queries !== 'undefined' && perf.total_queries !== null) {
                metaBits.push((s.perfQueries || 'Queries') + ': ' + Number(perf.total_queries));
            }
            if (perf.captured_at) {
                metaBits.push((s.perfCapturedAt || 'Captured') + ': ' + perf.captured_at);
            }
            if (metaBits.length > 0) {
                html += '<div class="sapm-drawer-meta">' + esc(metaBits.join(' · ')) + '</div>';
            }

            // Per-plugin times are shown inline in Plugin Management below
            html += '<div class="sapm-drawer-meta sapm-drawer-meta-hint">' + esc(s.perfInlineHint || 'Per-plugin load times shown in Plugin Management below.') + '</div>';

            html += '</div>';
            return html;
        }

        function renderScopeSelector() {
            var ovr = data.override;
            if (!ovr) return ''; // No override target available (e.g. homepage)

            var html = '<div class="sapm-fe-scope-selector">';
            html += '<div class="sapm-drawer-section-title section-scope">' + esc(s.scopeLabel || 'Apply to') + '</div>';
            html += '<div class="sapm-fe-drawer-pills">';
            html += '<button type="button" class="sapm-fe-drawer-pill sapm-fe-scope-pill' + (activeScope === 'context' ? ' pill-active' : ' pill-info') + '" data-scope="context">' + esc(s.scopeContext || 'All of this type') + '</button>';
            html += '<button type="button" class="sapm-fe-drawer-pill sapm-fe-scope-pill' + (activeScope === 'override' ? ' pill-active' : ' pill-info') + '" data-scope="override">' + esc(s.scopeOverride || 'Only this page') + '</button>';
            if (ovr.label) {
                html += '<span class="sapm-fe-drawer-pill pill-context">📌 ' + esc(ovr.label) + '</span>';
            }
            html += '</div>';

            if (ovr.hasRules && activeScope === 'override') {
                html += '<button type="button" class="sapm-fe-reset-overrides-btn" id="sapm-fe-reset-overrides">✕ ' + esc(s.resetOverride || 'Reset per-page overrides') + '</button>';
            }

            html += '</div>';
            return html;
        }

        function renderRulesSection(perfMap) {
            var allPlugins = data.allPlugins || [];
            var html = '<div class="sapm-drawer-section">';
            html += '<div class="sapm-drawer-section-title section-rules">' + esc(s.rulesTitle || 'Plugin Rules') + ' (' + allPlugins.length + ')</div>';

            // Scope selector (context vs per-page)
            html += renderScopeSelector();

            if (data.override && data.override.hasRules) {
                html += '<div class="sapm-drawer-notice is-override">📌 ' + esc(s.overrideActive || 'Per-page override active') + '</div>';
            }

            if (allPlugins.length > 0) {
                html += '<div class="sapm-drawer-list" id="sapm-fe-rules-list">';
                allPlugins.forEach(function (p) {
                    html += renderPluginRow(p, perfMap);
                });
                html += '</div>';
                html += '<div class="sapm-fe-drawer-reload-notice" id="sapm-fe-reload-notice" hidden>';
                html += '<span>🔄 ' + esc(s.reloadNotice || 'Changes will take effect after page reload') + '</span>';
                html += ' <button type="button" class="sapm-fe-reload-btn" id="sapm-fe-reload-btn">Reload</button>';
                html += '</div>';
            }

            html += '</div>';
            return html;
        }

        function renderAssetsSection() {
            var dqScripts = data.dequeuedScripts || [];
            var dqStyles = data.dequeuedStyles || [];
            var dqTotal = dqScripts.length + dqStyles.length;
            var jsLabel = esc(s.jsLabel || 'JS');
            var cssLabel = esc(s.cssLabel || 'CSS');

            var html = '<div class="sapm-drawer-section">';
            html += '<div class="sapm-drawer-section-title section-assets">' + esc(s.assetsTitle || 'Dequeued assets') + ' (' + dqTotal + ') <span class="sapm-fe-assets-breakdown">' + jsLabel + ': ' + dqScripts.length + ' / ' + cssLabel + ': ' + dqStyles.length + '</span></div>';
            html += '<div class="sapm-drawer-meta">' + esc(s.assetsHint || 'Shows CSS/JS handles removed on this page by dequeue rules.') + '</div>';

            if (dqScripts.length > 0 || dqStyles.length > 0) {
                html += '<div class="sapm-drawer-list">';
                dqScripts.forEach(function (h) {
                    html += '<div class="sapm-drawer-row">';
                    html += '<div class="sapm-drawer-row-main">';
                    html += '<div class="sapm-drawer-plugin">JS: ' + esc(h) + '</div>';
                    html += '<div class="sapm-drawer-rule is-disabled">dequeued</div>';
                    html += '</div>';
                    html += '</div>';
                });
                dqStyles.forEach(function (h) {
                    html += '<div class="sapm-drawer-row">';
                    html += '<div class="sapm-drawer-row-main">';
                    html += '<div class="sapm-drawer-plugin">CSS: ' + esc(h) + '</div>';
                    html += '<div class="sapm-drawer-rule is-disabled">dequeued</div>';
                    html += '</div>';
                    html += '</div>';
                });
                html += '</div>';
            } else {
                html += '<div class="sapm-drawer-empty">' + esc(s.noAssets || 'No assets dequeued') + '</div>';
                html += '<div class="sapm-drawer-meta sapm-fe-assets-help">' + esc(s.assetsHowTo || 'To see data here, create dequeue rules for this page context in settings, then reload this page.') + '</div>';
                if (data.settingsUrl) {
                    html += '<div class="sapm-drawer-meta sapm-fe-assets-help-link"><a class="sapm-drawer-settings" href="' + esc(data.settingsUrl) + '">⚙ ' + esc(s.assetsOpenSettings || 'Open Asset Rules') + '</a></div>';
                }
            }

            html += '</div>';
            return html;
        }

        function renderPluginRow(p, perfMap) {
            var perf = getPluginPerf(p, perfMap);
            var timeHtml = '<div class="sapm-drawer-time">' + formatMs(perf.ms) + ' ms</div>';

            var ruleClass = 'sapm-drawer-rule';
            if (p.effective === 'blocked') {
                ruleClass += ' is-disabled';
            }

            // Show effective state label (accounts for overrides)
            var sLabel = stateLabel(p.effective === 'blocked' ? 'blocked' : (p.effective === 'allowed' ? 'allowed' : p.state));

            // Override badge
            var overrideBadge = '';
            if (p.overrideState && p.overrideState !== 'default') {
                var badgeClass = p.overrideState === 'blocked' ? 'is-override-blocked' : 'is-override-allowed';
                overrideBadge = ' <span class="sapm-fe-override-badge ' + badgeClass + '">📌 ' + esc(s.overrideSource || 'override') + '</span>';
            }

            var html = '<div class="sapm-drawer-row" data-sapm-plugin="' + esc(p.file) + '">';
            html += '<div class="sapm-drawer-row-main">';
            html += '<div class="sapm-drawer-plugin">' + esc(p.name) + overrideBadge + '</div>';
            html += timeHtml;
            html += '<div class="' + ruleClass + '">' + esc(sLabel) + '</div>';
            html += '</div>';

            if (!p.protected) {
                html += '<div class="sapm-drawer-actions">';
                html += '<button type="button" class="button button-small sapm-fe-action-btn" data-sapm-action="enabled" data-sapm-plugin="' + esc(p.file) + '">' + esc(s.actionEnable || s.allow || 'Enable') + '</button>';
                html += '<button type="button" class="button button-small sapm-fe-action-btn" data-sapm-action="disabled" data-sapm-plugin="' + esc(p.file) + '">' + esc(s.actionDisable || s.block || 'Block') + '</button>';
                html += '<button type="button" class="button button-small sapm-fe-action-btn" data-sapm-action="default" data-sapm-plugin="' + esc(p.file) + '">' + esc(s.actionDefault || s.default || 'Default') + '</button>';
                html += '</div>';
            } else {
                html += '<div class="sapm-drawer-actions"><span class="sapm-fe-protected">🔒 ' + esc(s.protectedPlugin || 'protected') + '</span></div>';
            }
            html += '</div>';

            return html;
        }

        function bindActionButtons() {
            var buttons = body.querySelectorAll('.sapm-fe-action-btn');
            buttons.forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var action = btn.getAttribute('data-sapm-action');
                    var plugin = btn.getAttribute('data-sapm-plugin');
                    if (!action || !plugin) return;
                    sendRuleChange(plugin, action, btn);
                });
            });

            // Scope selector pills
            var scopePills = body.querySelectorAll('.sapm-fe-scope-pill');
            scopePills.forEach(function (pill) {
                pill.addEventListener('click', function (e) {
                    e.preventDefault();
                    var newScope = pill.getAttribute('data-scope');
                    if (newScope && newScope !== activeScope) {
                        activeScope = newScope;
                        render();
                    }
                });
            });

            // Reset overrides button
            var resetBtn = body.querySelector('#sapm-fe-reset-overrides');
            if (resetBtn) {
                resetBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    sendResetOverrides(resetBtn);
                });
            }

            var reloadBtn = body.querySelector('#sapm-fe-reload-btn');
            if (reloadBtn) {
                reloadBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.location.reload();
                });
            }
        }

        function mapActionToRuleAction(action) {
            if (action === 'enabled' || action === 'allow') return 'allow';
            if (action === 'disabled' || action === 'block') return 'block';
            return 'default';
        }

        function sendRuleChange(plugin, action, btn) {
            if (!data.ajaxUrl || !data.nonce) return;

            var row = btn.closest('.sapm-drawer-row');
            var rowBtns = row ? row.querySelectorAll('.sapm-fe-action-btn') : [];
            rowBtns.forEach(function (b) { b.disabled = true; b.classList.add('is-saving'); });

            var params = new URLSearchParams();
            params.set('action', 'sapm_frontend_toggle_rule');
            params.set('nonce', data.nonce);
            params.set('context', data.context);
            params.set('plugin', plugin);
            params.set('rule_action', mapActionToRuleAction(action));
            params.set('scope', activeScope);

            // For override scope, include target identification
            if (activeScope === 'override' && data.override) {
                params.set('override_type', data.override.type);
                params.set('override_id', data.override.id);
            }

            fetch(data.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            })
                .then(function (resp) { return resp.json(); })
                .then(function (resp) {
                    rowBtns.forEach(function (b) { b.disabled = false; b.classList.remove('is-saving'); });

                    if (!resp.success) {
                        throw new Error('Request failed');
                    }

                    data.disabledPlugins = resp.data.disabledPlugins || [];
                    data.filteringMode = resp.data.mode || data.filteringMode;

                    // Update allPlugins with full state from server (includes overrideState)
                    if (resp.data.allPlugins) {
                        data.allPlugins = resp.data.allPlugins;
                    }

                    // Update override data if returned
                    if (resp.data.overrides && data.override) {
                        data.override.overrides = resp.data.overrides;
                        var hasRules = (resp.data.overrides.disabled_plugins && resp.data.overrides.disabled_plugins.length > 0) ||
                                       (resp.data.overrides.enabled_plugins && resp.data.overrides.enabled_plugins.length > 0);
                        data.override.hasRules = hasRules;
                    }

                    render();
                    updateBadge();

                    var notice = drawer.querySelector('#sapm-fe-reload-notice');
                    if (notice) notice.hidden = false;
                })
                .catch(function () {
                    rowBtns.forEach(function (b) {
                        b.disabled = false;
                        b.classList.remove('is-saving');
                    });
                });
        }

        function sendResetOverrides(btn) {
            if (!data.ajaxUrl || !data.nonce || !data.override) return;

            btn.disabled = true;
            btn.textContent = esc(s.saving || 'Saving…');

            var params = new URLSearchParams();
            params.set('action', 'sapm_frontend_reset_overrides');
            params.set('nonce', data.nonce);
            params.set('context', data.context);
            params.set('override_type', data.override.type);
            params.set('override_id', data.override.id);

            fetch(data.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: params.toString()
            })
                .then(function (resp) { return resp.json(); })
                .then(function (resp) {
                    if (!resp.success) {
                        throw new Error('Reset failed');
                    }

                    // Clear override data
                    data.override.hasRules = false;
                    data.override.overrides = { disabled_plugins: [], enabled_plugins: [] };

                    // Update allPlugins from server
                    if (resp.data.allPlugins) {
                        data.allPlugins = resp.data.allPlugins;
                    }

                    render();
                    updateBadge();

                    var notice = drawer.querySelector('#sapm-fe-reload-notice');
                    if (notice) notice.hidden = false;
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = esc(s.resetOverride || 'Reset per-page overrides');
                });
        }

        function updateBadge() {
            var badge = drawer.querySelector('.sapm-fe-drawer-toggle-badge');
            if (!badge) return;

            var count = (data.disabledPlugins || []).length + (data.dequeuedScripts || []).length + (data.dequeuedStyles || []).length;
            badge.textContent = count;
            if (count > 0) badge.classList.add('has-items');
            else badge.classList.remove('has-items');
        }

        function esc(text) {
            if (!text && text !== 0) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(String(text)));
            return div.innerHTML;
        }

        if (toggle) {
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                isOpen() ? closeDrawer() : openDrawer();
            });
        }

        // Admin bar toolbar toggle
        var adminBarItem = document.getElementById('wp-admin-bar-sapm-frontend');
        if (adminBarItem) {
            var adminBarLink = adminBarItem.querySelector('a');
            if (adminBarLink) {
                adminBarLink.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    isOpen() ? closeDrawer() : openDrawer();
                });
            }
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                closeDrawer();
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', function () {
                closeDrawer();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) {
                closeDrawer();
            }
        });

        render();
        updateBadge();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDrawer);
    } else {
        initDrawer();
    }
})();