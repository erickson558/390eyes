(function () {
    var viewer = document.getElementById('viewer');
    var viewerContent = document.getElementById('viewerContent');
    var viewerRefreshTimer = null;
    var currentGroup = 'all';

    function hasClass(element, className) {
        return (' ' + element.className + ' ').indexOf(' ' + className + ' ') !== -1;
    }

    function addClass(element, className) {
        if (!hasClass(element, className)) {
            element.className += (element.className ? ' ' : '') + className;
        }
    }

    function removeClass(element, className) {
        element.className = (' ' + element.className + ' ')
            .replace(' ' + className + ' ', ' ')
            .replace(/^\s+|\s+$/g, '')
            .replace(/\s{2,}/g, ' ');
    }

    function appendCacheBust(url) {
        if (!url) {
            return '';
        }

        return url + (url.indexOf('?') === -1 ? '?' : '&') + '_ts=' + new Date().getTime();
    }

    function refreshSnapshot(img) {
        var mode = img.getAttribute('data-feed-mode');
        if (mode !== 'snapshot') {
            return;
        }

        var source = img.getAttribute('data-base-src');
        img.src = appendCacheBust(source);
    }

    function scheduleSnapshots() {
        var images = document.querySelectorAll('.camera-feed');
        var i;

        for (i = 0; i < images.length; i += 1) {
            (function (img) {
                var seconds = parseInt(img.getAttribute('data-refresh-seconds'), 10) || 10;
                refreshSnapshot(img);
                if (img.getAttribute('data-feed-mode') === 'snapshot') {
                    window.setInterval(function () {
                        refreshSnapshot(img);
                    }, seconds * 1000);
                }
            }(images[i]));
        }
    }

    function updateSummary(statusItems) {
        var online = 0;
        var offline = 0;
        var i;
        var summary = document.getElementById('summaryOnline');

        for (i = 0; i < statusItems.length; i += 1) {
            if (statusItems[i].online) {
                online += 1;
            } else {
                offline += 1;
            }
        }

        if (summary) {
            summary.textContent = online + ' OK / ' + offline + ' OFF';
        }
    }

    function applyStatuses(payload) {
        if (!payload || !payload.items) {
            return;
        }

        var i;
        for (i = 0; i < payload.items.length; i += 1) {
            var item = payload.items[i];
            var badge = document.querySelector('[data-status-id="' + item.id + '"]');

            if (!badge) {
                continue;
            }

            var card = badge.closest ? badge.closest('.camera-card') : badge.parentNode.parentNode;
            badge.className = 'status-badge ' + (item.online ? 'status-badge--online' : 'status-badge--offline');
            badge.textContent = item.online ? 'Online ' + item.latency_ms + 'ms' : 'Offline';
            badge.title = item.message || '';

            if (card) {
                removeClass(card, 'is-online');
                removeClass(card, 'is-offline');
                addClass(card, item.online ? 'is-online' : 'is-offline');
            }
        }

        updateSummary(payload.items);
    }

    function pollStatuses() {
        var xhr = new XMLHttpRequest();

        xhr.open('GET', 'api/status.php', true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    applyStatuses(JSON.parse(xhr.responseText));
                } catch (error) {
                }
            }
        };
        xhr.send();
    }

    function clearViewer() {
        if (viewerRefreshTimer) {
            window.clearInterval(viewerRefreshTimer);
            viewerRefreshTimer = null;
        }

        if (viewerContent) {
            viewerContent.innerHTML = '';
        }
    }

    function openViewer(card) {
        clearViewer();

        var kind = card.getAttribute('data-view-kind');
        var type = card.getAttribute('data-camera-type');
        var url = card.getAttribute('data-view-url');
        var refresh = parseInt(card.getAttribute('data-view-refresh'), 10) || 10;
        var element;
        var link;
        var message;

        if (!viewer || !viewerContent) {
            return;
        }

        if (kind === 'iframe') {
            if (!url) {
                return;
            }
            element = document.createElement('iframe');
            element.src = url;
        } else if (kind === 'video') {
            if (!url) {
                return;
            }
            element = document.createElement('video');
            element.src = url;
            element.controls = true;
            element.autoplay = true;
            element.muted = true;
            element.setAttribute('playsinline', 'playsinline');
        } else if (kind === 'notice') {
            element = document.createElement('div');
            element.className = 'viewer__notice';

            message = document.createElement('p');
            message.innerHTML = 'Esta camara expone <strong>RTSP</strong>. El panel la detecta y monitorea, pero para verla en navegador necesitas convertirla a MJPEG, HLS o WebRTC.';
            element.appendChild(message);

            if (url) {
                link = document.createElement('a');
                link.href = url;
                link.target = '_blank';
                link.className = 'button button--ghost';
                link.appendChild(document.createTextNode('Abrir RTSP'));
                element.appendChild(link);
            }
        } else {
            if (!url) {
                return;
            }
            element = document.createElement('img');
            element.src = appendCacheBust(url);
            if (type === 'snapshot') {
                viewerRefreshTimer = window.setInterval(function () {
                    element.src = appendCacheBust(url);
                }, refresh * 1000);
            } else {
                element.src = url;
            }
        }

        viewerContent.appendChild(element);
        viewer.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeViewer() {
        if (!viewer) {
            return;
        }

        viewer.hidden = true;
        document.body.style.overflow = '';
        clearViewer();
    }

    function applyFilters() {
        var queryField = document.getElementById('searchInput');
        var cards = document.querySelectorAll('.camera-card');
        var query = queryField ? queryField.value.toLowerCase() : '';
        var i;

        for (i = 0; i < cards.length; i += 1) {
            var card = cards[i];
            var matchesGroup = currentGroup === 'all' || card.getAttribute('data-group') === currentGroup;
            var bag = (card.getAttribute('data-name') || '') + ' ' + (card.getAttribute('data-group') || '').toLowerCase() + ' ' + (card.getAttribute('data-location') || '');
            var matchesQuery = bag.indexOf(query) !== -1;
            if (matchesGroup && matchesQuery) {
                removeClass(card, 'is-hidden');
            } else {
                addClass(card, 'is-hidden');
            }
        }
    }

    function bindFilters() {
        var groupFilters = document.getElementById('groupFilters');
        var searchInput = document.getElementById('searchInput');

        if (groupFilters) {
            groupFilters.addEventListener('click', function (event) {
                var target = event.target;
                var buttons;
                var i;

                if (!target || !target.getAttribute('data-group')) {
                    return;
                }

                currentGroup = target.getAttribute('data-group');
                buttons = groupFilters.querySelectorAll('[data-group]');
                for (i = 0; i < buttons.length; i += 1) {
                    removeClass(buttons[i], 'is-active');
                }
                addClass(target, 'is-active');
                applyFilters();
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }
    }

    function findClosestOpenButton(target) {
        while (target && target !== document) {
            if (target.getAttribute && target.getAttribute('data-open-viewer') !== null) {
                return target;
            }
            target = target.parentNode;
        }

        return null;
    }

    function findClosestCard(target) {
        while (target && target !== document) {
            if (target.className && String(target.className).indexOf('camera-card') !== -1) {
                return target;
            }
            target = target.parentNode;
        }

        return null;
    }

    function bindViewer() {
        document.addEventListener('click', function (event) {
            var target = event.target;
            var openButton;
            var card;

            if (target && target.getAttribute && target.getAttribute('data-close-viewer') !== null) {
                closeViewer();
                return;
            }

            openButton = findClosestOpenButton(target);
            if (openButton) {
                card = findClosestCard(openButton);
                if (card) {
                    openViewer(card);
                }
            }
        });

        document.addEventListener('keyup', function (event) {
            if (event.keyCode === 27) {
                closeViewer();
            }
        });
    }

    scheduleSnapshots();
    bindFilters();
    bindViewer();
    pollStatuses();
    window.setInterval(pollStatuses, 15000);
}());
