(function(){
    if (!window.SAPM_DRAWER) return;

    var data = window.SAPM_DRAWER;
    var drawer = document.getElementById('sapm-admin-drawer');
    if (!drawer) return;

    var toggle = drawer.querySelector('.sapm-drawer-toggle');
    var adminBarToggle = document.querySelector('#wp-admin-bar-sapm-info > a');
    var panel = drawer.querySelector('.sapm-drawer-panel');
    var closeBtn = drawer.querySelector('.sapm-drawer-close');
    var notice = drawer.querySelector('[data-sapm-notice]');
    var contextLabel = drawer.querySelector('[data-sapm-context-label]');

    var perfTitle = drawer.querySelector('[data-sapm-perf-title]');
    var perfMeta = drawer.querySelector('[data-sapm-perf-meta]');
    var perfList = drawer.querySelector('[data-sapm-perf-list]');
    var perfEmpty = drawer.querySelector('[data-sapm-perf-empty]');

    var disabledTitle = drawer.querySelector('[data-sapm-disabled-title]');
    var disabledList = drawer.querySelector('[data-sapm-disabled-list]');
    var disabledEmpty = drawer.querySelector('[data-sapm-disabled-empty]');

    var deferredTitle = drawer.querySelector('[data-sapm-deferred-title]');
    var deferredList = drawer.querySelector('[data-sapm-deferred-list]');
    var deferredEmpty = drawer.querySelector('[data-sapm-deferred-empty]');

    var rulesTitle = drawer.querySelector('[data-sapm-rules-title]');
    var rulesList = drawer.querySelector('[data-sapm-rules-list]');
    var rulesEmpty = drawer.querySelector('[data-sapm-rules-empty]');

    var addTitle = drawer.querySelector('[data-sapm-add-title]');
    var addPlugin = drawer.querySelector('[data-sapm-add-plugin]');
    var addAction = drawer.querySelector('[data-sapm-add-action]');
    var addApply = drawer.querySelector('[data-sapm-add-apply]');

    var settingsLink = drawer.querySelector('[data-sapm-settings-link]');

    var strings = data.strings || {};

    function setNotice(message, type){
        if (!notice) return;
        notice.textContent = message || '';
        notice.classList.remove('is-error','is-success','is-info');
        if (message) {
            notice.classList.add(type ? 'is-' + type : 'is-info');
        }
    }

    function isOpen(){
        return drawer.classList.contains('is-open');
    }

    function openDrawer(){
        drawer.classList.add('is-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
        drawer.setAttribute('aria-hidden', 'false');
        try { localStorage.setItem('sapmDrawerOpen', '1'); } catch(e) {}
    }

    function closeDrawer(){
        drawer.classList.remove('is-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
        drawer.setAttribute('aria-hidden', 'true');
        try { localStorage.setItem('sapmDrawerOpen', '0'); } catch(e) {}
    }

    function getEffectiveState(plugin){
        if (!data.effectiveStates || !data.effectiveStates[plugin]) {
            return { state: null, source: null };
        }
        return data.effectiveStates[plugin];
    }

    function getInheritedState(plugin){
        if (!data.inheritedStates || !data.inheritedStates[plugin]) {
            return { state: null, source: null };
        }
        return data.inheritedStates[plugin];
    }

    function stateLabel(state){
        if (state === 'enabled') return strings.stateEnabled || 'Enabled';
        if (state === 'disabled') return strings.stateDisabled || 'Blocked';
        if (state === 'defer') return strings.stateDefer || 'Deferred';
        return strings.stateDefault || 'Default';
    }

    function applyRuleLocal(plugin, state){
        data.screenRules = data.screenRules || {};
        data.effectiveStates = data.effectiveStates || {};

        var nextEffectiveState = null;

        if (state === 'default') {
            delete data.screenRules[plugin];
            data.effectiveStates[plugin] = getInheritedState(plugin);
            nextEffectiveState = data.effectiveStates[plugin] && data.effectiveStates[plugin].state ? data.effectiveStates[plugin].state : null;
            syncRequestLists(plugin, nextEffectiveState);
            return;
        }

        data.screenRules[plugin] = state;
        data.effectiveStates[plugin] = { state: state, source: 'screen' };
        syncRequestLists(plugin, state);
    }

    function syncRequestLists(plugin, state){
        if (!data.disabledThisRequest) data.disabledThisRequest = [];
        if (!data.deferredThisRequest) data.deferredThisRequest = [];

        var removeFrom = function(list){
            var idx = list.indexOf(plugin);
            if (idx > -1) list.splice(idx, 1);
        };

        removeFrom(data.disabledThisRequest);
        removeFrom(data.deferredThisRequest);

        if (state === 'disabled') {
            data.disabledThisRequest.push(plugin);
        } else if (state === 'defer') {
            data.deferredThisRequest.push(plugin);
        }
    }

    function sendRuleChange(plugin, state, callback){
        setNotice(strings.saving || 'Saving…', 'info');

        var params = new URLSearchParams();
        params.set('action', 'sapm_drawer_toggle_rule');
        params.set('nonce', data.nonce || '');
        params.set('screen_id', data.screen ? data.screen.id : '');
        params.set('plugin', plugin);
        params.set('state', state);

        fetch(data.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: params.toString()
        })
        .then(function(resp){ return resp.json(); })
        .then(function(payload){
            if (payload && payload.success){
                setNotice(strings.saved || 'Saved.', 'success');
                if (typeof callback === 'function') callback(true, payload);
                return;
            }
            throw new Error('Request failed');
        })
        .catch(function(){
            setNotice(strings.error || 'Action failed. Please try again.', 'error');
            if (typeof callback === 'function') callback(false);
        });
    }

    function makeActionButton(label, actionState, plugin){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button button-small';
        btn.textContent = label;
        btn.dataset.sapmAction = actionState;
        btn.dataset.sapmPlugin = plugin;
        return btn;
    }

    function renderPerf(){
        if (!perfTitle || !perfList || !perfEmpty || !perfMeta) return;

        perfTitle.textContent = strings.perfTitle || 'Performance snapshot';
        perfList.innerHTML = '';
        perfMeta.textContent = '';
        perfEmpty.textContent = '';

        if (!data.perf || !Array.isArray(data.perf.items) || data.perf.items.length === 0){
            perfEmpty.textContent = strings.perfEmpty || '';
            return;
        }

        var metaBits = [];
        if (typeof data.perf.total_ms !== 'undefined'){
            metaBits.push((strings.perfTotal || 'Total') + ': ' + Number(data.perf.total_ms).toFixed(2) + ' ms');
        }
        if (typeof data.perf.total_queries !== 'undefined' && data.perf.total_queries !== null){
            metaBits.push((strings.perfQueries || 'Queries') + ': ' + Number(data.perf.total_queries));
        }
        if (data.perf.captured_at){
            metaBits.push((strings.perfCaptured || 'Captured') + ': ' + data.perf.captured_at);
        }
        perfMeta.textContent = metaBits.join(' · ');

        if (data.perf.matches_current === false){
            var latest = document.createElement('div');
            latest.className = 'sapm-drawer-hint';
            latest.textContent = strings.perfLatest || '';
            perfMeta.appendChild(latest);
        }

        data.perf.items.forEach(function(item){
            var row = document.createElement('div');
            row.className = 'sapm-drawer-row';

            var main = document.createElement('div');
            main.className = 'sapm-drawer-row-main';

            var name = document.createElement('div');
            name.className = 'sapm-drawer-plugin';
            name.textContent = item.name || item.plugin;

            var time = document.createElement('div');
            time.className = 'sapm-drawer-time';
            var timeText = Number(item.ms || 0).toFixed(2) + ' ms';
            if (typeof item.queries !== 'undefined' && item.queries !== null){
                timeText += ' · ' + item.queries + ' ' + (strings.perfQueriesShort || 'queries');
            }
            time.textContent = timeText;

            var stateInfo = getEffectiveState(item.plugin);
            var stateText = stateLabel(stateInfo.state);
            var stateLine = document.createElement('div');
            stateLine.className = 'sapm-drawer-rule';
            stateLine.textContent = stateText;

            main.appendChild(name);
            main.appendChild(time);
            main.appendChild(stateLine);

            var actions = document.createElement('div');
            actions.className = 'sapm-drawer-actions';
            actions.appendChild(makeActionButton(strings.actionEnable || 'Enable', 'enabled', item.plugin));
            actions.appendChild(makeActionButton(strings.actionDisable || 'Block', 'disabled', item.plugin));
            actions.appendChild(makeActionButton(strings.actionDefer || 'Defer', 'defer', item.plugin));
            if (stateInfo.source === 'screen'){
                actions.appendChild(makeActionButton(strings.actionDefault || 'Default', 'default', item.plugin));
            }

            row.appendChild(main);
            row.appendChild(actions);
            perfList.appendChild(row);
        });
    }

    function renderDisabled(){
        if (!disabledTitle || !disabledList || !disabledEmpty) return;

        disabledTitle.textContent = strings.disabledTitle || 'Disabled plugins';
        disabledList.innerHTML = '';
        disabledEmpty.textContent = '';

        var list = Array.isArray(data.disabledThisRequest) ? data.disabledThisRequest : [];
        if (list.length === 0){
            disabledEmpty.textContent = strings.disabledEmpty || '';
            return;
        }

        list.forEach(function(plugin){
            var row = document.createElement('div');
            row.className = 'sapm-drawer-row';

            var main = document.createElement('div');
            main.className = 'sapm-drawer-row-main';

            var name = document.createElement('div');
            name.className = 'sapm-drawer-plugin';
            name.textContent = (data.plugins && data.plugins[plugin]) ? data.plugins[plugin] : plugin;

            var rule = document.createElement('div');
            rule.className = 'sapm-drawer-rule is-disabled';
            rule.textContent = strings.stateDisabled || 'Disabled';

            main.appendChild(name);
            main.appendChild(rule);

            var actions = document.createElement('div');
            actions.className = 'sapm-drawer-actions';
            actions.appendChild(makeActionButton(strings.actionEnable || 'Enable', 'enabled', plugin));
            actions.appendChild(makeActionButton(strings.actionDefer || 'Defer', 'defer', plugin));

            row.appendChild(main);
            row.appendChild(actions);
            disabledList.appendChild(row);
        });
    }

    function renderDeferred(){
        if (!deferredTitle || !deferredList || !deferredEmpty) return;

        deferredTitle.textContent = strings.deferredTitle || 'Deferred plugins';
        deferredList.innerHTML = '';
        deferredEmpty.textContent = '';

        var list = Array.isArray(data.deferredThisRequest) ? data.deferredThisRequest : [];
        if (list.length === 0){
            deferredEmpty.textContent = strings.deferredEmpty || '';
            return;
        }

        list.forEach(function(plugin){
            var row = document.createElement('div');
            row.className = 'sapm-drawer-row';

            var main = document.createElement('div');
            main.className = 'sapm-drawer-row-main';

            var name = document.createElement('div');
            name.className = 'sapm-drawer-plugin';
            name.textContent = (data.plugins && data.plugins[plugin]) ? data.plugins[plugin] : plugin;

            var rule = document.createElement('div');
            rule.className = 'sapm-drawer-rule is-defer';
            rule.textContent = strings.stateDefer || 'Deferred';

            main.appendChild(name);
            main.appendChild(rule);

            var actions = document.createElement('div');
            actions.className = 'sapm-drawer-actions';
            actions.appendChild(makeActionButton(strings.actionEnable || 'Enable', 'enabled', plugin));
            actions.appendChild(makeActionButton(strings.actionDisable || 'Block', 'disabled', plugin));

            row.appendChild(main);
            row.appendChild(actions);
            deferredList.appendChild(row);
        });
    }

    function renderRules(){
        if (!rulesTitle || !rulesList || !rulesEmpty) return;

        rulesTitle.textContent = strings.rulesTitle || 'Rules for this screen';
        rulesList.innerHTML = '';
        rulesEmpty.textContent = '';

        var rules = data.screenRules || {};
        var keys = Object.keys(rules);
        if (keys.length === 0){
            rulesEmpty.textContent = strings.rulesEmpty || '';
            return;
        }

        keys.forEach(function(plugin){
            var row = document.createElement('div');
            row.className = 'sapm-drawer-row';

            var main = document.createElement('div');
            main.className = 'sapm-drawer-row-main';

            var name = document.createElement('div');
            name.className = 'sapm-drawer-plugin';
            name.textContent = (data.plugins && data.plugins[plugin]) ? data.plugins[plugin] : plugin;

            var rule = document.createElement('div');
            rule.className = 'sapm-drawer-rule';
            rule.textContent = stateLabel(rules[plugin]);

            main.appendChild(name);
            main.appendChild(rule);

            var actions = document.createElement('div');
            actions.className = 'sapm-drawer-actions';
            actions.appendChild(makeActionButton(strings.actionDefault || 'Default', 'default', plugin));

            row.appendChild(main);
            row.appendChild(actions);
            rulesList.appendChild(row);
        });
    }

    function renderAdd(){
        if (!addTitle || !addPlugin || !addAction || !addApply) return;

        addTitle.textContent = strings.addTitle || 'Add rule';
        addApply.textContent = strings.actionApply || 'Apply';

        addPlugin.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = strings.addPlaceholder || 'Select plugin';
        addPlugin.appendChild(placeholder);

        if (data.plugins){
            Object.keys(data.plugins).forEach(function(file){
                var opt = document.createElement('option');
                opt.value = file;
                opt.textContent = data.plugins[file];
                addPlugin.appendChild(opt);
            });
        }

        var disableOption = addAction.querySelector('option[value=disabled]');
        if (disableOption) disableOption.textContent = strings.actionDisable || 'Block';
        var deferOption = addAction.querySelector('option[value=defer]');
        if (deferOption) deferOption.textContent = strings.actionDefer || 'Defer';
        var enableOption = addAction.querySelector('option[value=enabled]');
        if (enableOption) enableOption.textContent = strings.actionEnable || 'Enable';
    }

    function handleActionClick(e){
        var btn = e.target.closest('button[data-sapm-action]');
        if (!btn) return;
        e.preventDefault();

        var state = btn.dataset.sapmAction || 'default';
        var plugin = btn.dataset.sapmPlugin || '';
        if (!plugin) return;

        sendRuleChange(plugin, state, function(ok){
            if (!ok) return;
            applyRuleLocal(plugin, state);
            renderPerf();
            renderDisabled();
            renderDeferred();
            renderRules();
        });
    }

    if (toggle){
        toggle.addEventListener('click', function(){
            isOpen() ? closeDrawer() : openDrawer();
        });
    }

    if (adminBarToggle){
        adminBarToggle.addEventListener('click', function(e){
            e.preventDefault();
            isOpen() ? closeDrawer() : openDrawer();
        });
    }

    if (closeBtn){
        closeBtn.addEventListener('click', function(){
            closeDrawer();
        });
    }

    if (panel){
        panel.addEventListener('click', handleActionClick);
    }

    if (addApply){
        addApply.addEventListener('click', function(){
            var plugin = addPlugin ? addPlugin.value : '';
            var state = addAction ? addAction.value : 'disabled';
            if (!plugin){
                setNotice(strings.selectPlugin || 'Select plugin', 'error');
                return;
            }
            sendRuleChange(plugin, state, function(ok){
                if (!ok) return;
                applyRuleLocal(plugin, state);
                renderPerf();
                renderDisabled();
                renderDeferred();
                renderRules();
            });
        });
    }

    if (contextLabel){
        contextLabel.textContent = (data.screen && data.screen.label) ? data.screen.label : (data.screen ? data.screen.id : '');
    }

    if (settingsLink && data.settingsUrl){
        settingsLink.href = data.settingsUrl;
    }

    renderPerf();
    renderDisabled();
    renderDeferred();
    renderRules();
    renderAdd();

    try {
        if (localStorage.getItem('sapmDrawerOpen') === '1'){
            openDrawer();
        }
    } catch(e) {}
})();
