<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ============================================================
    // SETTINGS MANAGEMENT
    // ============================================================

    function formatAiCents(cents) {
        return ((parseInt(cents || 0, 10) / 100).toFixed(2)) + ' EUR';
    }

    function updateAiUsage(usage) {
        var el = document.getElementById('aiUsageSummary');
        if (!AI_FEATURES_ENABLED || !aiServiceIsUsable(currentAiSettings || {})) {
            if (el) el.hidden = true;
            renderAiUsagePanel(null);
            return;
        }
        if (!el || !usage) return;
        el.hidden = false;
        var today = usage.today || {};
        var month = usage.month || {};
        el.textContent = t('ai.usage_summary', {
            today: today.requests || 0,
            cost: formatAiCents(month.estimatedCostCents || 0)
        });
        renderAiUsagePanel(usage);
    }

    function aiUsageStatTile(value, label, sub) {
        var tile = document.createElement('div');
        tile.className = 'ai-usage-stat';
        var valueEl = document.createElement('span');
        valueEl.className = 'ai-usage-stat__value';
        valueEl.textContent = value;
        var labelEl = document.createElement('span');
        labelEl.className = 'ai-usage-stat__label';
        labelEl.textContent = label;
        tile.appendChild(valueEl);
        tile.appendChild(labelEl);
        if (sub) {
            var subEl = document.createElement('span');
            subEl.className = 'ai-usage-stat__sub';
            subEl.textContent = sub;
            tile.appendChild(subEl);
        }
        return tile;
    }

    function renderAiUsagePanel(usage) {
        var panel = document.getElementById('aiUsagePanel');
        var body = document.getElementById('aiUsagePanelBody');
        if (!panel || !body) return;
        if (!usage) {
            panel.hidden = true;
            return;
        }
        panel.hidden = false;
        var today = usage.today || {};
        var month = usage.month || {};
        var budgetCents = parseInt((currentAiSettings && currentAiSettings.limits && currentAiSettings.limits.monthlyBudgetCents) || 0, 10);

        body.innerHTML = '';
        var grid = document.createElement('div');
        grid.className = 'ai-usage-grid';
        grid.appendChild(aiUsageStatTile(
            String(today.requests || 0),
            t('ai.usage_requests_today'),
            t('ai.usage_text_image_split', { text: today.textRequests || 0, images: today.imageRequests || 0 })
        ));
        grid.appendChild(aiUsageStatTile(
            formatAiCents(month.estimatedCostCents || 0),
            t('ai.usage_cost_month'),
            t('ai.usage_requests_month', { requests: month.requests || 0 })
        ));
        grid.appendChild(aiUsageStatTile(
            ((month.inputTokens || 0) + (month.outputTokens || 0)).toLocaleString(),
            t('ai.usage_tokens_month'),
            t('ai.usage_tokens_split', {
                input: (month.inputTokens || 0).toLocaleString(),
                output: (month.outputTokens || 0).toLocaleString()
            })
        ));
        const reservations = usage.reservations || {};
        grid.appendChild(aiUsageStatTile(formatAiCents(reservations.reservedCents || 0), t('ai.reserved'), t('ai.pending_requests', { count: reservations.pendingRequests || 0 })));
        body.appendChild(grid);
        const budgetHint = document.createElement('p');
        budgetHint.className = 'form-hint'; budgetHint.textContent = t('ai.budget_explanation');
        body.appendChild(budgetHint);

        if (budgetCents > 0) {
            var spent = (month.estimatedCostCents || 0) + (reservations.reservedCents || 0);
            var ratio = spent / budgetCents;
            var budget = document.createElement('div');
            budget.className = 'ai-usage-budget';
            var header = document.createElement('div');
            header.className = 'ai-usage-budget__header';
            var label = document.createElement('span');
            label.className = 'ai-usage-budget__label';
            label.textContent = t('ai.usage_budget');
            var value = document.createElement('span');
            value.className = 'ai-usage-budget__value';
            value.textContent = t('ai.usage_budget_value', {
                spent: formatAiCents(spent),
                budget: formatAiCents(budgetCents),
                percent: Math.min(999, Math.round(ratio * 100))
            });
            header.appendChild(label);
            header.appendChild(value);
            var bar = document.createElement('div');
            bar.className = 'ai-usage-budget__bar';
            var fill = document.createElement('div');
            fill.className = 'ai-usage-budget__fill'
                + (ratio >= 1 ? ' ai-usage-budget__fill--over' : (ratio >= 0.8 ? ' ai-usage-budget__fill--warn' : ''));
            fill.style.width = Math.min(100, Math.max(ratio > 0 ? 2 : 0, Math.round(ratio * 100))) + '%';
            bar.appendChild(fill);
            budget.appendChild(header);
            budget.appendChild(bar);
            body.appendChild(budget);
        }
    }

    function switchAiToolTab(tool) {
        var requestedTool = ['image', 'text', 'audit'].indexOf(tool) !== -1 ? tool : 'text';
        var availableTabs = Array.from(document.querySelectorAll('.ai-tool-tab')).filter(function(tab) {
            return !tab.hidden;
        });
        if (!availableTabs.length) return;
        var activeTool = availableTabs.some(function(tab) {
            return tab.dataset.aiToolTab === requestedTool;
        }) ? requestedTool : availableTabs[0].dataset.aiToolTab;
        document.querySelectorAll('.ai-tool-tab').forEach(function(tab) {
            var isActive = tab.dataset.aiToolTab === activeTool;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        document.querySelectorAll('.ai-tool-panel').forEach(function(panel) {
            panel.hidden = panel.dataset.aiToolPanel !== activeTool;
            panel.classList.toggle('active', panel.dataset.aiToolPanel === activeTool);
        });
        // Opening the image tool under OpenRouter loads live image prices so
        // the estimated per-image cost can be shown.
        if (activeTool === 'image' && aiSavedProviderKey() === 'openrouter') {
            loadOpenRouterModels();
        }
    }

    function openAiImageGenerator(promptText, aspectRatio) {
        if (!dashboardAiImageUsable) {
            showToast(t('ai.image_generator_disabled'), 'warning');
            return;
        }
        switchTab('home');
        switchAiToolTab('image');
        var prompt = document.getElementById('aiImagePrompt');
        if (prompt && promptText) {
            prompt.value = promptText;
        }
        if (aspectRatio) {
            setAiImageRatio(aspectRatio);
        }
        if (prompt) {
            prompt.focus();
        }
    }

    function setAiImageRatio(aspectRatio) {
        var ratioInput = document.getElementById('aiImageRatio');
        if (ratioInput) ratioInput.value = aspectRatio || 'auto';
        if (typeof updateAiImageRatioIcon === 'function') {
            updateAiImageRatioIcon();
        }
    }

    function getNewsImagePrompt() {
        var title = document.getElementById('newsTitle')?.value.trim() || '';
        var excerpt = document.getElementById('newsExcerpt')?.value.trim() || '';
        var content = document.getElementById('newsContentWysiwyg')?.innerText.trim() || '';
        var language = document.getElementById('newsLang')?.value.trim() || '';
        var parts = [title, excerpt, content].filter(Boolean).join('\n\n').slice(0, 900);
        return [
            'Create a 16:9 editorial cover image for a website news post.',
            title ? 'Article title: ' + title : '',
            language ? 'Content language: ' + language : '',
            parts ? 'Context:\n' + parts : '',
            'Use the article context to choose a fitting subject, mood, and setting.',
            'No text, no logo, no UI, no watermark. Natural composition with a clear subject and enough negative space for cropping.'
        ].filter(Boolean).join('\n\n');
    }

    function getSeoOgImagePrompt() {
        var title = document.getElementById('seoOgTitle')?.value.trim()
            || document.getElementById('seoTitle')?.value.trim()
            || currentContent?.title
            || currentPage
            || '';
        var description = document.getElementById('seoOgDescription')?.value.trim()
            || document.getElementById('seoDescription')?.value.trim()
            || currentContent?.description
            || '';
        var pageText = extractContentText(currentContent || {}).slice(0, 700);
        return [
            'Create a 16:9 landscape Open Graph/social sharing image for this page. It should work well when cropped to a 1200x630 preview.',
            title ? 'Page title: ' + title : '',
            description ? 'Description: ' + description : '',
            pageText ? 'Page context: ' + pageText : '',
            'No embedded text, no logos unless explicitly part of the brand, no UI mockup. Clean social-sharing composition with a strong visual focal point.'
        ].filter(Boolean).join('\n\n');
    }

    function dashboardAnalyticsRangeLabel(period, count) {
        if (period === 'months') return t('dashboard_home.range_label_months', {count: count || 12});
        if (period === 'years') return t('dashboard_home.range_label_years');
        return t('dashboard_home.range_label_days', {count: count || 30});
    }

    function updateDashboardAnalyticsRangeUi(period, count) {
        var label = dashboardAnalyticsRangeLabel(period, count);
        var labelMap = {
            dashboardViewsPeriodLabel: t('dashboard_home.views_period', {period: label}),
            dashboardVisitorsPeriodLabel: t('dashboard_home.visitors_period', {period: label}),
            dashboardVisitsPeriodLabel: t('dashboard_home.visits_period', {period: label}),
            dashboardChartRangeLabel: t('dashboard_home.views_period', {period: label})
        };
        Object.keys(labelMap).forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.textContent = labelMap[id];
        });
        document.querySelectorAll('.dashboard-range-tab').forEach(function(tab) {
            var active = tab.dataset.analyticsPeriod === period && parseInt(tab.dataset.analyticsCount || '0', 10) === count;
            tab.classList.toggle('active', active);
        });
    }

    function setDashboardAnalyticsRange(period, count) {
        currentDashboardAnalyticsRange = {
            period: ['days', 'months', 'years'].includes(period) ? period : 'days',
            count: parseInt(count || '0', 10)
        };
        updateDashboardAnalyticsRangeUi(currentDashboardAnalyticsRange.period, currentDashboardAnalyticsRange.count);
        loadDashboardOverview();
    }

    function formatDashboardDate(value) {
        if (!value) return '—';
        var date = typeof value === 'number' ? new Date(value * 1000) : new Date(value);
        if (isNaN(date.getTime())) return '—';
        return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    async function openDashboardRecentEdit(item) {
        if (!item) return;
        if (item.type === 'news') {
            switchTab('news');
            if (!newsLoaded) {
                newsLoaded = true;
                await loadNews();
            }
            editPost(item.id);
            return;
        }
        if (item.type === 'page' && item.id) {
            window.location.href = '#page/' + item.id;
            applyDashboardRoute(true);
        }
    }

    function renderDashboardStatus(status) {
        var target = document.getElementById('dashboardStatusStrip');
        if (!target || !status) return;

        var recent = (status.recentEdits || [])[0] || null;
        window.dashboardRecentStatusItems = status.recentEdits || [];
        var backup = status.backup || {};
        var users = status.users || {};
        var currentUser = users.current || {};
        var chips = [];

        if (isDashboardModuleEnabled('mails')) {
            var unreadCount = status.messages?.unread || 0;
            chips.push('<button type="button" class="dashboard-status-chip' + (unreadCount > 0 ? ' dashboard-status-chip--accent' : '') + '" onclick="switchTab(&quot;mails&quot;)"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.unread_messages')) + '</span><strong class="dashboard-status-chip__value">' + unreadCount + '</strong></button>');
        }

        var backupText = backup.newest ? formatDashboardDate(backup.newest) : t('dashboard_home.no_backup');
        if (VALID_DASHBOARD_TABS.indexOf('backup') !== -1) {
            chips.push('<button type="button" class="dashboard-status-chip" onclick="switchTab(&quot;backup&quot;)"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.backup_status')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(backupText) + '</strong></button>');

            chips.push('<button type="button" class="dashboard-status-chip" onclick="switchTab(&quot;backup&quot;)"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.auto_backup')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(backup.enabled ? t('dashboard_home.active') : t('dashboard_home.inactive')) + '</strong></button>');
        }

        if (recent) {
            var recentTitle = recent.title || recent.id || '';
            chips.push('<button type="button" class="dashboard-status-chip dashboard-status-chip--wide" title="' + escapeHtml(recentTitle) + '" onclick="openDashboardRecentEdit(window.dashboardRecentStatusItems[0])"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.recent_edit')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(recentTitle) + '</strong></button>');
        }

        if (currentUser.username) {
            chips.push('<button type="button" class="dashboard-status-chip" onclick="switchTab(&quot;settings&quot;, {settingsTab: &quot;users&quot;});"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.current_user')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(currentUser.username) + '</strong></button>');
        }

        target.innerHTML = chips.join('');
    }

    async function loadDashboardOverview() {
        try {
            updateDashboardAnalyticsRangeUi(currentDashboardAnalyticsRange.period, currentDashboardAnalyticsRange.count);
            var params = new URLSearchParams({
                action: 'dashboard-overview',
                analytics_period: currentDashboardAnalyticsRange.period,
                analytics_count: String(currentDashboardAnalyticsRange.count),
                _: String(Date.now())
            });
            var response = await fetch('api.php?' + params.toString());
            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Could not load dashboard overview');
            var data = result.data || {};
            var analytics = data.analytics || {};

            var viewsToday = document.getElementById('dashboardViewsToday');
            var viewsPeriod = document.getElementById('dashboardViewsPeriod');
            var visitorsPeriod = document.getElementById('dashboardVisitorsPeriod');
            var visitsPeriod = document.getElementById('dashboardVisitsPeriod');
            var botCount = document.getElementById('dashboardBotCount');
            var pageCount = document.getElementById('dashboardPageCount');
            var newsCount = document.getElementById('dashboardNewsCount');
            if (viewsToday) viewsToday.textContent = analytics.todayViews || 0;
            if (viewsPeriod) viewsPeriod.textContent = analytics.periodViews || 0;
            if (visitorsPeriod) visitorsPeriod.textContent = analytics.periodVisitors || 0;
            if (visitsPeriod) visitsPeriod.textContent = analytics.periodVisits || 0;
            if (botCount) botCount.textContent = analytics.botRequests || 0;
            if (pageCount) pageCount.textContent = data.pages || 0;
            if (newsCount) newsCount.textContent = data.news || 0;
            renderDashboardStatus(data.status || {});

            renderDashboardTopPages(analytics.topPages || []);
            renderDashboardBreakdown('dashboardReferrers', analytics.referrers || []);
            renderDashboardBreakdown('dashboardDevices', analytics.devices || []);
            renderDashboardBreakdown('dashboardBrowsers', analytics.browsers || []);
            renderDashboardBreakdown('dashboardOs', analytics.os || []);
            const chart = document.getElementById('dashboardTrafficChart');
            let state = document.getElementById('dashboardAnalyticsState');
            if (!state && chart) { state = document.createElement('p'); state.id = 'dashboardAnalyticsState'; state.className = 'dashboard-empty'; state.setAttribute('role', 'status'); chart.before(state); }
            if (state) { state.hidden = analytics.state === 'ready'; state.textContent = t('analytics.' + (analytics.state || 'empty')); }
            if (chart) chart.hidden = analytics.state !== 'ready';
            renderDashboardTrafficChart(analytics.series || []);
            renderDashboardHourlyChart(analytics.hourlyToday || []);

            if (AI_FEATURES_ENABLED) {
                currentAiSettings = (data.ai && data.ai.settings) || {};
                populateAiSettings(currentAiSettings);
                updateAiUsage(data.ai ? data.ai.usage : null);
                updateDashboardAiPanel(currentAiSettings);
            } else {
                updateDashboardAiPanel({});
            }
        } catch (error) {
            var target = document.getElementById('dashboardTopPages');
            if (target) target.textContent = error.message;
        }
    }

    function renderDashboardTopPages(pages) {
        var target = document.getElementById('dashboardTopPages');
        if (!target) return;
        if (!pages.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_views') + '</p>';
            return;
        }
        target.innerHTML = pages.map(function(page) {
            var visitors = page.visitors || 0;
            var visits = page.visits || 0;
            return '<div class="dashboard-top-page">' +
                '<span><strong>' + escapeHtml(page.title || page.key || '') + '</strong><small>' + t('dashboard_home.views_detail', { views: page.views || 0, visitors: visitors, visits: visits }) + '</small></span>' +
                '<strong>' + (page.views || 0) + '</strong>' +
                '</div>';
        }).join('');
    }

    function renderDashboardTrafficChart(series) {
        var target = document.getElementById('dashboardTrafficChart');
        if (!target) return;
        if (!series.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_views') + '</p>';
            return;
        }

        var width = 720;
        var height = 220;
        var pad = { top: 16, right: 18, bottom: 34, left: 42 };
        var chartWidth = width - pad.left - pad.right;
        var chartHeight = height - pad.top - pad.bottom;
        var values = series.map(function(item) { return Math.max(0, parseInt(item.views || 0, 10)); });
        var maxValue = Math.max.apply(null, values.concat([1]));
        // Four integer intervals keep low traffic from repeating rounded ticks.
        var yMax = Math.max(4, Math.ceil(maxValue * 1.2 / 4) * 4);
        var stepX = series.length > 1 ? chartWidth / (series.length - 1) : chartWidth;

        function x(index) {
            return pad.left + index * stepX;
        }
        function y(value) {
            return pad.top + chartHeight - (value / yMax) * chartHeight;
        }
        function point(index, value) {
            return x(index).toFixed(2) + ',' + y(value).toFixed(2);
        }

        var line = values.map(function(value, index) {
            return (index === 0 ? 'M ' : 'L ') + point(index, value);
        }).join(' ');
        var area = line + ' L ' + x(values.length - 1).toFixed(2) + ',' + (pad.top + chartHeight).toFixed(2) +
            ' L ' + pad.left + ',' + (pad.top + chartHeight).toFixed(2) + ' Z';
        var grid = [0, 0.25, 0.5, 0.75, 1].map(function(factor) {
            var yy = pad.top + chartHeight - chartHeight * factor;
            var label = Math.round(yMax * factor);
            return '<g><line x1="' + pad.left + '" y1="' + yy.toFixed(2) + '" x2="' + (width - pad.right) + '" y2="' + yy.toFixed(2) + '"></line>' +
                '<text x="' + (pad.left - 10) + '" y="' + (yy + 4).toFixed(2) + '">' + label + '</text></g>';
        }).join('');
        var labelIndexes = [0, Math.floor((series.length - 1) / 2), series.length - 1].filter(function(value, index, arr) {
            return arr.indexOf(value) === index;
        });
        var xLabels = labelIndexes.map(function(index) {
            var label = series[index].label || '';
            if (!label) {
                var date = new Date((series[index].date || '') + 'T00:00:00');
                label = isNaN(date.getTime()) ? (series[index].date || '') : date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
            }
            return '<text x="' + x(index).toFixed(2) + '" y="' + (height - 8) + '" text-anchor="middle">' + escapeHtml(label) + '</text>';
        }).join('');
        var points = values.map(function(value, index) {
            var date = new Date((series[index].date || '') + 'T00:00:00');
            var label = series[index].label || (isNaN(date.getTime()) ? (series[index].date || '') : date.toLocaleDateString());
            return '<circle cx="' + x(index).toFixed(2) + '" cy="' + y(value).toFixed(2) + '" r="3"><title>' +
                escapeHtml(label + ': ' + value + ' ' + t('dashboard_home.views_label')) + '</title></circle>';
        }).join('');

        target.innerHTML = '<svg class="dashboard-traffic-svg" viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + escapeHtml(t('dashboard_home.traffic_curve')) + '">' +
            '<g class="dashboard-chart-grid">' + grid + '</g>' +
            '<path class="dashboard-chart-area" d="' + area + '"></path>' +
            '<path class="dashboard-chart-line" d="' + line + '"></path>' +
            '<g class="dashboard-chart-points">' + points + '</g>' +
            '<g class="dashboard-chart-xlabels">' + xLabels + '</g>' +
            '</svg>';
    }

    function renderDashboardHourlyChart(hours) {
        var target = document.getElementById('dashboardHourlyChart');
        if (!target) return;
        if (!hours.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_views') + '</p>';
            return;
        }
        var maxValue = Math.max.apply(null, hours.map(function(item) { return Math.max(0, parseInt(item.views || 0, 10)); }).concat([1]));
        target.innerHTML = hours.map(function(item, index) {
            var views = Math.max(0, parseInt(item.views || 0, 10));
            var height = Math.max(3, Math.round((views / maxValue) * 100));
            var label = item.label || String(index).padStart(2, '0') + ':00';
            var showLabel = index % 3 === 0;
            return '<div class="dashboard-hour-bar" title="' + escapeHtml(label + ': ' + views + ' ' + t('dashboard_home.views_label')) + '">' +
                '<span class="dashboard-hour-bar__track"><span class="dashboard-hour-bar__fill" style="height:' + height + '%"></span></span>' +
                '<span class="dashboard-hour-bar__label">' + (showLabel ? escapeHtml(String(index).padStart(2, '0')) : '') + '</span>' +
                '</div>';
        }).join('');
    }

    function renderDashboardBreakdown(targetId, items) {
        var target = document.getElementById(targetId);
        if (!target) return;
        if (!items.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_data') + '</p>';
            return;
        }
        target.innerHTML = items.slice(0, 8).map(function(item) {
            return '<div class="dashboard-breakdown-row">' +
                '<span>' + escapeHtml(item.label || item.key || '') + '</span>' +
                '<strong>' + (item.views || item.count || 0) + '</strong>' +
                '</div>';
        }).join('');
    }

    function updateDashboardAiStatus(settings) {
        var target = document.getElementById('dashboardAiStatus');
        if (!target) return;
        if (!AI_FEATURES_ENABLED) {
            target.textContent = t('ai.module_disabled_status');
            return;
        }
        var configured = aiProviderIsConfigured(settings);
        if (!settings.enabled) {
            target.textContent = t('ai.disabled_status');
        } else if (!configured) {
            target.textContent = t('ai.not_configured_text');
        } else {
            target.textContent = t('ai.configured_status');
        }
    }

    function aiFeatureEnabled(settings, feature) {
        settings = settings || currentAiSettings || {};
        var features = settings.features || {};
        if (Object.prototype.hasOwnProperty.call(features, feature)) {
            return !!features[feature];
        }
        return !!AI_FEATURE_DEFAULTS[feature];
    }

    function aiServiceIsUsable(settings) {
        settings = settings || currentAiSettings || {};
        return AI_FEATURES_ENABLED && !!settings.enabled && aiProviderIsConfigured(settings);
    }

    function aiUnavailableNoticeDismissed() {
        try {
            return localStorage.getItem(AI_NOTICE_DISMISSED_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function dismissAiUnavailableNotice() {
        try {
            localStorage.setItem(AI_NOTICE_DISMISSED_KEY, '1');
        } catch (e) {}
        var section = document.getElementById('dashboardAiSection');
        var usable = aiServiceIsUsable(currentAiSettings || {});
        if (section && !usable) section.hidden = true;
    }

    function updateDashboardAiPanel(settings) {
        settings = settings || currentAiSettings || {};
        var section = document.getElementById('dashboardAiSection');
        if (!section) return;

        var configured = AI_FEATURES_ENABLED && aiProviderIsConfigured(settings);
        var serviceEnabled = AI_FEATURES_ENABLED && !!settings.enabled;
        var usable = configured && serviceEnabled;
        var unavailableDismissed = aiUnavailableNoticeDismissed();
        var banner = document.getElementById('aiUnavailableBanner');
        var bannerText = document.getElementById('aiUnavailableText');
        var tools = document.getElementById('dashboardAiTools');
        var usage = document.getElementById('aiUsageSummary');
        var assistantEnabled = usable && aiFeatureEnabled(settings, 'backendAssistant');
        var textEnabled = usable && aiFeatureEnabled(settings, 'seoTextGeneration');
        var imageEnabled = usable && aiFeatureEnabled(settings, 'imageGeneration');
        var imageJobsPanel = document.getElementById('aiImageJobsPanel');

        dashboardAiImageUsable = imageEnabled;
        if (window.NbImageManager && typeof NbImageManager.refresh === 'function') {
            NbImageManager.refresh();
        }
        section.hidden = !AI_FEATURES_ENABLED || (!usable && unavailableDismissed);
        updateDashboardAiStatus(settings);

        if (usage && !usable) usage.hidden = true;

        if (banner) {
            banner.hidden = usable;
        }
        if (bannerText) {
            if (!AI_FEATURES_ENABLED) {
                bannerText.textContent = t('ai.module_disabled_status');
            } else if (!serviceEnabled) {
                bannerText.textContent = t('ai.disabled_status');
            } else {
                bannerText.textContent = t('ai.not_configured_text');
            }
        }

        if (tools) tools.hidden = !usable || (!assistantEnabled && !textEnabled && !imageEnabled);
        if (imageJobsPanel) imageJobsPanel.hidden = !imageEnabled;

        var assistantCard = document.getElementById('aiAssistantCard');
        if (assistantCard) assistantCard.hidden = !assistantEnabled;

        var toolsCard = document.getElementById('aiToolsCard');
        if (toolsCard) toolsCard.hidden = !textEnabled && !imageEnabled;

        document.querySelectorAll('.ai-tool-tab, .ai-tool-panel').forEach(function(el) {
            var feature = el.dataset.aiFeature;
            var visible = feature === 'imageGeneration' ? imageEnabled : (feature === 'seoTextGeneration' ? textEnabled : true);
            el.hidden = !visible;
            if (!visible) {
                el.classList.remove('active');
                if (el.classList.contains('ai-tool-tab')) el.setAttribute('aria-selected', 'false');
            }
        });

        if (textEnabled || imageEnabled) {
            var currentActive = document.querySelector('.ai-tool-tab.active:not([hidden])');
            switchAiToolTab(currentActive ? currentActive.dataset.aiToolTab : (imageEnabled ? 'image' : 'text'));
        }
    }

    document.getElementById('aiUnavailableDismiss')?.addEventListener('click', dismissAiUnavailableNotice);

    function aiIsLocalProviderUrl(baseUrl) {
        try {
            var host = new URL(baseUrl || '').hostname.toLowerCase();
            return host === 'localhost' || host === '127.0.0.1' || host === '::1' || host.startsWith('192.168.') || host.startsWith('10.') || /^172\\.(1[6-9]|2\\d|3[0-1])\\./.test(host);
        } catch (e) {
            return false;
        }
    }

    function aiProviderIsConfigured(settings) {
        settings = settings || currentAiSettings || {};
        var provider = document.getElementById('aiProvider')?.value || settings.provider || 'openai-compatible';
        var credentials = aiProviderCredentials(settings, provider);
        var activeProvider = settings.provider || 'openai-compatible';
        var hasKey = !!document.getElementById('aiApiKey')?.value.trim()
            || (provider === activeProvider ? !!settings.hasApiKey : false)
            || !!credentials.hasApiKey;
        var baseUrl = document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '';
        var allowLocal = !!settings.allowLocalProvider || !!document.getElementById('aiAllowLocalProvider')?.checked;
        return hasKey || (allowLocal && aiIsLocalProviderUrl(baseUrl));
    }

    function aiProviderCredentials(settings, provider) {
        settings = settings || currentAiSettings || {};
        provider = provider || settings.provider || document.getElementById('aiProvider')?.value || 'openai-compatible';
        var defaults = {
            'openai-compatible': { baseUrl: 'https://api.openai.com/v1', organization: '', hasApiKey: false },
            openrouter: { baseUrl: 'https://openrouter.ai/api/v1', organization: '', hasApiKey: false },
            anthropic: { baseUrl: 'https://api.anthropic.com/v1', organization: '', hasApiKey: false },
            kie: { baseUrl: 'https://api.kie.ai', organization: '', hasApiKey: false }
        };
        var credentials = settings.providerCredentials && settings.providerCredentials[provider]
            ? settings.providerCredentials[provider]
            : {};
        return Object.assign({}, defaults[provider] || defaults['openai-compatible'], credentials);
    }

    function aiUsesOpenRouter(settings) {
        settings = settings || currentAiSettings || {};
        var provider = String(document.getElementById('aiProvider')?.value || settings.provider || '').trim();
        var credentials = aiProviderCredentials(settings, provider);
        var baseUrl = String(document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '').trim();
        return provider === 'openrouter' || baseUrl.indexOf('openrouter.ai') !== -1;
    }

    function aiUsesAnthropic(settings) {
        settings = settings || currentAiSettings || {};
        var provider = String(document.getElementById('aiProvider')?.value || settings.provider || '').trim();
        var credentials = aiProviderCredentials(settings, provider);
        var baseUrl = String(document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '').trim();
        return provider === 'anthropic' || baseUrl.indexOf('api.anthropic.com') !== -1;
    }

    function aiUsesKie(settings) {
        settings = settings || currentAiSettings || {};
        var provider = String(document.getElementById('aiProvider')?.value || settings.provider || '').trim();
        var credentials = aiProviderCredentials(settings, provider);
        var baseUrl = String(document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '').trim();
        return provider === 'kie' || baseUrl.indexOf('api.kie.ai') !== -1;
    }

    var AI_TEXT_MODEL_PRESETS = Object.fromEntries(Object.entries(window.NB_AI_CATALOG).map(([key, provider]) => [key, provider.text]));

    var aiOpenRouterModelsCache = null;
    var aiOpenRouterModelsLoading = false;

    // Load the live OpenRouter catalog through the server cache; the static
    // preset list stays as fallback when the request fails or is pending.
    function loadOpenRouterModels() {
        if (aiOpenRouterModelsCache || aiOpenRouterModelsLoading) return;
        aiOpenRouterModelsLoading = true;
        var formData = new FormData();
        formData.append('action', 'ai-openrouter-models');
        formData.append('csrf_token', CSRF_TOKEN);
        fetch('api.php', { method: 'POST', body: formData, cache: 'no-store' })
            .then(function(response) { return response.json(); })
            .then(function(result) {
                if (!result.success || !result.data) return;
                aiOpenRouterModelsCache = result.data;
                applyOpenRouterModels();
            })
            .catch(function() { /* keep static fallback */ })
            .finally(function() { aiOpenRouterModelsLoading = false; });
    }

    function applyOpenRouterModels() {
        var data = aiOpenRouterModelsCache;
        if (!data) return;
        if (Array.isArray(data.textModels) && data.textModels.length) {
            AI_TEXT_MODEL_PRESETS.openrouter.suggestions = data.textModels.map(function(model) { return model.id; });
        }
        if (Array.isArray(data.imageModels) && data.imageModels.length) {
            AI_IMAGE_MODEL_OPTIONS.openrouter = data.imageModels.map(function(model) {
                return { value: model.id, label: model.name || model.id };
            });
        }
        var provider = document.getElementById('aiProvider')?.value || (currentAiSettings && currentAiSettings.provider) || '';
        if (provider === 'openrouter') {
            updateAiModelPlaceholders('openrouter');
            updateAiImageModelControl(currentAiSettings || {});
        }
        // Refresh the size/cost note now that live image prices are available.
        if (typeof updateAiImageRatioIcon === 'function') updateAiImageRatioIcon();
    }

    function maybeAutofillOpenRouterPricing() {
        var provider = document.getElementById('aiProvider')?.value;
        if (provider !== 'openrouter' || !aiOpenRouterModelsCache) return;
        var model = String(document.getElementById('aiChatModel')?.value || '').trim();
        var match = (aiOpenRouterModelsCache.textModels || []).find(function(entry) { return entry.id === model; });
        if (!match || (!match.promptCentsPerMillion && !match.completionCentsPerMillion)) return;
        var inputPrice = document.getElementById('aiInputPrice');
        var outputPrice = document.getElementById('aiOutputPrice');
        if (inputPrice && match.promptCentsPerMillion > 0) inputPrice.value = match.promptCentsPerMillion;
        if (outputPrice && match.completionCentsPerMillion > 0) outputPrice.value = match.completionCentsPerMillion;
        if (typeof showToast === 'function') showToast(t('ai.pricing_autofilled'), 'info');
    }

    function aiModelIsPresetDefault(value) {
        value = String(value || '').trim();
        if (!value) return true;
        return Object.keys(AI_TEXT_MODEL_PRESETS).some(function(provider) {
            var preset = AI_TEXT_MODEL_PRESETS[provider];
            return preset.chat === value || preset.text === value;
        });
    }

    // Keep the model inputs aligned with the selected provider: adjust
    // placeholders and suggestions always, and swap the values only when they
    // still hold another provider's default (never a custom model).
    function updateAiModelPlaceholders(provider) {
        var preset = AI_TEXT_MODEL_PRESETS[provider] || AI_TEXT_MODEL_PRESETS['openai-compatible'];
        var chatInput = document.getElementById('aiChatModel');
        var textInput = document.getElementById('aiTextModel');
        if (chatInput) {
            chatInput.placeholder = preset.chat;
            if (aiModelIsPresetDefault(chatInput.value)) chatInput.value = preset.chat;
            updateClearButton(chatInput);
        }
        if (textInput) {
            textInput.placeholder = preset.text;
            if (aiModelIsPresetDefault(textInput.value)) textInput.value = preset.text;
            updateClearButton(textInput);
        }
        aiModelComboboxes.forEach(function(combobox) { combobox.refresh(); });
        if (provider === 'openrouter') {
            loadOpenRouterModels();
        }
    }

    function aiModelSuggestions() {
        var provider = document.getElementById('aiProvider')?.value
            || (currentAiSettings && currentAiSettings.provider) || 'openai-compatible';
        var preset = AI_TEXT_MODEL_PRESETS[provider] || AI_TEXT_MODEL_PRESETS['openai-compatible'];
        return preset.suggestions.slice();
    }

    // Custom combobox: identical rendering in every browser (incl. Safari and
    // embedded webviews) instead of the unstylable native datalist popup.
    var aiModelComboboxes = [];
    function setupModelCombobox(root) {
        var input = root.querySelector('input');
        var toggle = root.querySelector('.nb-combobox__toggle');
        var list = root.querySelector('.nb-combobox__list');
        if (!input || !list) return null;
        var activeIndex = -1;
        var items = [];

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function select(value) {
            input.value = value;
            close();
            updateClearButton(input);
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
        }

        function highlight(index) {
            activeIndex = index;
            Array.from(list.children).forEach(function(item, i) {
                item.classList.toggle('is-active', i === index);
                item.setAttribute('aria-selected', i === index ? 'true' : 'false');
                if (i === index) item.scrollIntoView({ block: 'nearest' });
            });
        }

        function open(filterText) {
            var all = aiModelSuggestions();
            var filter = String(filterText || '').trim().toLowerCase();
            items = filter && all.indexOf(input.value.trim()) === -1
                ? all.filter(function(model) { return model.toLowerCase().indexOf(filter) !== -1; })
                : all;
            if (!items.length) { close(); return; }
            list.innerHTML = '';
            items.forEach(function(model, index) {
                var item = document.createElement('div');
                item.className = 'nb-combobox__option';
                item.setAttribute('role', 'option');
                item.textContent = model;
                item.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                    select(model);
                });
                list.appendChild(item);
            });
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            highlight(-1);
        }

        input.addEventListener('input', function() {
            updateClearButton(input);
            open(input.value);
        });
        input.addEventListener('keydown', function(event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (list.hidden) { open(input.value); return; }
                var count = list.children.length;
                if (!count) return;
                var next = event.key === 'ArrowDown'
                    ? (activeIndex + 1) % count
                    : (activeIndex - 1 + count) % count;
                highlight(next);
            } else if (event.key === 'Enter') {
                if (!list.hidden && activeIndex >= 0 && items[activeIndex] !== undefined) {
                    event.preventDefault();
                    select(items[activeIndex]);
                }
            } else if (event.key === 'Escape' && !list.hidden) {
                event.stopPropagation();
                close();
            }
        });
        input.addEventListener('blur', function() {
            window.setTimeout(close, 120);
        });
        if (toggle) {
            toggle.addEventListener('mousedown', function(event) {
                event.preventDefault();
                if (list.hidden) {
                    input.focus();
                    open('');
                } else {
                    close();
                }
            });
        }

        return { refresh: function() { if (!list.hidden) open(input.value); } };
    }

    document.querySelectorAll('[data-model-combobox]').forEach(function(root) {
        var combobox = setupModelCombobox(root);
        if (combobox) aiModelComboboxes.push(combobox);
    });

    document.getElementById('aiChatModel')?.addEventListener('change', maybeAutofillOpenRouterPricing);

    var AI_IMAGE_MODEL_OPTIONS = Object.fromEntries(Object.entries(window.NB_AI_CATALOG).map(([key, provider]) => [key, provider.images.map(image => ({ value: image.value, label: image.label.startsWith('ai.') ? t(image.label) : image.label }))]));

    function normalizeOpenRouterImageModel(model) {
        model = String(model || '').trim();
        var aliases = window.NB_AI_CATALOG.openrouter.imageAliases;
        var valid = AI_IMAGE_MODEL_OPTIONS.openrouter.map(function(option) {
            return option.value;
        });
        if (valid.indexOf(model) !== -1) {
            return model;
        }
        return aliases[model] || 'openai/gpt-5.4-image-2';
    }

    function normalizeOpenAiImageModel(model) {
        model = String(model || '').trim();
        return model === 'gpt-image-2' ? model : 'gpt-image-2';
    }

    function normalizeKieImageModel(model) {
        model = String(model || '').trim();
        var aliases = window.NB_AI_CATALOG.kie.imageAliases;
        model = aliases[model] || model;
        return AI_IMAGE_MODEL_OPTIONS.kie.some(function(option) { return option.value === model; })
            ? model : 'gpt-image-2';
    }

    function currentAiProviderKey(settings) {
        settings = settings || currentAiSettings || {};
        var provider = document.getElementById('aiProvider')?.value || settings.provider || 'openai-compatible';
        if (provider === 'kie' || aiUsesKie(settings)) return 'kie';
        return provider === 'openrouter' || aiUsesOpenRouter(settings) ? 'openrouter' : 'openai-compatible';
    }

    function updateAiImageModelControl(settings) {
        settings = settings || currentAiSettings || {};
        var providerKey = currentAiProviderKey(settings);
        var input = document.getElementById('aiImageModel');
        var picker = document.getElementById('aiImageModelPicker');
        if (!input) return;
        var options = AI_IMAGE_MODEL_OPTIONS[providerKey] || AI_IMAGE_MODEL_OPTIONS['openai-compatible'];
        var selected = providerKey === 'openrouter'
            ? normalizeOpenRouterImageModel(settings.imageModel || input.value || '')
            : (providerKey === 'kie'
                ? normalizeKieImageModel(settings.imageModel || input.value || '')
                : normalizeOpenAiImageModel(settings.imageModel || input.value || ''));
        if (picker) {
            picker.innerHTML = options.map(function(option) {
                return '<option value="' + escapeHtml(option.value) + '">' + escapeHtml(option.label) + '</option>';
            }).join('');
            if (!options.some(function(option) { return option.value === selected; })) {
                selected = options[0] ? options[0].value : '';
            }
            picker.value = selected;
        }
        input.value = selected;
        updateAiImageScaleOptions();
    }

    function aiSelectedImageModelSupports4K() {
        var model = String(document.getElementById('aiImageModelPicker')?.value || document.getElementById('aiImageModel')?.value || '').trim();
        return !/(^|\/)gpt-5\.4-image-2(?:$|-)|^gpt-image-2(?:$|-)/i.test(model);
    }

    function updateAiImageScaleOptions() {
        var scale = document.getElementById('aiImageScale');
        if (!scale) return;
        var current = parseInt(scale.value || '2048', 10) || 2048;
        var supports4K = aiSelectedImageModelSupports4K();
        var options = [
            { value: '1024', label: '1K' },
            { value: '2048', label: '2K' }
        ];
        if (supports4K) {
            options.push({ value: '3072', label: '3K' }, { value: '3840', label: '4K' });
        }
        scale.innerHTML = options.map(function(option) {
            return '<option value="' + option.value + '">' + option.label + '</option>';
        }).join('');
        var maxScale = supports4K ? 3840 : 2048;
        scale.value = String(Math.min(current, maxScale));
        if (!scale.value) {
            scale.value = supports4K && current > 2048 ? '3840' : '2048';
        }
        if (typeof updateAiImageRatioIcon === 'function') updateAiImageRatioIcon();
    }

    function updateAiAvailability(settings) {
        settings = settings || currentAiSettings || {};
        updateDashboardAiPanel(settings);
        if (!AI_FEATURES_ENABLED) return;
        updateAiImageModelControl(settings);
        // Refresh the size note so the estimated per-image cost reflects the
        // current pricing once settings have loaded.
        if (typeof updateAiImageRatioIcon === 'function') updateAiImageRatioIcon();
        var configured = aiProviderIsConfigured(settings);
        var enabled = !!settings.enabled;
        var usable = configured && enabled;
        var assistantUsable = usable && aiFeatureEnabled(settings, 'backendAssistant');
        var textUsable = usable && aiFeatureEnabled(settings, 'seoTextGeneration');
        var imageUsable = usable && aiFeatureEnabled(settings, 'imageGeneration') && !aiUsesAnthropic(settings);
        dashboardAiImageUsable = imageUsable;
        var status = document.getElementById('aiProviderStatus');
        if (status) {
            status.className = 'ai-status-box ' + (usable ? 'ai-status-box--ok' : 'ai-status-box--warning');
            status.textContent = usable
                ? t('ai.configured_status')
                : (configured ? t('ai.disabled_status') : t('ai.not_configured_text'));
        }

        var banner = document.getElementById('aiUnavailableBanner');
        if (banner) banner.hidden = usable;

        document.querySelectorAll('#aiChatForm textarea, #aiChatForm button').forEach(function(el) {
            el.disabled = !assistantUsable;
        });
        document.querySelectorAll('#aiTextForm textarea, #aiTextForm input, #aiTextForm button').forEach(function(el) {
            el.disabled = !textUsable;
        });
        document.querySelectorAll('#aiImageForm textarea, #aiImageForm select, #aiImageForm input, #aiImageForm button').forEach(function(el) {
            el.disabled = !imageUsable;
        });

        var imageModel = String(document.getElementById('aiImageModelPicker')?.value || settings.imageModel || document.getElementById('aiImageModel')?.value || '').trim();
        var imageModelMissing = imageUsable && !imageModel;
        var imageModelNote = document.getElementById('aiImageModelNote');
        var imageButton = document.getElementById('aiGenerateImageButton');
        if (imageButton) {
            imageButton.disabled = !imageUsable || imageModelMissing;
        }
        if (imageModelNote) {
            imageModelNote.textContent = aiUsesAnthropic(settings)
                ? t('ai.image_anthropic_note')
                : (aiUsesOpenRouter(settings) ? t('ai.image_openrouter_note') : t('ai.image_model_missing_note'));
            imageModelNote.hidden = !imageUsable || (!imageModelMissing && !aiUsesOpenRouter(settings));
        }

        document.querySelectorAll('#aiFeatureAssistant, #aiFeatureSeo, #aiFeatureImages').forEach(function(el) {
            el.disabled = !configured;
        });
        refreshSeoAiButtons();
    }

    async function loadAiSettings() {
        if (!AI_FEATURES_ENABLED) return;
        try {
            var response = await fetch('api.php?action=load-ai-settings');
            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Could not load AI settings');
            currentAiSettings = result.data.settings || {};
            populateAiSettings(currentAiSettings);
            updateAiUsage(result.data.usage);
            updateDashboardAiPanel(currentAiSettings);
        } catch (error) {
            var usage = document.getElementById('aiUsageSummary');
            if (usage) usage.textContent = error.message;
        }
    }

    function populateAiSettings(settings) {
        settings = settings || {};
        var provider = settings.provider || 'openai-compatible';
        var providerCredentials = aiProviderCredentials(settings, provider);
        var fields = {
            aiEnabled: !!settings.enabled,
            aiAllowLocalProvider: !!settings.allowLocalProvider,
            aiAssistantForceEnglish: !!settings.assistantForceEnglish,
            aiAssistantSurfaceVisualEditor: !settings.assistantSurfaces || settings.assistantSurfaces.visualEditor !== false,
            aiAssistantSurfaceDashboard: !settings.assistantSurfaces || settings.assistantSurfaces.dashboard !== false,
            aiFeatureAssistant: !!(settings.features && settings.features.backendAssistant),
            aiFeatureSeo: !!(settings.features && settings.features.seoTextGeneration),
            aiFeatureImages: !!(settings.features && settings.features.imageGeneration)
        };
        Object.keys(fields).forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.checked = fields[id];
        });
        var values = {
            aiProvider: provider,
            aiBaseUrl: Object.prototype.hasOwnProperty.call(providerCredentials, 'baseUrl') ? (providerCredentials.baseUrl || '') : (settings.baseUrl || ''),
            aiOrganization: Object.prototype.hasOwnProperty.call(providerCredentials, 'organization') ? (providerCredentials.organization || '') : (settings.organization || ''),
            aiChatModel: settings.chatModel || 'gpt-4.1-mini',
            aiTextModel: settings.textModel || settings.chatModel || 'gpt-4.1-mini',
            aiImageModel: Object.prototype.hasOwnProperty.call(settings, 'imageModel') ? (settings.imageModel || '') : 'gpt-image-2',
            aiMonthlyBudget: settings.limits?.monthlyBudgetCents ?? 1000,
            aiDailyRequests: settings.limits?.dailyRequests ?? 100,
            aiDailyTextRequests: settings.limits?.dailyTextRequests ?? 80,
            aiDailyImageRequests: settings.limits?.dailyImageRequests ?? 10,
            aiMaxInputTokens: settings.limits?.maxInputTokens ?? 24000,
            aiMaxOutputTokens: settings.limits?.maxOutputTokens ?? 4096,
            aiRequestTimeout: settings.limits?.requestTimeoutSeconds ?? 300,
            aiInputPrice: settings.pricing?.inputCentsPerMillion ?? 15,
            aiOutputPrice: settings.pricing?.outputCentsPerMillion ?? 60,
            aiImagePrice: settings.pricing?.imageCentsPerRequest ?? 5
        };
        Object.keys(values).forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = values[id];
        });
        updateAiImageModelControl(settings);
        var keyInput = document.getElementById('aiApiKey');
        if (keyInput) keyInput.value = '';
        var clearKey = document.getElementById('aiClearApiKey');
        if (clearKey) clearKey.checked = false;
        updateAiApiKeyHint(provider);
        updateAiModelPlaceholders(provider);
        updateAiAvailability(settings);
    }

    function updateAiApiKeyHint(provider) {
        var keyHint = document.getElementById('aiApiKeyHint');
        if (!keyHint) return;
        var credentials = aiProviderCredentials(currentAiSettings || {}, provider || document.getElementById('aiProvider')?.value || 'openai-compatible');
        keyHint.textContent = credentials.hasApiKey ? t('ai.api_key_saved') : t('ai.api_key_missing');
    }

    function applyAiDefaultsForNewApiKey() {
        var keyInput = document.getElementById('aiApiKey');
        if (!keyInput || !keyInput.value.trim()) return;
        var provider = document.getElementById('aiProvider')?.value || (currentAiSettings && currentAiSettings.provider) || 'openai-compatible';
        var credentials = aiProviderCredentials(currentAiSettings || {}, provider);
        if ((currentAiSettings && currentAiSettings.hasApiKey) || credentials.hasApiKey) return;
        [
            'aiEnabled',
            'aiAssistantSurfaceVisualEditor',
            'aiAssistantSurfaceDashboard',
            'aiFeatureAssistant',
            'aiFeatureSeo',
            'aiFeatureImages'
        ].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.checked = true;
        });
    }

    function collectAiSettingsForm() {
        applyAiDefaultsForNewApiKey();
        var provider = document.getElementById('aiProvider').value;
        var imagePicker = document.getElementById('aiImageModelPicker');
        if (imagePicker) {
            document.getElementById('aiImageModel').value = imagePicker.value;
        }
        return {
            enabled: document.getElementById('aiEnabled').checked,
            provider: provider,
            baseUrl: document.getElementById('aiBaseUrl').value.trim(),
            apiKey: document.getElementById('aiApiKey').value.trim(),
            clearApiKey: document.getElementById('aiClearApiKey').checked,
            organization: document.getElementById('aiOrganization').value.trim(),
            allowLocalProvider: document.getElementById('aiAllowLocalProvider').checked,
            assistantForceEnglish: document.getElementById('aiAssistantForceEnglish').checked,
            assistantSurfaces: {
                visualEditor: document.getElementById('aiAssistantSurfaceVisualEditor').checked,
                dashboard: document.getElementById('aiAssistantSurfaceDashboard').checked
            },
            chatModel: document.getElementById('aiChatModel').value.trim(),
            textModel: document.getElementById('aiTextModel').value.trim(),
            imageModel: document.getElementById('aiImageModel').value.trim(),
            features: {
                backendAssistant: document.getElementById('aiFeatureAssistant').checked,
                seoTextGeneration: document.getElementById('aiFeatureSeo').checked,
                imageGeneration: document.getElementById('aiFeatureImages').checked
            },
            limits: {
                monthlyBudgetCents: parseInt(document.getElementById('aiMonthlyBudget').value || '0', 10),
                dailyRequests: parseInt(document.getElementById('aiDailyRequests').value || '0', 10),
                dailyTextRequests: parseInt(document.getElementById('aiDailyTextRequests').value || '0', 10),
                dailyImageRequests: parseInt(document.getElementById('aiDailyImageRequests').value || '0', 10),
                maxInputTokens: parseInt(document.getElementById('aiMaxInputTokens').value || '0', 10),
                maxOutputTokens: parseInt(document.getElementById('aiMaxOutputTokens').value || '0', 10),
                requestTimeoutSeconds: parseInt(document.getElementById('aiRequestTimeout').value || '0', 10)
            },
            pricing: {
                inputCentsPerMillion: parseInt(document.getElementById('aiInputPrice').value || '0', 10),
                outputCentsPerMillion: parseInt(document.getElementById('aiOutputPrice').value || '0', 10),
                imageCentsPerRequest: parseInt(document.getElementById('aiImagePrice').value || '0', 10)
            }
        };
    }

    document.getElementById('aiProvider')?.addEventListener('change', function() {
        var baseUrl = document.getElementById('aiBaseUrl');
        var credentials = aiProviderCredentials(currentAiSettings || {}, this.value);
        if (baseUrl) baseUrl.value = credentials.baseUrl || '';
        var organization = document.getElementById('aiOrganization');
        if (organization) organization.value = credentials.organization || '';
        var keyInput = document.getElementById('aiApiKey');
        if (keyInput) keyInput.value = '';
        var clearKey = document.getElementById('aiClearApiKey');
        if (clearKey) clearKey.checked = false;
        updateAiApiKeyHint(this.value);
        updateAiModelPlaceholders(this.value);
        updateAiImageModelControl({
            provider: this.value,
            baseUrl: baseUrl ? baseUrl.value : '',
            imageModel: document.getElementById('aiImageModel')?.value || ''
        });
        var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
        draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
        updateAiAvailability(draft);
    });

    document.getElementById('aiImageModelPicker')?.addEventListener('change', function() {
        var imageInput = document.getElementById('aiImageModel');
        if (imageInput) imageInput.value = this.value;
        updateAiImageScaleOptions();
        var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
        draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
        updateAiAvailability(draft);
    });

    ['aiApiKey', 'aiBaseUrl', 'aiAllowLocalProvider', 'aiEnabled', 'aiAssistantForceEnglish', 'aiAssistantSurfaceVisualEditor', 'aiAssistantSurfaceDashboard', 'aiFeatureAssistant', 'aiFeatureSeo', 'aiFeatureImages', 'aiImageModel', 'aiImageModelPicker'].forEach(function(id) {
        document.addEventListener('input', function(e) {
            if (e.target && e.target.id === id) {
                if (id === 'aiApiKey') applyAiDefaultsForNewApiKey();
                var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
                draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
                updateAiAvailability(draft);
            }
        });
        document.addEventListener('change', function(e) {
            if (e.target && e.target.id === id) {
                if (id === 'aiApiKey') applyAiDefaultsForNewApiKey();
                var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
                draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
                updateAiAvailability(draft);
            }
        });
    });

    function appendAiChat(role, text) {
        var log = document.getElementById('aiChatLog');
        if (!log) return;
        var item = document.createElement('div');
        item.className = 'ai-chat-message ai-chat-message--' + role;
        if (role === 'assistant') {
            item.innerHTML = window.renderSimpleMarkup(text);
        } else {
            item.textContent = text;
        }
        log.appendChild(item);
        log.scrollTop = log.scrollHeight;
    }

    window.renderSimpleMarkup = function(text) {
        var escaped = escapeHtml(String(text || ''));
        return escaped
            .replace(/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+|mailto:[^)\s]+)\)/g, function(match, label, url) {
                var safeUrl = url.replace(/&amp;/g, '&');
                return '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
            })
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*\\n]+)\*/g, '$1<em>$2</em>')
            .replace(/`([^`]+)`/g, '<code>$1</code>');
    };

    // Form definitions
    let formsAdminData = [];
    let currentFormEditorId = '';

    function defaultFormDefinition(id) {
        return {
            id: id || 'kontakt',
            label: t('forms.contact_default'),
            description: '',
            enabled: true,
            submit: {
                store: true,
                email: true,
                subject: '{form}: {name}',
                successText: t('forms.default_success')
            },
            fields: [
                { type: 'text', key: 'name', label: t('mails.name').replace(':', ''), placeholder: '', required: true, width: 6, options: [] },
                { type: 'email', key: 'email', label: t('mails.email').replace(':', ''), placeholder: '', required: true, width: 6, options: [] },
                { type: 'textarea', key: 'message', label: t('mails.message').replace(':', ''), placeholder: '', required: true, width: 12, options: [] }
            ]
        };
    }
