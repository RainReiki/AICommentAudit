(function () {
    'use strict';

    var cfg = window.AICommentAudit || {};
    var strings = cfg.strings || {};

    function t(key, fallback) {
        return strings[key] || fallback;
    }

    function ajax(action, data) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', cfg.nonce || '');
        Object.keys(data || {}).forEach(function (key) {
            body.set(key, data[key] == null ? '' : data[key]);
        });

        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).then(function (response) {
            return response.json();
        });
    }

    function textCell(value, className) {
        var cell = document.createElement('td');
        if (className) {
            var span = document.createElement('span');
            span.className = className;
            span.textContent = value == null ? '' : String(value);
            cell.appendChild(span);
        } else {
            cell.textContent = value == null ? '' : String(value);
        }
        return cell;
    }

    function decisionLabel(decision) {
        var labels = {approved: '通过', waiting: '待审核', rejected: '拒绝'};
        return labels[decision] || decision || '';
    }

    function statusLabel(status) {
        var labels = {approved: '已通过', waiting: '待审核', spam: '垃圾', trash: '回收站'};
        return labels[status] || status || '';
    }

    function badge(decision) {
        var span = document.createElement('span');
        span.className = 'aicommentaudit-badge aicommentaudit-badge-' + decision;
        span.textContent = decisionLabel(decision);
        return span;
    }

    function formatCount(template, count) {
        return String(template || '').replace('%d', count);
    }

    // Settings tabs.
    var tabs = document.querySelectorAll('.aicommentaudit-tabs .nav-tab');
    var panels = document.querySelectorAll('.aicommentaudit-panel');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = tab.getAttribute('data-tab');
            tabs.forEach(function (item) {
                var active = item === tab;
                item.classList.toggle('nav-tab-active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-panel') !== target;
            });
        });
    });

    // Dirty-state hint in the sticky save bar.
    var settingsForm = document.querySelector('.aicommentaudit-settings-form');
    var saveState = document.getElementById('aicommentaudit-save-state');
    if (settingsForm && saveState) {
        var dirty = false;
        var markDirty = function () {
            if (dirty) return;
            dirty = true;
            saveState.textContent = '有未保存的更改';
            saveState.classList.add('is-dirty');
        };
        settingsForm.addEventListener('input', markDirty);
        settingsForm.addEventListener('change', markDirty);
        settingsForm.addEventListener('submit', function () {
            saveState.textContent = '正在保存…';
            saveState.classList.remove('is-dirty');
        });
    }

    // Test connection.
    var testButton = document.getElementById('aicommentaudit-test');
    var testResult = document.getElementById('aicommentaudit-test-result');
    if (testButton && testResult) {
        testButton.addEventListener('click', function () {
            testButton.disabled = true;
            testResult.textContent = t('testing', '测试中...');
            ajax('aicommentaudit_test_connection', {}).then(function (data) {
                var latency = data && data.result && Number(data.result.latency_ms) > 0
                    ? Number(data.result.latency_ms)
                    : 0;
                var message = data && data.success
                    ? t('testOk', '连接正常')
                    : ((data && data.message) || t('testFail', '连接失败，请检查设置'));
                if (latency > 0) {
                    message += ' · ' + latency + 'ms';
                }
                testResult.textContent = message;
                testButton.disabled = false;
            }).catch(function () {
                testResult.textContent = t('testFail', '连接失败，请检查设置');
                testButton.disabled = false;
            });
        });
    }

    // Model list.
    var modelsButton = document.getElementById('aicommentaudit-models');
    var modelInput = document.getElementById('aicommentaudit-model');
    var modelSelect = document.getElementById('aicommentaudit-model-select');
    var modelsStatus = document.getElementById('aicommentaudit-models-status');
    if (modelsButton && modelInput && modelSelect && modelsStatus) {
        modelsButton.addEventListener('click', function () {
            modelsButton.disabled = true;
            modelSelect.style.display = 'none';
            modelSelect.innerHTML = '';
            modelsStatus.textContent = t('fetching', '获取中…');

            ajax('aicommentaudit_models', {}).then(function (data) {
                if (!data || !data.success) {
                    modelsStatus.textContent = (data && data.message) || t('testFail', '获取失败');
                    return;
                }

                var models = data.models || [];
                if (!models.length) {
                    modelsStatus.textContent = t('noModels', '没有找到模型');
                    return;
                }

                var current = modelInput.value.trim();
                var placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = '选择模型…';
                modelSelect.appendChild(placeholder);

                var foundCurrent = false;
                models.forEach(function (id) {
                    var option = document.createElement('option');
                    option.value = id;
                    option.textContent = id;
                    modelSelect.appendChild(option);
                    if (id === current) {
                        foundCurrent = true;
                    }
                });

                if (current && !foundCurrent) {
                    var currentOption = document.createElement('option');
                    currentOption.value = current;
                    currentOption.textContent = current + '（当前）';
                    modelSelect.appendChild(currentOption);
                }

                modelSelect.value = current || '';
                modelSelect.style.display = '';
                modelsStatus.textContent = formatCount(t('modelsFound', '找到 %d 个模型，请选择'), models.length);
            }).catch(function () {
                modelsStatus.textContent = t('testFail', '请求失败');
            }).finally(function () {
                modelsButton.disabled = false;
            });
        });

        modelSelect.addEventListener('change', function () {
            if (modelSelect.value) {
                modelInput.value = modelSelect.value;
                modelsStatus.textContent = '已选择 ' + modelSelect.value;
            }
        });
    }

    // Log detail dialog.
    var dialog = document.getElementById('aicommentaudit-detail');
    var dialogBody = document.getElementById('aicommentaudit-detail-body');
    if (dialog && dialogBody) {
        document.querySelectorAll('.aicommentaudit-detail').forEach(function (button) {
            button.addEventListener('click', function () {
                var data;
                try {
                    data = JSON.parse(button.getAttribute('data-log') || '{}');
                } catch (error) {
                    return;
                }

                dialogBody.innerHTML = '';
                var rows = [
                    ['审核状态', data.decision_label || decisionLabel(data.decision)],
                    ['原因', data.reason || ''],
                    ['评论者', data.author || ''],
                    ['邮箱', data.email || ''],
                    ['IP', data.ip || ''],
                    ['信誉', data.trust || ''],
                    ['AI 决策 / 类别', (data.ai_decision || '-') + ' / ' + (data.ai_category || '-')],
                    ['AI 置信度 / 严重度', (data.ai_confidence != null ? Number(data.ai_confidence).toFixed(2) : '-') + ' / ' + (data.ai_severity || '-')],
                    ['AI 原因', data.ai_reason || ''],
                    ['身份检查', data.identity || ''],
                    ['身份检查依据', Array.isArray(data.identity_evidence) && data.identity_evidence.length ? data.identity_evidence.join('；') : '无'],
                    ['审核上下文', data.conversation || ''],
                    ['耗时 / tokens', (Number(data.latency) ? data.latency + 'ms' : '-') + ' / ' + (Number(data.tokens) || '-')],
                    ['评论 ID / 文章 ID', '#' + (data.comment_id || '-') + ' / #' + (data.post_id || '-')],
                    ['时间', data.created ? new Date(Number(data.created) * 1000).toLocaleString() : '-'],
                    ['人工处理', Number(data.manual) ? '是' : '否'],
                    ['评论内容', data.content || '（无内容）']
                ];

                rows.forEach(function (row) {
                    var wrapper = document.createElement('div');
                    wrapper.className = 'aicommentaudit-detail-row';
                    var label = document.createElement('strong');
                    label.textContent = row[0];
                    var value = document.createElement('span');
                    value.textContent = row[1];
                    wrapper.appendChild(label);
                    wrapper.appendChild(value);
                    dialogBody.appendChild(wrapper);
                });

                if (typeof dialog.showModal === 'function') {
                    if (dialog.open) {
                        dialog.close();
                    }
                    dialog.showModal();
                }
            });
        });

        dialog.querySelector('.aicommentaudit-modal-close').addEventListener('click', function () {
            dialog.close();
        });
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    }

    // History scan.
    var scanStart = document.getElementById('aicommentaudit-scan-start');
    var scanPause = document.getElementById('aicommentaudit-scan-pause');
    var scanStatus = document.getElementById('aicommentaudit-scan-status');
    var scanTable = document.getElementById('aicommentaudit-scan-table');
    var scanBody = scanTable ? scanTable.querySelector('tbody') : null;
    var bulkApply = document.getElementById('aicommentaudit-bulk-apply');
    var bulkAction = document.getElementById('aicommentaudit-bulk-action');
    var bulkStatus = document.getElementById('aicommentaudit-bulk-status');
    var selectAll = document.getElementById('aicommentaudit-select-all');

    var scan = {
        running: false,
        paused: false,
        after: 0,
        through: null,
        scope: 'approved',
        mode: 'local'
    };

    function appendResult(item) {
        if (!scanBody) return;

        var row = document.createElement('tr');
        row.setAttribute('data-id', item.id);

        var checkCell = document.createElement('td');
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'aicommentaudit-row-check';
        checkbox.value = item.id;
        checkCell.appendChild(checkbox);
        row.appendChild(checkCell);

        row.appendChild(textCell('#' + item.id));
        row.appendChild(textCell(item.post || ''));
        row.appendChild(textCell(item.author || ''));
        row.appendChild(textCell(item.excerpt || ''));
        row.appendChild(textCell(statusLabel(item.status)));

        var suggestionCell = document.createElement('td');
        suggestionCell.appendChild(badge(item.suggestion));
        row.appendChild(suggestionCell);

        var reasonCell = document.createElement('td');
        reasonCell.textContent = (item.reason || '') + (item.aiReason ? ' · ' + item.aiReason : '');
        if (item.identitySummary) {
            var identity = document.createElement('div');
            identity.className = 'aicommentaudit-muted';
            identity.textContent = item.identitySummary;
            reasonCell.appendChild(identity);
        }
        row.appendChild(reasonCell);

        var actionCell = document.createElement('td');
        [
            ['approve', '通过'],
            ['hold', '待审'],
            ['spam', '垃圾'],
            ['delete', '删除']
        ].forEach(function (action) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'button button-small';
            button.textContent = action[1];
            button.addEventListener('click', function () {
                if (action[0] === 'delete'
                    && !window.confirm(t('deleteConfirm', '确定删除选中的评论？此操作无法撤销。'))) {
                    return;
                }
                runModeration([{id: item.id, action: action[0]}], [row]);
            });
            actionCell.appendChild(button);
        });
        row.appendChild(actionCell);

        scanBody.appendChild(row);
    }

    function runModeration(changes, rows) {
        if (!changes.length) {
            if (bulkStatus) bulkStatus.textContent = t('selectFirst', '请先勾选评论');
            return;
        }

        if (bulkStatus) bulkStatus.textContent = t('processing', '处理中...');
        ajax('aicommentaudit_moderate_history', {
            changes: JSON.stringify(changes)
        }).then(function (data) {
            if (!data || !data.success) {
                if (bulkStatus) bulkStatus.textContent = (data && data.message) || t('error', '操作失败');
                return;
            }

            rows.forEach(function (row) {
                if (row && row.parentNode) row.parentNode.removeChild(row);
            });
            if (bulkStatus) bulkStatus.textContent = t('done', '完成');
        }).catch(function () {
            if (bulkStatus) bulkStatus.textContent = t('error', '操作失败');
        });
    }

    function scanLoop() {
        if (!scan.running || scan.paused) return;

        ajax('aicommentaudit_scan_history', {
            scope: scan.scope,
            mode: scan.mode,
            after: scan.after,
            through: scan.through == null ? '' : scan.through
        }).then(function (data) {
            if (!data || !data.success) {
                scan.running = false;
                if (scanStatus) scanStatus.textContent = (data && data.message) || t('error', '扫描失败');
                if (scanStart) scanStart.disabled = false;
                if (scanPause) scanPause.disabled = true;
                return;
            }

            (data.results || []).forEach(appendResult);
            scan.after = data.after;
            scan.through = data.through;

            if (data.done) {
                scan.running = false;
                if (scanStatus) scanStatus.textContent = t('scanDone', '扫描完成');
                if (scanStart) scanStart.disabled = false;
                if (scanPause) scanPause.disabled = true;
                if (scanPause) scanPause.textContent = '暂停';
                return;
            }

            if (data.pauseReason) {
                scan.running = false;
                if (scanStatus) scanStatus.textContent = data.pauseReason;
                if (scanStart) scanStart.disabled = false;
                if (scanPause) scanPause.disabled = true;
                return;
            }

            if (scan.running && !scan.paused) {
                scanLoop();
            }
        }).catch(function () {
            scan.running = false;
            if (scanStatus) scanStatus.textContent = t('error', '扫描失败');
            if (scanStart) scanStart.disabled = false;
            if (scanPause) scanPause.disabled = true;
        });
    }

    if (scanStart && scanPause && scanStatus && scanBody) {
        scanStart.addEventListener('click', function () {
            if (scan.running) return;

            var scope = document.getElementById('aicommentaudit-scan-scope');
            var mode = document.getElementById('aicommentaudit-scan-mode');
            scan.scope = scope ? scope.value : 'approved';
            scan.mode = mode ? mode.value : 'local';
            scan.after = 0;
            scan.through = null;
            scan.running = true;
            scan.paused = false;
            scanBody.innerHTML = '';
            scanStart.disabled = true;
            scanPause.disabled = false;
            scanPause.textContent = '暂停';
            scanStatus.textContent = t('scanning', '扫描中...');
            scanLoop();
        });

        scanPause.addEventListener('click', function () {
            if (!scan.running) return;
            scan.paused = !scan.paused;
            scanPause.textContent = scan.paused ? '继续' : '暂停';
            if (!scan.paused) {
                scanStatus.textContent = t('scanning', '扫描中...');
                scanLoop();
            } else {
                scanStatus.textContent = t('scanPaused', '已暂停');
            }
        });
    }

    if (bulkApply && bulkAction && scanBody) {
        bulkApply.addEventListener('click', function () {
            var action = bulkAction.value;
            if (action === 'delete' && !window.confirm(t('deleteConfirm', '确定删除选中的评论？此操作无法撤销。'))) {
                return;
            }

            var rows = [];
            var changes = [];
            scanBody.querySelectorAll('.aicommentaudit-row-check:checked').forEach(function (checkbox) {
                var row = checkbox.closest('tr');
                rows.push(row);
                changes.push({id: Number(checkbox.value), action: action});
            });

            runModeration(changes, rows);
        });
    }

    if (selectAll && scanBody) {
        selectAll.addEventListener('change', function () {
            scanBody.querySelectorAll('.aicommentaudit-row-check').forEach(function (checkbox) {
                checkbox.checked = selectAll.checked;
            });
        });
    }
})();
