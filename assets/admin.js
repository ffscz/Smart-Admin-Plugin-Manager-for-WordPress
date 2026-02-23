(function($){
    if (!window.sapmData) {
        return;
    }

    $(document).ready(function() {
        function renderTextMessage($container, message, color) {
            var safeMessage = (message === null || typeof message === 'undefined') ? '' : String(message);
            var $p = $('<p>').text(safeMessage);
            if (color) {
                $p.css('color', color);
            }
            $container.empty().append($p);
        }

        var $sapmWrap = $('.sapm-wrap');
        var $body = $(document.body);

        function syncCheckboxCardState($input) {
            var $card = $input.closest('.sapm-rt-plugin-check');
            if (!$card.length) {
                return;
            }
            $card.toggleClass('checked', $input.is(':checked'));
        }

        $('.sapm-rt-plugin-check input[type="checkbox"]').each(function() {
            syncCheckboxCardState($(this));
        });

        $(document).on('change', '.sapm-rt-plugin-check input[type="checkbox"]', function() {
            syncCheckboxCardState($(this));
        });

        function normalizeAdminTheme(theme) {
            return theme === 'dark' ? 'dark' : 'light';
        }

        function applyAdministrationTheme(theme) {
            var normalizedTheme = normalizeAdminTheme(theme);
            $sapmWrap
                .removeClass('sapm-admin-theme-dark sapm-admin-theme-light')
                .addClass('sapm-admin-theme-' + normalizedTheme)
                .attr('data-sapm-admin-theme', normalizedTheme);

            $body
                .removeClass('sapm-page-theme-dark sapm-page-theme-light')
                .addClass('sapm-page-theme-' + normalizedTheme);
        }

        function getEffectivePluginState($tag) {
            if (!$tag || !$tag.length) {
                return 'default';
            }

            if ($tag.hasClass('inherited')) {
                var inheritedState = $tag.data('inheritedState');
                if (inheritedState === 'enabled' || inheritedState === 'disabled' || inheritedState === 'defer') {
                    return inheritedState;
                }
            }

            var state = $tag.data('state');
            return (state === 'enabled' || state === 'disabled' || state === 'defer') ? state : 'default';
        }

        function renderGroupStats($target, counts) {
            if (!$target || !$target.length) {
                return;
            }

            var blockLabel = (sapmData.strings && sapmData.strings.countBlock) ? sapmData.strings.countBlock : 'Block';
            var delayLabel = (sapmData.strings && sapmData.strings.countDelay) ? sapmData.strings.countDelay : 'Delay';
            var allowLabel = (sapmData.strings && sapmData.strings.countAllow) ? sapmData.strings.countAllow : 'Allow';
            var showDelay = $target.data('showDelay');
            showDelay = !(showDelay === false || showDelay === 0 || showDelay === '0');

            $target.empty()
                .append(
                    $('<span>', { 'class': 'sapm-group-stat is-block' })
                        .append($('<strong>').text(counts.disabled))
                        .append($('<em>').text(blockLabel))
                );

            if (showDelay) {
                $target.append(
                    $('<span>', { 'class': 'sapm-group-stat is-delay' })
                        .append($('<strong>').text(counts.defer))
                        .append($('<em>').text(delayLabel))
                );
            }

            $target.append(
                $('<span>', { 'class': 'sapm-group-stat is-allow' })
                    .append($('<strong>').text(counts.enabled))
                    .append($('<em>').text(allowLabel))
            );
        }

        function updateGroupHeaderStats() {
            $('.sapm-screen-group').each(function() {
                var $group = $(this);
                var $rows = $group.find('> .sapm-group-content .sapm-screen-row[data-screen-id]');

                if (!$rows.length) {
                    return;
                }

                var counts = {
                    enabled: 0,
                    disabled: 0,
                    defer: 0
                };

                $rows.find('.sapm-plugin-tag').each(function() {
                    var effectiveState = getEffectivePluginState($(this));

                    if (effectiveState === 'enabled') {
                        counts.enabled += 1;
                    } else if (effectiveState === 'disabled') {
                        counts.disabled += 1;
                    } else if (effectiveState === 'defer') {
                        counts.defer += 1;
                    }
                });

                var $headerStats = $group.find('> .sapm-group-header .sapm-group-header-stats').first();
                if (!$headerStats.length) {
                    return;
                }

                renderGroupStats($headerStats, counts);
            });
        }

        var initialTheme = sapmData.adminTheme || 'light';
        applyAdministrationTheme(initialTheme);

        var $adminThemeInputs = $('input[name="sapm_admin_theme"]');
        if ($adminThemeInputs.length) {
            initialTheme = $adminThemeInputs.filter(':checked').val() || initialTheme;
            applyAdministrationTheme(initialTheme);

            $adminThemeInputs.on('change', function() {
                var $status = $('#sapm-theme-status');
                var selectedTheme = normalizeAdminTheme($(this).val());
                var previousTheme = normalizeAdminTheme($sapmWrap.attr('data-sapm-admin-theme'));

                $status.text(sapmData.strings.saving).css('color', '#0073aa');
                $adminThemeInputs.prop('disabled', true);
                applyAdministrationTheme(selectedTheme);

                $.post(sapmData.ajaxUrl, {
                    action: 'sapm_save_admin_theme',
                    nonce: sapmData.nonce,
                    theme: selectedTheme
                }, function(response) {
                    if (response.success) {
                        var savedTheme = normalizeAdminTheme((response.data && response.data.theme) || selectedTheme);
                        applyAdministrationTheme(savedTheme);
                        $status.text(sapmData.strings.themeSaved || sapmData.strings.saved).css('color', '#46b450');
                        setTimeout(function() {
                            $status.fadeOut(400, function() {
                                $(this).text('').show();
                            });
                        }, 1800);
                    } else {
                        applyAdministrationTheme(previousTheme);
                        $adminThemeInputs.filter('[value="' + previousTheme + '"]').prop('checked', true);
                        $status.text((response.data && response.data.message) || sapmData.strings.themeError || sapmData.strings.error).css('color', '#dc3232');
                    }
                }).fail(function() {
                    applyAdministrationTheme(previousTheme);
                    $adminThemeInputs.filter('[value="' + previousTheme + '"]').prop('checked', true);
                    $status.text(sapmData.strings.themeError || sapmData.strings.error).css('color', '#dc3232');
                }).always(function() {
                    $adminThemeInputs.prop('disabled', false);
                });
            });
        }

        // Toggle groups – smooth slideToggle
        $('.sapm-group-header').on('click', function() {
            var $header = $(this);
            var $content = $header.next('.sapm-group-content');
            $header.toggleClass('collapsed');
            $content.slideToggle(220);
        });

        // Click on plugin tag - cycle: default -> enabled -> disabled -> defer -> default
        $('.sapm-plugin-tag').on('click', function() {
            if ($(this).closest('.sapm-context-row').length) {
                return;
            }

            var tag = $(this);
            var currentState = tag.data('state');
            var inheritedState = tag.data('inheritedState');
            var newState;

            if (inheritedState) {
                currentState = inheritedState;
                tag.removeClass('inherited');
                tag.removeAttr('data-inherited-state');
                tag.removeData('inheritedState');
            }

            if (currentState === 'default') {
                newState = 'enabled';
            } else if (currentState === 'enabled') {
                newState = 'disabled';
            } else if (currentState === 'disabled') {
                newState = 'defer';
            } else {
                newState = 'default';
            }

            tag.removeClass('enabled disabled defer default').addClass(newState);
            tag.data('state', newState);

            // Update icon
            var icon = tag.find('.dashicons');
            icon.removeClass('dashicons-yes dashicons-no dashicons-clock dashicons-minus');
            if (newState === 'enabled') {
                icon.addClass('dashicons-yes');
            } else if (newState === 'disabled') {
                icon.addClass('dashicons-no');
            } else if (newState === 'defer') {
                icon.addClass('dashicons-clock');
            } else {
                icon.addClass('dashicons-minus');
            }

            updateGroupHeaderStats();

            // Auto-save debounce
            clearTimeout(window.sapmSaveTimeout);
            window.sapmSaveTimeout = setTimeout(saveRules, 1000);
        });

        // Save rules
        function saveRules() {
            var rules = {};

            $('.sapm-screen-row[data-screen-id]').each(function() {
                var screenId = $(this).data('screen-id');
                var screenRules = {};
                var hasRules = false;

                $(this).find('.sapm-plugin-tag').each(function() {
                    var plugin = $(this).data('plugin');
                    var state = $(this).data('state');

                    if (state !== 'default') {
                        screenRules[plugin] = state;
                        hasRules = true;
                    }
                });

                if (hasRules) {
                    rules[screenId] = screenRules;
                }
            });

            $('#sapm-save-status').text(sapmData.strings.saving);

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_save_rules',
                nonce: sapmData.nonce,
                rules: JSON.stringify(rules)
            }, function(response) {
                if (response.success) {
                    $('#sapm-save-status').text(sapmData.strings.saved).fadeOut(2000, function() {
                        $(this).text('').show();
                    });
                } else {
                    $('#sapm-save-status').text(sapmData.strings.error);
                }
            });
        }

        // Filter plugins
        $('#sapm-filter').on('input', function() {
            var filter = $(this).val().toLowerCase();

            $('.sapm-screen-row[data-screen-id] .sapm-plugin-tag').each(function() {
                var name = $(this).text().toLowerCase();
                $(this).toggle(name.indexOf(filter) > -1);
            });
        });

        // Reset all
        $('#sapm-reset-all').on('click', function() {
            if (confirm('Are you sure you want to reset all rules?')) {
                $('.sapm-plugin-tag')
                    .filter(function() {
                        return $(this).closest('.sapm-screen-row[data-screen-id]').length > 0;
                    })
                    .removeClass('enabled disabled defer inherited')
                    .addClass('default')
                    .data('state', 'default')
                    .removeAttr('data-inherited-state')
                    .removeData('inheritedState');
                $('.sapm-screen-row[data-screen-id] .sapm-plugin-tag .dashicons').removeClass('dashicons-yes dashicons-no dashicons-clock').addClass('dashicons-minus');
                updateGroupHeaderStats();
                saveRules();
            }
        });

        updateGroupHeaderStats();

        // ==========================================
        // REQUEST TYPE SETTINGS (AJAX/REST/Cron/CLI)
        // ==========================================

        // Toggle mode - show/hide configuration
        $('.sapm-rt-mode').on('change', function() {
            var type = $(this).data('type');
            var mode = $(this).val();
            var row = $('.sapm-request-type-row[data-request-type="' + type + '"]');

            // Hide both configurations
            row.find('.sapm-rt-blacklist-config').hide();
            row.find('.sapm-rt-whitelist-config').hide();

            // Show relevant configuration
            if (mode === 'blacklist') {
                row.find('.sapm-rt-blacklist-config').slideDown(200);
            } else if (mode === 'whitelist') {
                row.find('.sapm-rt-whitelist-config').slideDown(200);
            }
        });

        // Save request type settings
        $('#sapm-save-request-types').on('click', function() {
            var $btn = $(this);
            var $status = $('#sapm-rt-save-status');
            var rules = {};

            // Collect data for each type (ajax, rest, cron, cli)
            $('.sapm-request-type-row').each(function() {
                var type = $(this).data('request-type');
                var row = $(this);
                var mode = row.find('.sapm-rt-mode:checked').val() || 'passthrough';

                var typeRules = {
                    '_mode': mode,
                    'disabled_plugins': [],
                    'default_plugins': [],
                    '_detect_by_action': false,
                    '_detect_by_namespace': false
                };

                // Blacklist - collect disabled plugins
                if (mode === 'blacklist') {
                    row.find('.sapm-rt-disabled-plugin:checked').each(function() {
                        typeRules.disabled_plugins.push($(this).val());
                    });
                }

                // Whitelist - collect enabled plugins
                if (mode === 'whitelist') {
                    row.find('.sapm-rt-enabled-plugin:checked').each(function() {
                        typeRules.default_plugins.push($(this).val());
                    });

                    // Smart detection
                    var detection = row.find('.sapm-rt-detection').is(':checked');
                    if (type === 'ajax') {
                        typeRules._detect_by_action = detection;
                    } else if (type === 'rest') {
                        typeRules._detect_by_namespace = detection;
                    }
                }

                rules[type] = typeRules;
            });

            // Disable button during saving
            $btn.prop('disabled', true);
            $status.text(sapmData.strings.saving || 'Saving...').css('color', '#0073aa');

            // AJAX request
            $.post(sapmData.ajaxUrl, {
                action: 'sapm_save_request_type_rules',
                nonce: sapmData.nonce,
                rules: JSON.stringify(rules)
            }, function(response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    $status.text(sapmData.strings.saved || 'Saved!').css('color', '#46b450');
                    setTimeout(function() {
                        $status.fadeOut(400, function() {
                            $(this).text('').show();
                        });
                    }, 2000);
                } else {
                    $status.text(response.data || 'Error saving').css('color', '#dc3232');
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                $status.text('AJAX error').css('color', '#dc3232');
            });
        });

        // ==========================================
        // REQUEST TYPE PERFORMANCE SAMPLING
        // ==========================================

        // Load performance data
        $('#sapm-load-rt-performance').on('click', function() {
            var $btn = $(this);
            var $container = $('#sapm-rt-performance-container');

            $btn.prop('disabled', true).find('.dashicons').addClass('spin');

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_get_request_type_performance',
                nonce: sapmData.nonce
            }, function(response) {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');

                if (response.success && response.data) {
                    renderPerformanceData(response.data);
                    $container.slideDown(300);
                } else {
                    alert(response.data || 'Error loading data');
                }
            }).fail(function() {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                alert('AJAX error');
            });
        });

        // Delete all performance data
        $('#sapm-clear-rt-performance').on('click', function() {
            if (!confirm('Are you sure you want to delete all measured performance data?')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_clear_request_type_performance',
                nonce: sapmData.nonce,
                type: 'all'
            }, function(response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    $('#sapm-rt-performance-container').html('<p style="color: #46b450;">Data have been deleted..</p>');
                } else {
                    alert(response.data || 'Error deleting');
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                alert('AJAX error');
            });
        });

        // Render performance data
        function renderPerformanceData(data) {
            var $container = $('#sapm-rt-performance-container');
            var typeLabels = { ajax: 'AJAX', rest: 'REST API', cron: 'WP-Cron', cli: 'WP-CLI' };
            var hasAnyData = false;
            var html = '<div class="sapm-perf-container">';

            for (var type in data) {
                var triggers = data[type];
                var triggerCount = Object.keys(triggers).length;
                if (triggerCount === 0) { continue; }
                hasAnyData = true;

                html += '<div class="sapm-perf-type">';
                html += '<div class="sapm-perf-type-header">';
                html += '<span class="dashicons dashicons-arrow-down-alt2 sapm-perf-type-arrow"></span>';
                html += '<span class="sapm-perf-type-label">' + (typeLabels[type] || type.toUpperCase()) + '</span>';
                html += '<span class="sapm-perf-type-count">' + triggerCount + ' triggers</span>';
                html += '</div>';
                html += '<div class="sapm-perf-type-body" style="display:none;">';

                var sortedTriggers = Object.keys(triggers).sort(function(a, b) {
                    return triggers[b].samples - triggers[a].samples;
                });

                for (var i = 0; i < sortedTriggers.length; i++) {
                    var trigger = sortedTriggers[i];
                    var tdata = triggers[trigger];

                    html += '<div class="sapm-perf-trigger">';
                    html += '<div class="sapm-perf-trigger-header">';
                    html += '<span class="dashicons dashicons-arrow-down-alt2 sapm-perf-trigger-arrow"></span>';
                    html += '<span class="sapm-perf-trigger-name">' + escapeHtml(trigger) + '</span>';
                    html += '<span class="sapm-perf-trigger-meta">' + tdata.samples + ' samples &middot; ' + tdata.avg_ms.toFixed(1) + ' ms &middot; ' + tdata.avg_queries.toFixed(0) + ' q</span>';
                    html += '</div>';

                    if (tdata.plugins && tdata.plugins.length > 0) {
                        html += '<div class="sapm-perf-table-wrap" style="display:none;">';
                        html += '<table class="sapm-perf-table">';
                        html += '<thead><tr><th>Plugin</th><th>Avg ms</th><th>Avg q</th><th>Samples</th></tr></thead><tbody>';

                        tdata.plugins.sort(function(a, b) { return b.avg_ms - a.avg_ms; });

                        for (var j = 0; j < tdata.plugins.length; j++) {
                            var plugin = tdata.plugins[j];
                            var rowClass = plugin.avg_ms > 50 ? ' class="perf-danger"' : (plugin.avg_ms > 20 ? ' class="perf-warn"' : '');
                            html += '<tr' + rowClass + '>';
                            html += '<td>' + escapeHtml(plugin.name) + '</td>';
                            html += '<td>' + plugin.avg_ms.toFixed(1) + '</td>';
                            html += '<td>' + plugin.avg_queries.toFixed(1) + '</td>';
                            html += '<td>' + plugin.samples + '</td>';
                            html += '</tr>';
                        }

                        html += '</tbody></table></div>';
                    }

                    html += '</div>'; // trigger
                }

                html += '</div>'; // type-body
                html += '</div>'; // type
            }

            html += '</div>'; // container

            if (!hasAnyData) {
                html = '<p class="sapm-perf-no-data">No data collected yet. Sampling runs automatically for 5% of requests.</p>';
            }

            $container.html(html);

            // Type-level toggle (starts collapsed)
            $container.find('.sapm-perf-type-header').on('click', function() {
                var $hdr = $(this);
                $hdr.toggleClass('open');
                $hdr.next('.sapm-perf-type-body').slideToggle(180);
            });

            // Trigger-level toggle (starts collapsed)
            $container.find('.sapm-perf-trigger-header').on('click', function() {
                var $hdr = $(this);
                $hdr.toggleClass('open');
                $hdr.next('.sapm-perf-table-wrap').slideToggle(150);
            });
        }

        // Helper: escape HTML
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ==========================================
        // MODE SWITCHER (Manual / Auto)
        // ==========================================

        // Mode switcher
        $('input[name="sapm_mode"]').on('change', function() {
            var newMode = $(this).val();
            var $status = $('#sapm-mode-status');
            var $suggestionsBox = $('#sapm-auto-suggestions');

            $status.text(sapmData.strings.saving).css('color', '#0073aa');

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_set_mode',
                nonce: sapmData.nonce,
                mode: newMode
            }, function(response) {
                if (response.success) {
                    $status.text(sapmData.strings.modeChanged).css('color', '#46b450');
                    setTimeout(function() {
                        $status.fadeOut(400, function() {
                            $(this).text('').show();
                        });
                    }, 2000);

                    // Show/hide auto suggestions box
                    if (newMode === 'auto') {
                        $suggestionsBox.slideDown(300);
                        loadAutoSuggestions();
                    } else {
                        $suggestionsBox.slideUp(300);
                    }
                } else {
                    $status.text(sapmData.strings.modeError).css('color', '#dc3232');
                }
            }).fail(function() {
                $status.text('AJAX error').css('color', '#dc3232');
            });
        });

        // Load auto suggestions
        function loadAutoSuggestions() {
            var $content = $('#sapm-suggestions-content');
            renderTextMessage($content, sapmData.strings.loading, '#666');

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_get_auto_suggestions',
                nonce: sapmData.nonce
            }, function(response) {
                if (response.success && response.data && response.data.suggestions) {
                    renderAutoSuggestions(response.data.suggestions);
                } else {
                    var errMsg = (response && response.data && typeof response.data.message === 'string')
                        ? response.data.message
                        : sapmData.strings.error;
                    renderTextMessage($content, errMsg, '#dc3232');
                }
            }).fail(function() {
                renderTextMessage($content, 'AJAX error', '#dc3232');
            });
        }

        // Render auto suggestions
        function renderAutoSuggestions(data) {
            var $content = $('#sapm-suggestions-content');
            var html = '';

            var typeLabels = {
                ajax: 'AJAX',
                rest: 'REST API',
                cron: 'WP-Cron',
                cli: 'WP-CLI'
            };

            var hasAnySuggestion = false;

            // Section 1: Request Type suggestions (AJAX/REST/Cron/CLI)
            html += '<div class="sapm-suggestion-section">';
            html += '<h4 class="sapm-suggestion-heading is-request">';
            html += '<span class="dashicons dashicons-rest-api"></span>';
            html += 'Suggestions for request types (AJAX/REST/Cron/CLI)';
            html += '</h4>';
            html += '<p class="sapm-suggestion-intro">';
            html += '<strong>Explanation:</strong> These rules affect plugin loading during AJAX requests, REST API calls, WP-Cron tasks, and WP-CLI commands. ';
            html += 'They apply <strong>globally</strong> to all requests of a given type.';
            html += '</p>';

            var requestTypeHtml = '';
            for (var type in data) {
                if (type === 'admin_screens') continue; // Handle separately
                
                var typeData = data[type];
                var blocks = typeData.suggested_blocks || [];
                var whitelist = typeData.suggested_whitelist || [];

                if (blocks.length === 0 && whitelist.length === 0) {
                    continue;
                }

                hasAnySuggestion = true;

                requestTypeHtml += '<div class="sapm-suggestion-type">';
                requestTypeHtml += '<div class="sapm-suggestion-type-head"><strong>' + typeLabels[type] + '</strong></div>';

                if (blocks.length > 0) {
                    requestTypeHtml += '<div class="sapm-suggestion-list-wrap is-block"><span class="sapm-suggestion-list-title">' + sapmData.strings.suggestBlock + ':</span><ul class="sapm-suggestion-list">';
                    for (var i = 0; i < blocks.length; i++) {
                        var b = blocks[i];
                        requestTypeHtml += '<li><label class="sapm-suggestion-item-row">';
                        requestTypeHtml += '<input type="checkbox" class="sapm-suggestion-item" data-type="' + type + '" data-action="block" data-plugin="' + escapeHtml(b.plugin) + '" checked> ';
                        requestTypeHtml += '<span class="check-box sapm-suggestion-check" aria-hidden="true"></span>';
                        requestTypeHtml += '<span class="sapm-suggestion-plugin">' + escapeHtml(b.plugin) + '</span>';
                        if (typeof b.savings_ms === 'number' && isFinite(b.savings_ms) && b.savings_ms > 0) {
                            requestTypeHtml += '<span class="sapm-suggestion-pill is-savings">savings: ' + b.savings_ms.toFixed(1) + 'ms</span>';
                        }
                        if (typeof b.confidence === 'number' && isFinite(b.confidence) && b.confidence > 0) {
                            requestTypeHtml += '<span class="sapm-suggestion-pill is-confidence">' + sapmData.strings.confidence + ': ' + (b.confidence * 100).toFixed(0) + '%</span>';
                        }
                        requestTypeHtml += '</label></li>';
                    }
                    requestTypeHtml += '</ul></div>';
                }

                if (whitelist.length > 0) {
                    requestTypeHtml += '<div class="sapm-suggestion-list-wrap is-whitelist"><span class="sapm-suggestion-list-title">' + sapmData.strings.suggestWhitelist + ':</span><ul class="sapm-suggestion-list">';
                    for (var j = 0; j < whitelist.length; j++) {
                        var w = whitelist[j];
                        requestTypeHtml += '<li><label class="sapm-suggestion-item-row">';
                        requestTypeHtml += '<input type="checkbox" class="sapm-suggestion-item" data-type="' + type + '" data-action="whitelist" data-plugin="' + escapeHtml(w.plugin) + '" checked> ';
                        requestTypeHtml += '<span class="check-box sapm-suggestion-check" aria-hidden="true"></span>';
                        requestTypeHtml += '<span class="sapm-suggestion-plugin">' + escapeHtml(w.plugin) + '</span>';
                        if (typeof w.confidence === 'number' && isFinite(w.confidence) && w.confidence > 0) {
                            requestTypeHtml += '<span class="sapm-suggestion-pill is-confidence">' + sapmData.strings.confidence + ': ' + (w.confidence * 100).toFixed(0) + '%</span>';
                        }
                        requestTypeHtml += '</label></li>';
                    }
                    requestTypeHtml += '</ul></div>';
                }

                requestTypeHtml += '</div>';
            }

            if (requestTypeHtml === '') {
                html += '<p class="sapm-suggestion-empty">No suggestions available yet. Collecting data from AJAX/REST/Cron requests.</p>';
            } else {
                html += requestTypeHtml;
            }
            html += '</div>';

            // Section 2: Per-Screen suggestions (Admin screens)
            html += '<div class="sapm-suggestion-section">';
            html += '<h4 class="sapm-suggestion-heading is-screen">';
            html += '<span class="dashicons dashicons-admin-generic"></span>';
            html += 'Suggestions for individual admin screens';
            html += '</h4>';
            html += '<p class="sapm-suggestion-intro">';
            html += '<strong>Explanation:</strong> These rules allow you to block or defer plugin loading on <strong>specific admin screens</strong> in WordPress. ';
            html += 'For example, you can block an SEO plugin on the media screen because it is not needed there.';
            html += '</p>';

            var adminScreens = data.admin_screens || [];
            var renderedScreens = 0;
            if (adminScreens.length > 0) {
                var totalSavings = 0;
                for (var s = 0; s < adminScreens.length; s++) {
                    var screen = adminScreens[s];
                    var screenBlocks = screen.suggested_blocks || [];
                    var screenDefer = screen.suggested_defer || [];
                    
                    for (var sb = 0; sb < screenBlocks.length; sb++) {
                        var blockSaving = parseFloat(screenBlocks[sb].savings_ms);
                        if (isFinite(blockSaving) && blockSaving > 0) {
                            totalSavings += blockSaving;
                        }
                    }
                    for (var sd = 0; sd < screenDefer.length; sd++) {
                        var deferSaving = parseFloat(screenDefer[sd].savings_ms);
                        if (isFinite(deferSaving) && deferSaving > 0) {
                            totalSavings += deferSaving;
                        }
                    }
                }
                
                if (totalSavings > 0) {
                    html += '<div class="sapm-suggestion-total">';
                    html += '<strong>Total potential savings: ' + totalSavings.toFixed(1) + 'ms</strong>';
                    html += '</div>';
                }
                
                for (var k = 0; k < adminScreens.length; k++) {
                    var screenData = adminScreens[k];
                    var blocks = screenData.suggested_blocks || [];
                    var defer = screenData.suggested_defer || [];
                    var screenId = screenData.screen || '';
                    var screenLabel = screenData.screen_label || screenId;
                    var totalLoadMs = (typeof screenData.total_load_ms === 'number' && isFinite(screenData.total_load_ms))
                        ? screenData.total_load_ms
                        : 0;

                    if (blocks.length === 0 && defer.length === 0) {
                        continue;
                    }
                    renderedScreens += 1;
                    hasAnySuggestion = true;
                    
                    html += '<div class="sapm-suggestion-type">';
                    html += '<div class="sapm-suggestion-type-head">';
                    html += '<strong>' + escapeHtml(screenLabel) + '</strong>';
                    html += '<code class="sapm-suggestion-screen-code">' + escapeHtml(screenId) + '</code>';
                    html += '<span class="sapm-suggestion-screen-meta">total load: ' + totalLoadMs.toFixed(1) + 'ms</span>';
                    html += '</div>';
                    
                    if (blocks.length > 0) {
                        html += '<div class="sapm-suggestion-list-wrap is-block"><span class="sapm-suggestion-list-title">Block:</span><ul class="sapm-suggestion-list">';
                        for (var m = 0; m < blocks.length; m++) {
                            var blk = blocks[m];
                            html += '<li><label class="sapm-suggestion-item-row">';
                            html += '<input type="checkbox" class="sapm-suggestion-item" data-type="screen" data-screen="' + escapeHtml(screenId) + '" data-action="block" data-plugin="' + escapeHtml(blk.plugin) + '" checked> ';
                            html += '<span class="check-box sapm-suggestion-check" aria-hidden="true"></span>';
                            html += '<span class="sapm-suggestion-plugin">' + escapeHtml(blk.plugin) + '</span>';
                            if (typeof blk.savings_ms === 'number' && isFinite(blk.savings_ms) && blk.savings_ms > 0) {
                                html += '<span class="sapm-suggestion-pill is-savings">savings: ' + blk.savings_ms.toFixed(1) + 'ms</span>';
                            }
                            if (typeof blk.confidence === 'number' && isFinite(blk.confidence) && blk.confidence > 0) {
                                html += '<span class="sapm-suggestion-pill is-confidence">' + sapmData.strings.confidence + ': ' + (blk.confidence * 100).toFixed(0) + '%</span>';
                            }
                            html += '</label></li>';
                        }
                        html += '</ul></div>';
                    }
                    
                    if (defer.length > 0) {
                        html += '<div class="sapm-suggestion-list-wrap is-defer"><span class="sapm-suggestion-list-title">Defer:</span><ul class="sapm-suggestion-list">';
                        for (var n = 0; n < defer.length; n++) {
                            var def = defer[n];
                            html += '<li><label class="sapm-suggestion-item-row">';
                            html += '<input type="checkbox" class="sapm-suggestion-item" data-type="screen" data-screen="' + escapeHtml(screenId) + '" data-action="defer" data-plugin="' + escapeHtml(def.plugin) + '" checked> ';
                            html += '<span class="check-box sapm-suggestion-check" aria-hidden="true"></span>';
                            html += '<span class="sapm-suggestion-plugin">' + escapeHtml(def.plugin) + '</span>';
                            if (typeof def.savings_ms === 'number' && isFinite(def.savings_ms) && def.savings_ms > 0) {
                                html += '<span class="sapm-suggestion-pill is-savings">savings: ' + def.savings_ms.toFixed(1) + 'ms</span>';
                            }
                            if (typeof def.confidence === 'number' && isFinite(def.confidence) && def.confidence > 0) {
                                html += '<span class="sapm-suggestion-pill is-confidence">' + sapmData.strings.confidence + ': ' + (def.confidence * 100).toFixed(0) + '%</span>';
                            }
                            html += '</label></li>';
                        }
                        html += '</ul></div>';
                    }
                    
                    html += '</div>';
                }

                if (renderedScreens === 0) {
                    html += '<p class="sapm-suggestion-empty">No suggestions available for individual screens yet. Browse different admin pages to collect data.</p>';
                }
            } else {
                html += '<p class="sapm-suggestion-empty">No suggestions available for individual screens yet. Browse different admin pages to collect data.</p>';
            }
            html += '</div>';

            if (!hasAnySuggestion) {
                html = '<div class="sapm-suggestion-empty-state">';
                html += '<span class="dashicons dashicons-chart-area"></span>';
                html += '<p>' + sapmData.strings.noSuggestions + '</p>';
                html += '<small>Browse WordPress admin to collect data.</small>';
                html += '</div>';
            }

            $content.html(html);
        }

        // Refresh suggestions
        $('#sapm-refresh-suggestions').on('click', function() {
            loadAutoSuggestions();
        });

        // Reset Auto data (sampling + rules)
        $('#sapm-reset-auto-data').on('click', function() {
            if (!confirm('Are you sure you want to delete all collected data and reset Auto rules to default values?')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_reset_auto_data',
                nonce: sapmData.nonce
            }, function(response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    alert('Auto data has been reset. Page will reload.');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data?.message || 'Unknown error'));
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                alert('AJAX error');
            });
        });

        // Apply suggestions
        $('#sapm-apply-suggestions').on('click', function() {
            var $btn = $(this);
            var suggestions = [];

            $('.sapm-suggestion-item:checked').each(function() {
                var suggestion = {
                    type: $(this).data('type'),
                    action: $(this).data('action'),
                    plugin: $(this).data('plugin')
                };
                // Add screen ID for screen-based suggestions
                if ($(this).data('screen')) {
                    suggestion.screen = $(this).data('screen');
                }
                suggestions.push(suggestion);
            });

            if (suggestions.length === 0) {
                alert('No suggestions selected to apply.');
                return;
            }

            $btn.prop('disabled', true);

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_apply_auto_rules',
                nonce: sapmData.nonce,
                suggestions: JSON.stringify(suggestions)
            }, function(response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    alert('Suggestions have been applied! Page will reload.');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                alert('AJAX error');
            });
        });

        // If mode is 'auto', show suggestions box on load
        if (sapmData.currentMode === 'auto') {
            $('#sapm-auto-suggestions').show();
            loadAutoSuggestions();
        }

        // ========================================
        // FRONTEND OPTIMIZATION
        // ========================================

        function setFrontendContextTagState($tag, state) {
            var nextState = (state === 'enabled' || state === 'disabled') ? state : 'default';
            $tag.removeClass('enabled disabled default').addClass(nextState);
            $tag.data('state', nextState);

            var $icon = $tag.find('.dashicons');
            if ($icon.length) {
                $icon.removeClass('dashicons-yes dashicons-no dashicons-minus')
                    .addClass(nextState === 'enabled' ? 'dashicons-yes' : (nextState === 'disabled' ? 'dashicons-no' : 'dashicons-minus'));
            }
        }

        function updateFrontendContextModeUI($row) {
            if (!$row || !$row.length) {
                return;
            }

            var mode = $row.find('.sapm-context-mode-radio:checked').val() || 'passthrough';
            var $checkedOption = $row.find('.sapm-context-mode-radio:checked').closest('.sapm-rt-radio-option');
            var modeLabel = $checkedOption.find('.sapm-rt-radio-content strong').first().text() || 'Passthrough';

            $row.find('.sapm-rt-radio-option').removeClass('selected');
            $checkedOption.addClass('selected');

            var $pill = $row.find('.sapm-mode-pill').first();
            if ($pill.length) {
                $pill.removeClass('mode-passthrough mode-blacklist mode-whitelist')
                    .addClass('mode-' + mode)
                    .text(modeLabel);
            }

            $row.find('.sapm-context-config-blacklist').toggle(mode === 'blacklist');
            $row.find('.sapm-context-config-whitelist').toggle(mode === 'whitelist');
        }

        function updateFrontendGroupHeaderStats() {
            $('#sapm-fe-request-types .sapm-fe-context-group').each(function() {
                var $group = $(this);
                var $rows = $group.find('> .sapm-group-content .sapm-context-row[data-context]');

                if (!$rows.length) {
                    return;
                }

                var counts = {
                    enabled: 0,
                    disabled: 0,
                    defer: 0
                };

                $rows.find('.sapm-context-plugins .sapm-plugin-tag').each(function() {
                    var state = $(this).data('state');
                    if (state === 'enabled') {
                        counts.enabled += 1;
                    } else if (state === 'disabled') {
                        counts.disabled += 1;
                    }
                });

                var $headerStats = $group.find('> .sapm-group-header .sapm-group-header-stats').first();
                if ($headerStats.length) {
                    renderGroupStats($headerStats, counts);
                }
            });
        }

        function syncFrontendRuleRowState($row) {
            if (!$row || !$row.length) {
                return;
            }

            var mode = $row.find('.sapm-context-mode-radio:checked').val() || 'passthrough';
            $row.attr('data-mode', mode);

            $row.find('.sapm-context-plugins .sapm-plugin-tag').each(function() {
                var $tag = $(this);
                var currentState = $tag.data('state') || 'default';
                var isActive = currentState !== 'default';

                if (mode === 'passthrough') {
                    setFrontendContextTagState($tag, 'default');
                    return;
                }

                var activeState = mode === 'blacklist' ? 'disabled' : 'enabled';
                setFrontendContextTagState($tag, isActive ? activeState : 'default');
            });

            updateFrontendContextModeUI($row);
        }

        function syncFrontendRuleStates() {
            $('#sapm-fe-request-types .sapm-context-row').each(function() {
                syncFrontendRuleRowState($(this));
            });
            updateFrontendGroupHeaderStats();
        }

        // Toggle context plugins visibility based on mode radio
        $(document).on('change', '#sapm-fe-request-types .sapm-context-mode-radio', function() {
            var $row = $(this).closest('.sapm-context-row');
            var mode = $(this).val();

            $row.attr('data-mode', mode);

            if (mode === 'passthrough') {
                $row.find('.sapm-context-plugins').slideUp(200);
            } else {
                $row.find('.sapm-context-plugins').slideDown(200);
            }

            syncFrontendRuleRowState($row);
            updateFrontendGroupHeaderStats();
        });

        $(document).on('click', '#sapm-fe-request-types .sapm-context-row .sapm-context-plugins .sapm-plugin-tag', function(e) {
            e.preventDefault();

            var $tag = $(this);
            var $row = $tag.closest('.sapm-context-row');
            var mode = $row.find('.sapm-context-mode-radio:checked').val() || 'passthrough';

            if (mode === 'passthrough') {
                return;
            }

            var currentState = $tag.data('state') || 'default';
            var nextState = currentState === 'default'
                ? (mode === 'blacklist' ? 'disabled' : 'enabled')
                : 'default';

            setFrontendContextTagState($tag, nextState);
            updateFrontendGroupHeaderStats();
        });

        syncFrontendRuleStates();

        // Save frontend settings
        $('#sapm-fe-save-settings').on('click', function() {
            var $btn = $(this);
            var $status = $('#sapm-fe-settings-status');
            var defaultHtml = $btn.data('defaultHtml') || $btn.html();
            $btn.data('defaultHtml', defaultHtml);

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>' + (sapmData.strings.saving || 'Saving...'));
            $status.text(sapmData.strings.saving || 'Saving...').css('color', '#0073aa');

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_save_frontend_settings',
                nonce: sapmData.nonce,
                enabled: $('#sapm-fe-enabled').is(':checked') ? 1 : 0,
                admin_bypass: $('#sapm-fe-admin-bypass').is(':checked') ? 1 : 0,
                wc_protection: $('#sapm-fe-wc-protection').is(':checked') ? 1 : 0,
                sampling_enabled: $('#sapm-fe-sampling').is(':checked') ? 1 : 0,
                asset_audit: $('#sapm-fe-asset-audit').is(':checked') ? 1 : 0
            }, function(response) {
                $btn.prop('disabled', false).html(defaultHtml);
                if (response.success) {
                    $status.text(sapmData.strings.saved || 'Saved!').css('color', '#46b450');
                    setTimeout(function() {
                        $status.fadeOut(400, function() {
                            $(this).text('').show();
                        });
                    }, 2000);
                } else {
                    $status.text((response && response.data && response.data.message) ? response.data.message : (sapmData.strings.error || 'Error')).css('color', '#dc3232');
                }
            }).fail(function() {
                $btn.prop('disabled', false).html(defaultHtml);
                $status.text('AJAX error').css('color', '#dc3232');
            });
        });

        // Save frontend plugin rules
        $('#sapm-fe-save-rules').on('click', function() {
            var $btn = $(this);
            var $status = $('#sapm-fe-rules-status');
            var defaultHtml = $btn.data('defaultHtml') || $btn.html();
            $btn.data('defaultHtml', defaultHtml);

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>' + (sapmData.strings.saving || 'Saving...'));
            $status.text(sapmData.strings.saving || 'Saving...').css('color', '#0073aa');

            var rules = {};
            $('#sapm-fe-request-types .sapm-context-row').each(function() {
                var contextId = $(this).data('context');
                var mode = $(this).find('.sapm-context-mode-radio:checked').val() || 'passthrough';
                var disabledPlugins = [];
                var enabledPlugins = [];

                if (mode === 'blacklist') {
                    $(this).find('.sapm-context-plugins .sapm-plugin-tag.disabled').each(function() {
                        disabledPlugins.push($(this).data('plugin'));
                    });
                } else if (mode === 'whitelist') {
                    $(this).find('.sapm-context-plugins .sapm-plugin-tag.enabled').each(function() {
                        enabledPlugins.push($(this).data('plugin'));
                    });
                }

                rules[contextId] = {
                    '_mode': mode,
                    'disabled_plugins': disabledPlugins,
                    'enabled_plugins': enabledPlugins
                };
            });

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_save_frontend_rules',
                nonce: sapmData.nonce,
                rules: JSON.stringify(rules)
            }, function(response) {
                $btn.prop('disabled', false).html(defaultHtml);
                if (response.success) {
                    $status.text(sapmData.strings.saved || 'Saved!').css('color', '#46b450');
                    setTimeout(function() {
                        $status.fadeOut(400, function() {
                            $(this).text('').show();
                        });
                    }, 2000);
                } else {
                    $status.text((response && response.data && response.data.message) ? response.data.message : (sapmData.strings.error || 'Error')).css('color', '#dc3232');
                }
            }).fail(function() {
                $btn.prop('disabled', false).html(defaultHtml);
                $status.text('AJAX error').css('color', '#dc3232');
            });
        });

        // Load asset audit data
        $('#sapm-fe-load-audit').on('click', function() {
            var $btn = $(this);
            var $content = $('#sapm-fe-audit-content');
            var defaultHtml = $btn.data('defaultHtml') || $btn.html();
            $btn.data('defaultHtml', defaultHtml);
            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>' + (sapmData.strings.loading || 'Loading...'));

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_get_frontend_asset_audit',
                nonce: sapmData.nonce
            }, function(response) {
                $btn.prop('disabled', false).html(defaultHtml);
                if (response.success && response.data.audit) {
                    var html = '';
                    var audit = response.data.audit;

                    if (Object.keys(audit).length === 0) {
                        html = '<p style="color:#666;">No audit data yet. Enable Asset Audit and visit some frontend pages first.</p>';
                    } else {
                        for (var context in audit) {
                            if (!audit.hasOwnProperty(context)) continue;
                            var ctxData = audit[context];
                            html += '<div class="sapm-asset-audit-context">';
                            html += '<h5>' + escapeHtml(context) + ' <small style="color:#999;">' + escapeHtml(ctxData.url || '') + '</small></h5>';

                            // Scripts table
                            if (ctxData.scripts && Object.keys(ctxData.scripts).length > 0) {
                                html += '<table class="sapm-asset-audit-table">';
                                html += '<thead><tr><th>Type</th><th>Handle</th><th>Source</th><th>Plugin</th></tr></thead><tbody>';
                                for (var handle in ctxData.scripts) {
                                    if (!ctxData.scripts.hasOwnProperty(handle)) continue;
                                    var s = ctxData.scripts[handle];
                                    var pluginTag = getAssetPluginTag(s.plugin);
                                    html += '<tr>';
                                    html += '<td><span class="sapm-asset-tag script">JS</span></td>';
                                    html += '<td><code>' + escapeHtml(handle) + '</code></td>';
                                    html += '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(s.src) + '">' + escapeHtml(s.src) + '</td>';
                                    html += '<td>' + pluginTag + '</td>';
                                    html += '</tr>';
                                }
                                html += '</tbody></table>';
                            }

                            // Styles table
                            if (ctxData.styles && Object.keys(ctxData.styles).length > 0) {
                                html += '<table class="sapm-asset-audit-table">';
                                html += '<thead><tr><th>Type</th><th>Handle</th><th>Source</th><th>Plugin</th></tr></thead><tbody>';
                                for (var handle2 in ctxData.styles) {
                                    if (!ctxData.styles.hasOwnProperty(handle2)) continue;
                                    var st = ctxData.styles[handle2];
                                    var pluginTag2 = getAssetPluginTag(st.plugin);
                                    html += '<tr>';
                                    html += '<td><span class="sapm-asset-tag style">CSS</span></td>';
                                    html += '<td><code>' + escapeHtml(handle2) + '</code></td>';
                                    html += '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escapeHtml(st.src) + '">' + escapeHtml(st.src) + '</td>';
                                    html += '<td>' + pluginTag2 + '</td>';
                                    html += '</tr>';
                                }
                                html += '</tbody></table>';
                            }

                            html += '</div>';
                        }
                    }

                    $content.html(html);
                } else {
                    renderTextMessage($content, 'Error loading audit data.', '#dc3232');
                }
            }).fail(function() {
                $btn.prop('disabled', false).html(defaultHtml);
                renderTextMessage($content, 'AJAX error.', '#dc3232');
            });
        });

        // Helper: escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        // Helper: get plugin tag HTML
        function getAssetPluginTag(plugin) {
            if (!plugin) return '<span style="color:#999;">—</span>';
            if (plugin === '_theme') return '<span class="sapm-asset-tag theme">Theme</span>';
            if (plugin === '_core') return '<span class="sapm-asset-tag core">WP Core</span>';
            return '<span class="sapm-asset-tag plugin">' + escapeHtml(plugin) + '</span>';
        }
    });
})(jQuery);
