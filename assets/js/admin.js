(function () {
    var discoverForm = document.getElementById('discoverForm');
    var discoverSummary = document.getElementById('discoverSummary');
    var discoverResults = document.getElementById('discoverResults');
    var discoverSubmit = document.getElementById('discoverSubmit');
    var cameraForm = document.getElementById('cameraForm');
    var cameraFormTitle = document.getElementById('cameraFormTitle');
    var currentResults = [];

    function setField(name, value) {
        if (!cameraForm) {
            return;
        }

        var field = cameraForm.elements[name];

        if (!field) {
            return;
        }

        if (field.type === 'checkbox') {
            field.checked = value ? true : false;
            return;
        }

        field.value = value;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderSummary(data) {
        if (!discoverSummary) {
            return;
        }

        var meta = data.meta || {};
        var text = 'Objetivo: ' + (data.resolved_target || data.requested_target || '-') + ' | ';
        text += 'Encontradas: ' + (data.items ? data.items.length : 0) + ' | ';
        text += 'Hosts escaneados: ' + (meta.hosts_scanned || 0) + ' | ';
        text += 'ONVIF: ' + (meta.onvif_items || 0) + ' | ';
        text += 'RTSP: ' + (meta.rtsp_items || 0) + ' | ';
        text += 'ARP: ' + (meta.known_hosts || 0) + ' | ';
        text += 'Tiempo: ' + (meta.elapsed_ms || 0) + 'ms';

        if (meta.truncated) {
            text += ' | Rango truncado para evitar esperas largas';
        }

        discoverSummary.innerHTML = escapeHtml(text);
    }

    function itemSourceText(item) {
        return item.source ? 'Origen: ' + item.source : '';
    }

    function itemUrlLine(item) {
        if (item.snapshot_url) {
            return 'Snapshot: ' + item.snapshot_url;
        }

        if (item.type === 'rtsp' && item.stream_url) {
            return 'RTSP: ' + item.stream_url;
        }

        if (item.stream_url) {
            return 'Stream: ' + item.stream_url;
        }

        if (item.embed_url) {
            return 'Web: ' + item.embed_url;
        }

        return '';
    }

    function renderResults(items) {
        if (!discoverResults) {
            return;
        }

        currentResults = items || [];

        if (!currentResults.length) {
            discoverResults.innerHTML = '<div class="discover-empty">No se detectaron camaras con los criterios actuales. Prueba el modo completo o agrega el puerto HTTP, HTTPS o RTSP del equipo.</div>';
            return;
        }

        var html = '';
        var i;

        for (i = 0; i < currentResults.length; i += 1) {
            var item = currentResults[i];
            html += ''
                + '<article class="discover-card">'
                + '<div class="discover-card__head">'
                + '<div>'
                + '<h3>' + escapeHtml(item.name || ('Camara ' + item.ip)) + '</h3>'
                + '<p>' + escapeHtml(item.ip + ':' + item.port + ' | ' + (item.confidence || '')) + '</p>'
                + '</div>'
                + '<span class="tag">' + escapeHtml(item.type || 'iframe') + '</span>'
                + '</div>'
                + '<p class="discover-card__meta">' + escapeHtml(item.vendor || 'Camara IP') + '</p>'
                + '<p class="discover-card__meta">' + escapeHtml(itemSourceText(item)) + '</p>'
                + '<p class="discover-card__meta">' + escapeHtml(itemUrlLine(item)) + '</p>'
                + '<p class="discover-card__meta">' + escapeHtml(item.note || '') + '</p>'
                + '<div class="discover-card__actions">'
                + '<button class="button button--ghost" type="button" data-use-result="' + i + '">Usar deteccion</button>'
                + '</div>'
                + '</article>';
        }

        discoverResults.innerHTML = html;
    }

    function fillFormFromResult(item) {
        var note = item.note || '';

        setField('id', '');
        setField('name', item.name || ('Camara ' + item.ip));
        setField('group', 'Auto detectadas');
        setField('location', 'LAN ' + item.ip);
        setField('type', item.type || 'iframe');
        setField('snapshot_url', item.snapshot_url || '');
        setField('stream_url', item.stream_url || '');
        setField('embed_url', item.embed_url || '');
        setField('host', item.host || item.ip || '');
        setField('port', item.port || 80);
        setField('note', note);
        setField('username', '');
        setField('password', '');
        setField('refresh_seconds', item.type === 'snapshot' ? 10 : 5);
        setField('use_proxy', item.type === 'snapshot' || item.type === 'mjpeg');
        setField('enabled', true);

        if (cameraFormTitle) {
            cameraFormTitle.innerHTML = 'Nueva camara desde deteccion';
        }

        if (cameraForm && cameraForm.scrollIntoView) {
            cameraForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function runDiscovery(query) {
        var xhr = new XMLHttpRequest();

        if (discoverSubmit) {
            discoverSubmit.disabled = true;
            discoverSubmit.innerHTML = 'Escaneando...';
        }

        if (discoverSummary) {
            discoverSummary.innerHTML = 'Ejecutando escaneo...';
        }

        xhr.open('GET', 'api/discover.php?' + query, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            if (discoverSubmit) {
                discoverSubmit.disabled = false;
                discoverSubmit.innerHTML = 'Escanear camaras';
            }

            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    renderSummary(data);
                    renderResults(data.items || []);
                    return;
                } catch (error) {
                }
            }

            if (discoverSummary) {
                discoverSummary.innerHTML = 'No fue posible completar el escaneo.';
            }
            if (discoverResults) {
                discoverResults.innerHTML = '';
            }
        };
        xhr.send();
    }

    if (discoverForm) {
        discoverForm.addEventListener('submit', function (event) {
            var parts = [];
            var i;

            event.preventDefault();

            for (i = 0; i < discoverForm.elements.length; i += 1) {
                var field = discoverForm.elements[i];
                if (!field.name || field.disabled) {
                    continue;
                }
                parts.push(encodeURIComponent(field.name) + '=' + encodeURIComponent(field.value));
            }

            runDiscovery(parts.join('&'));
        });

        discoverForm.addEventListener('click', function (event) {
            var target = event.target;

            if (!target || !target.getAttribute || target.getAttribute('data-discovery-target') === null) {
                return;
            }

            discoverForm.elements.target.value = target.getAttribute('data-discovery-target');
        });
    }

    if (discoverResults) {
        discoverResults.addEventListener('click', function (event) {
            var target = event.target;
            var index;

            if (!target || !target.getAttribute || target.getAttribute('data-use-result') === null) {
                return;
            }

            index = parseInt(target.getAttribute('data-use-result'), 10);

            if (!isNaN(index) && currentResults[index]) {
                fillFormFromResult(currentResults[index]);
            }
        });
    }
}());
