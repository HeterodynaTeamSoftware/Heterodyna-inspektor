(function($){

  // --- Explorer (graf zależności) ---
  var cy = null;
  var fg3d = null;
  var fg3dLoaded = false;
  var lastGraphData = null;
  var graphTheme = 'auto';

  function getThemeSetting(){
    try{
      var v = localStorage.getItem('het_inspector_graph_theme');
      if(v) return v;
    } catch(e) {}
    return 'auto';
  }

  function setThemeSetting(v){
    try{ localStorage.setItem('het_inspector_graph_theme', v); } catch(e) {}
  }

  function resolveTheme(v){
    v = v || 'auto';
    if(v === 'auto'){
      try{
        if(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches){
          return 'dark';
        }
      } catch(e) {}
      return 'light';
    }
    if(v === 'contrast') return 'contrast';
    return v;
  }

  function themeCfg(v){
    var t = resolveTheme(v);
    if(t === 'dark'){
      return {
        mode: 'dark',
        bg: '#0f1115',
        label: '#ffffff',
        outline: '#000000',
        outlineWidth: 2,
        edge: '#7b8190'
      };
    }
    if(t === 'contrast'){
      return {
        mode: 'contrast',
        bg: '#000000',
        label: '#ffffff',
        outline: '#000000',
        outlineWidth: 3,
        edge: '#aeb4c2'
      };
    }
    // light
    return {
      mode: 'light',
      bg: '#ffffff',
      label: '#000000',
      outline: '#ffffff',
      outlineWidth: 2,
      edge: '#6b7280'
    };
  }

  function loadScriptOnce(url){
    return new Promise(function(resolve, reject){
      var existing = document.querySelector('script[data-het-src="'+url+'"]');
      if(existing){ resolve(); return; }
      var s = document.createElement('script');
      s.async = true;
      s.src = url;
      s.setAttribute('data-het-src', url);
      s.onload = function(){ resolve(); };
      s.onerror = function(){ reject(new Error('Nie udało się załadować: ' + url)); };
      document.head.appendChild(s);
    });
  }

  function fetchGraph(days){
    return $.post(HET_INSPECTOR.ajax_url, { action:'het_inspector_graph', nonce:HET_INSPECTOR.nonce, days: days || 7 });
  }

  function normalizeGraph(res){
    if(!res || !res.success || !res.data) return null;
    var d = res.data;
    var nodes = (d.nodes || []).map(function(n){
      return { id:n.id, label:n.label, type:n.type };
    });
    var links = (d.edges || []).map(function(e){
      return { source:e.source, target:e.target, type:e.type };
    });
    return { nodes:nodes, links:links };
  }

  function applyFilterToGraphData(data, q){
    q = (q || '').trim().toLowerCase();
    if(!q) return data;
    var keep = {};
    data.nodes.forEach(function(n){
      if((n.label||'').toLowerCase().indexOf(q) !== -1 || (n.id||'').toLowerCase().indexOf(q) !== -1){
        keep[n.id] = true;
      }
    });
    // keep neighbors
    data.links.forEach(function(l){
      if(keep[l.source] || keep[l.target]){ keep[l.source] = true; keep[l.target] = true; }
    });
    return {
      nodes: data.nodes.filter(function(n){ return keep[n.id]; }),
      links: data.links.filter(function(l){ return keep[l.source] && keep[l.target]; })
    };
  }

  function render2D(data){
    if(typeof cytoscape === 'undefined') return;
    var container = document.getElementById('het-graph-2d');
    if(!container) return;
    var cfg = themeCfg(graphTheme);
    container.style.background = cfg.bg;
    var elements = [];
    data.nodes.forEach(function(n){ elements.push({ data:{ id:n.id, label:n.label, type:n.type } }); });
    data.links.forEach(function(l, idx){ elements.push({ data:{ id:'e'+idx, source:l.source, target:l.target, type:l.type } }); });
    cy = cytoscape({
      container: container,
      elements: elements,
      layout: { name:'cose', animate:false, fit:true, padding:20, nodeRepulsion: 9000 },
      style: [
        { selector:'node', style:{
          'label':'data(label)',
          'font-size':10,
          'color': cfg.label,
          'text-outline-color': cfg.outline,
          'text-outline-width': cfg.outlineWidth,
          'text-wrap':'wrap',
          'text-max-width':120,
          'width':18,
          'height':18,
          'background-color':'#6b7280'
        } },
        { selector:'node[type = "plugin"]', style:{ 'shape':'round-rectangle', 'width':34, 'height':18, 'font-weight':'600', 'background-color':'#7a00df' } },
        { selector:'node[type = "table"]', style:{ 'shape':'ellipse', 'background-color':'#2d9cdb' } },
        { selector:'node[type = "endpoint"]', style:{ 'shape':'diamond', 'background-color':'#27ae60' } },
        { selector:'node[type = "hook"]', style:{ 'shape':'hexagon', 'background-color':'#f2c94c' } },
        { selector:'edge', style:{ 'width':1, 'curve-style':'bezier', 'target-arrow-shape':'triangle', 'arrow-scale':0.7, 'line-color': cfg.edge, 'target-arrow-color': cfg.edge } }
      ]
    });
  }

  function render3D(data){
    var container = document.getElementById('het-graph-3d');
    if(!container) return;
    if(typeof ForceGraph3D === 'undefined') return;
    var cfg = themeCfg(graphTheme);
    container.innerHTML = '';
    fg3d = ForceGraph3D()(container)
      .graphData(data)
      .nodeLabel(function(n){ return n.label; })
      .linkLabel(function(l){ return l.type; })
      .nodeAutoColorBy('type')
      .linkAutoColorBy('type')
      .backgroundColor(cfg.bg);

    // Jeśli mamy SpriteText, pokazujemy etykiety zawsze.
    if(typeof SpriteText !== 'undefined'){
      fg3d
        .nodeThreeObject(function(n){
          var s = new SpriteText(n.label);
          s.color = cfg.label;
          s.textHeight = 8;
          s.padding = 2;
          s.backgroundColor = 'rgba(0,0,0,0)';
          return s;
        })
        .nodeThreeObjectExtend(true);
    }

    fg3d.onNodeClick(function(node){
      if(!node) return;
      var dist = 90;
      var d = Math.hypot(node.x || 0, node.y || 0, node.z || 0);
      var ratio = 1 + dist / (d || 1);
      fg3d.cameraPosition(
        { x:(node.x||0)*ratio, y:(node.y||0)*ratio, z:(node.z||0)*ratio },
        node,
        900
      );
    });
  }

  function setMode(mode){
    var el2d = document.getElementById('het-graph-2d');
    var el3d = document.getElementById('het-graph-3d');
    if(!el2d || !el3d) return;
    if(mode === '3d'){
      el2d.style.display = 'none';
      el3d.style.display = 'block';
    } else {
      el3d.style.display = 'none';
      el2d.style.display = 'block';
    }
  }

  function refreshExplorer(){
    var days = parseInt($('#het-x-days').val(), 10) || 7;
    var q = $('#het-x-filter').val() || '';
    $('#het-x-status').text('Ładowanie danych…');
    fetchGraph(days).done(function(res){
      var d = normalizeGraph(res);
      if(!d){ $('#het-x-status').text('Błąd pobierania danych.'); return; }
      lastGraphData = d;
      var filtered = applyFilterToGraphData(d, q);
      render2D(filtered);
      if(fg3dLoaded && fg3d){ render3D(filtered); }
      $('#het-x-status').text('OK: ' + filtered.nodes.length + ' węzłów • ' + filtered.links.length + ' połączeń');
    }).fail(function(){
      $('#het-x-status').text('Błąd połączenia (AJAX).');
    });
  }
  function pill(level){
    var cls = 'het-pill het-pill--info';
    if(level === 'fatal') cls = 'het-pill het-pill--fatal';
    else if(level === 'error' || level === 'performance') cls = 'het-pill het-pill--error';
    else if(level === 'warning') cls = 'het-pill het-pill--warning';
    return '<span class="'+cls+'">'+level+'</span>';
  }

  function renderIncidents(rows){
    if(!rows || !rows.length){
      return '<div>Brak incydentów.</div>';
    }
    var html = '<table class="het-table"><thead><tr><th>Data</th><th>Poziom</th><th>Wtyczka</th><th>Opis</th><th>Plik</th></tr></thead><tbody>';
    rows.forEach(function(r){
      var file = r.file_path ? (r.file_path + (r.line_no ? (':' + r.line_no) : '')) : '—';
      html += '<tr>'+
        '<td>'+ (r.created_at || '') +'</td>'+
        '<td>'+ pill(r.level) +'</td>'+
        '<td><code>'+ (r.source_slug || '—') +'</code></td>'+
        '<td>'+ (r.message || '') +'</td>'+
        '<td><code>'+ file +'</code></td>'+
      '</tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function renderConflicts(rows){
    if(!rows || !rows.length){
      return '<div>Brak konfliktów (lub jeszcze brak ticku).</div>';
    }
    var html = '<table class="het-table"><thead><tr><th>Data</th><th>Wtyczka/rdzeń</th><th>Opis</th><th>Rekomendacja</th></tr></thead><tbody>';
    rows.forEach(function(r){
      html += '<tr>'+
        '<td>'+ (r.created_at || '') +'</td>'+
        '<td><code>'+ (r.plugin_slug || '') +'</code></td>'+
        '<td>'+ (r.title || '') +'</td>'+
        '<td>'+ (r.recommendation || '') +'</td>'+
      '</tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function fetchStats(){
    return $.post(HET_INSPECTOR.ajax_url, { action:'het_inspector_stats', nonce:HET_INSPECTOR.nonce })
      .done(function(res){
        if(!res || !res.success) return;
        $('#m-fatal').text(res.data.fatal);
        $('#m-error').text(res.data.error);
        $('#m-warning').text(res.data.warning);
        $('#m-conflicts').text(res.data.conflicts);
        $('#het-inspector-last-run').text(res.data.last_run || '—');
        $('#het-inspector-health-meta').text('Ostatnie odświeżenie: ' + (new Date()).toLocaleTimeString());
      });
  }

  function fetchIncidents(){
    return $.post(HET_INSPECTOR.ajax_url, { action:'het_inspector_incidents', nonce:HET_INSPECTOR.nonce })
      .done(function(res){
        if(!res || !res.success) return;
        $('#het-incidents').html(renderIncidents(res.data.rows));
      });
  }

  function fetchConflicts(){
    return $.post(HET_INSPECTOR.ajax_url, { action:'het_inspector_conflicts', nonce:HET_INSPECTOR.nonce })
      .done(function(res){
        if(!res || !res.success) return;
        $('#het-conflicts').html(renderConflicts(res.data.rows));
      });
  }

  function refreshAll(){
    fetchStats();
    fetchIncidents();
    fetchConflicts();
  }

  // --- Bezpieczeństwo ---
  function pillSeverity(sev){
    sev = (sev||'').toLowerCase();
    var cls = 'het-pill het-pill--info';
    if(sev === 'critical') cls = 'het-pill het-pill--fatal';
    else if(sev === 'high') cls = 'het-pill het-pill--error';
    else if(sev === 'medium') cls = 'het-pill het-pill--warning';
    return '<span class="'+cls+'">'+sev+'</span>';
  }

  function renderSecurityIssues(rows){
    if(!rows || !rows.length){ return '<div>Brak wyników. Uruchom pełny skan lub poczekaj na tick.</div>'; }
    var html = '<table class="het-table"><thead><tr><th>Severity</th><th>Wtyczka</th><th>Opis</th><th>Szczegóły</th><th>Rekomendacja</th></tr></thead><tbody>';
    rows.forEach(function(r){
      var details = '';
      try{
        var d = r.details ? JSON.parse(r.details) : null;
        if(d){
          details = '<div><code>'+(d.file||'')+(d.line?(':'+d.line):'')+'</code></div>' +
                    (d.rule?('<div><code>'+d.rule+'</code></div>'):'') +
                    (d.snippet?('<div class="het-snippet">'+String(d.snippet).replace(/</g,'&lt;')+'</div>'):'');
        }
      }catch(e){ details = r.details || ''; }
      html += '<tr>'+
        '<td>'+ pillSeverity(r.severity) +'</td>'+
        '<td><code>'+ (r.plugin_slug||'') +'</code></td>'+
        '<td>'+ (r.title||'') +'</td>'+
        '<td>'+ details +'</td>'+
        '<td>'+ (r.recommendation||'') +'</td>'+
      '</tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function fetchSecurityStats(){
    return $.post(HET_INSPECTOR.ajax_url, { action:'het_inspector_security_stats', nonce:HET_INSPECTOR.nonce })
      .done(function(res){
        if(!res || !res.success) return;
        $('#sec-critical').text(res.data.critical);
        $('#sec-high').text(res.data.high);
        $('#sec-medium').text(res.data.medium);
        $('#sec-low').text(res.data.low);
        $('#het-sec-meta').text('Ostatnie odświeżenie: ' + (new Date()).toLocaleTimeString());
        var s = (res.data.state || {});
        var p = 'Stan: ' + (s.active ? 'SKAN TRWA' : 'idle');
        if(s.current_slug){ p += ' • ' + s.current_slug; }
        if(s.processed_files){ p += ' • plików: ' + s.processed_files; }
        if(s.last_msg){ p += ' • ' + s.last_msg; }
        $('#het-sec-progress').text(p);
      });
  }

  function fetchSecurityIssues(){
    return $.post(HET_INSPECTOR.ajax_url, { action:'het_inspector_security_issues', nonce:HET_INSPECTOR.nonce })
      .done(function(res){
        if(!res || !res.success) return;
        $('#het-sec-issues').html(renderSecurityIssues(res.data.rows));
      });
  }

  function startSecurityScan(){
    return $.post(HET_INSPECTOR.ajax_url, { action:'het_inspector_security_start', nonce:HET_INSPECTOR.nonce })
      .done(function(){
        fetchSecurityStats();
        fetchSecurityIssues();
      });
  }

  // --- Wydajność (Pack 4) ---
  function renderAutoloadTable(data){
    var top = (data && data.autoload && data.autoload.top) ? data.autoload.top : [];
    if(!top.length){ return '<div>Brak danych (poczekaj na tick) lub brak uprawnień.</div>'; }
    var html = '<table class="het-table"><thead><tr><th>KB</th><th>option_name</th></tr></thead><tbody>';
    top.forEach(function(r){
      html += '<tr><td><strong>'+ (r.kb||0) +'</strong></td><td><code>'+ (r.option_name||'') +'</code></td></tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function renderCronTable(data){
    var c = (data && data.cron) ? data.cron : {};
    var html = '<table class="het-table"><thead><tr><th>Metryka</th><th>Wartość</th></tr></thead><tbody>';
    html += '<tr><td>Hooki</td><td><strong>'+ (c.hooks||0) +'</strong></td></tr>';
    html += '<tr><td>Eventy</td><td><strong>'+ (c.events||0) +'</strong></td></tr>';
    html += '</tbody></table>';
    return html;
  }

  function renderSlowTable(rows){
    rows = rows || [];
    if(!rows.length){ return '<div>Brak danych. Włącz <code>SAVEQUERIES</code> i poczekaj na ruch / tick.</div>'; }
    var html = '<table class="het-table"><thead><tr><th>Data</th><th>Wtyczka</th><th>Opis</th><th>MS</th><th>Tabele</th></tr></thead><tbody>';
    rows.forEach(function(r){
      var ctx = {};
      try{ ctx = r.context ? JSON.parse(r.context) : {}; }catch(e){}
      var ms = ctx && ctx.ms ? ctx.ms : '';
      var tables = (ctx && ctx.tables && ctx.tables.length) ? ctx.tables.join(', ') : '';
      html += '<tr>'+
        '<td>'+ (r.created_at||'') +'</td>'+
        '<td><code>'+ (r.source_slug||'') +'</code></td>'+
        '<td>'+ (r.message||'') +'</td>'+
        '<td><strong>'+ ms +'</strong></td>'+
        '<td><code>'+ tables +'</code></td>'+
      '</tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function refreshPerformance(){
    if(!$('#perf-slow').length) return;
    $.post(HET_INSPECTOR.ajax_url, { action:'het_inspector_perf_stats', nonce:HET_INSPECTOR.nonce })
      .done(function(res){
        if(!res || !res.success) return;
        $('#perf-slow').text(res.data.slow_24h);
        $('#perf-autoload').text(res.data.autoload_total_kb);
        $('#perf-cron-hooks').text(res.data.cron_hooks);
        $('#perf-cron-events').text(res.data.cron_events);
        $('#het-perf-last').text(res.data.last_perf ? (''+res.data.last_perf) : '—');
        var note = 'Ostatnie odświeżenie: ' + (new Date()).toLocaleTimeString();
        if(!res.data.savequeries){ note += ' • SAVEQUERIES=OFF'; }
        $('#het-perf-meta').text(note);
      });

    $.post(HET_INSPECTOR.ajax_url, { action:'het_inspector_perf_details', nonce:HET_INSPECTOR.nonce })
      .done(function(res){
        if(!res || !res.success) return;
        $('#het-perf-autoload-table').html(renderAutoloadTable(res.data.perf||{}));
        $('#het-perf-cron').html(renderCronTable(res.data.perf||{}));
        $('#het-perf-slow-table').html(renderSlowTable(res.data.slow_rows||[]));
      });
  }

  $(document).ready(function(){
    refreshAll();
    var ms = Math.max(15000, (parseInt(HET_INSPECTOR.refresh, 10) || 60) * 1000);
    setInterval(refreshAll, ms);

    // Explorer init (jeśli jesteśmy na stronie eksploratora)
    if($('#het-graph-2d').length){
      // motyw grafu
      graphTheme = getThemeSetting();
      if($('#het-x-theme').length){
        $('#het-x-theme').val(graphTheme);
      }
      setMode('2d');
      refreshExplorer();

      $('#het-x-refresh').on('click', function(){ refreshExplorer(); });
      $('#het-x-filter').on('input', function(){
        if(!lastGraphData){ return; }
        var q = $('#het-x-filter').val() || '';
        var filtered = applyFilterToGraphData(lastGraphData, q);
        render2D(filtered);
        if(fg3dLoaded && fg3d){ render3D(filtered); }
      });
      $('#het-x-days').on('change', function(){ refreshExplorer(); });

      $('#het-x-theme').on('change', function(){
        graphTheme = $('#het-x-theme').val() || 'auto';
        setThemeSetting(graphTheme);
        if(!lastGraphData){ return; }
        var qT = $('#het-x-filter').val() || '';
        var filteredT = applyFilterToGraphData(lastGraphData, qT);
        render2D(filteredT);
        if(fg3dLoaded && fg3d){ render3D(filteredT); }
      });

      $('#het-x-mode-2d').on('click', function(){ setMode('2d'); });
      $('#het-x-mode-3d').on('click', function(){
        setMode('3d');
        if(!fg3dLoaded){
          $('#het-x-status').text('Ładowanie trybu 3D…');
          Promise.all([
            loadScriptOnce('https://unpkg.com/three@0.160.0/build/three.min.js'),
            loadScriptOnce('https://unpkg.com/3d-force-graph@1.73.1/dist/3d-force-graph.min.js'),
            loadScriptOnce('https://unpkg.com/three-spritetext@1.9.2/dist/three-spritetext.min.js')
          ]).then(function(){
            fg3dLoaded = true;
            var q = $('#het-x-filter').val() || '';
            var d = lastGraphData ? applyFilterToGraphData(lastGraphData, q) : {nodes:[],links:[]};
            render3D(d);
            $('#het-x-status').text('Tryb 3D gotowy.');
          }).catch(function(err){
            $('#het-x-status').text('Nie udało się załadować 3D: ' + (err && err.message ? err.message : 'błąd'));
          });
        } else {
          var q2 = $('#het-x-filter').val() || '';
          var d2 = lastGraphData ? applyFilterToGraphData(lastGraphData, q2) : {nodes:[],links:[]};
          render3D(d2);
        }
      });

      // auto refresh explorer co minutę
      setInterval(function(){
        if(document.hidden) return;
        refreshExplorer();
      }, ms);
    }

    // Security init (jeśli jesteśmy na stronie bezpieczeństwa)
    if($('#het-sec-issues').length){
      fetchSecurityStats();
      fetchSecurityIssues();
      setInterval(function(){
        if(document.hidden) return;
        fetchSecurityStats();
        fetchSecurityIssues();
      }, ms);
      $('#het-sec-start').on('click', function(){
        $('#het-sec-progress').text('Uruchamianie skanu…');
        startSecurityScan();
      });
    }

    // Performance init (jeśli jesteśmy na stronie wydajności)
    if($('#perf-slow').length){
      refreshPerformance();
      setInterval(function(){
        if(document.hidden) return;
        refreshPerformance();
      }, ms);
    }

    // AI init (jeśli jesteśmy na stronie AI)
    if($('#het-ai-settings').length){
      function renderAISettings(s){
        s = s || {};
        var enabled = s.enabled ? 1 : 0;
        var provider = s.provider || 'local';
        var endpoint = s.endpoint_url || '';
        var apiKey = s.api_key || '';
        var model = s.model || '';
        var interval = parseInt(s.run_interval_min,10) || 30;
        var html = '';
        html += '<label><input type="checkbox" id="ai-enabled" '+(enabled? 'checked':'')+'> Włącz generowanie w tle</label>';
        html += '<label>Dostawca</label>';
        html += '<select id="ai-provider">'+
          '<option value="local"'+(provider==='local'?' selected':'')+'>Lokalnie (heurystyki)</option>'+
          '<option value="http"'+(provider==='http'?' selected':'')+'>HTTP endpoint (zewnętrzny LLM)</option>'+
        '</select>';
        html += '<label>Endpoint URL (dla HTTP)</label><input type="text" id="ai-endpoint" value="'+endpoint.replace(/"/g,'&quot;')+'" placeholder="https://.../ai" />';
        html += '<label>API key (opcjonalnie)</label><input type="text" id="ai-key" value="'+apiKey.replace(/"/g,'&quot;')+'" placeholder="(puste = bez nagłówka Authorization)" />';
        html += '<label>Model (opcjonalnie)</label><input type="text" id="ai-model" value="'+model.replace(/"/g,'&quot;')+'" placeholder="np. gpt-4.1-mini" />';
        html += '<label>Interwał w tle (min)</label><input type="number" min="5" max="240" id="ai-interval" value="'+interval+'" />';
        html += '<div style="margin-top:10px"><button class="button button-primary" id="ai-save">Zapisz</button> <span id="ai-save-status" style="margin-left:8px"></span></div>';
        return html;
      }

      function fetchAISettings(){
        return $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_ai_settings_get', nonce:HET_INSPECTOR.nonce})
          .done(function(res){
            if(!res || !res.success) return;
            $('#het-ai-settings').html(renderAISettings(res.data.settings));
          });
      }

      function saveAISettings(){
        var payload = {
          action:'het_inspector_ai_settings_save',
          nonce:HET_INSPECTOR.nonce,
          enabled: $('#ai-enabled').is(':checked') ? 1 : 0,
          provider: $('#ai-provider').val(),
          endpoint_url: $('#ai-endpoint').val(),
          api_key: $('#ai-key').val(),
          model: $('#ai-model').val(),
          run_interval_min: $('#ai-interval').val()
        };
        $('#ai-save-status').text('Zapisywanie…');
        return $.post(HET_INSPECTOR.ajax_url, payload).done(function(res){
          if(res && res.success){
            $('#ai-save-status').text('Zapisano ✅');
            fetchAISettings();
          } else {
            $('#ai-save-status').text('Błąd zapisu');
          }
        }).fail(function(){ $('#ai-save-status').text('Błąd AJAX'); });
      }

      function fetchAIHistory(){
        return $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_ai_history', nonce:HET_INSPECTOR.nonce}).done(function(res){
          if(!res || !res.success) return;
          var rows = res.data.rows || [];
          if(!rows.length){ $('#het-ai-history').html('<div>Brak raportów.</div>'); return; }
          var html = '<table class="het-table"><thead><tr><th>Data</th><th>Zakres</th><th>Wtyczka</th><th>Dostawca</th><th>Model</th></tr></thead><tbody>';
          rows.forEach(function(r){
            html += '<tr><td>'+ (r.created_at||'') +'</td><td><code>'+ (r.scope||'') +'</code></td><td><code>'+ (r.plugin_slug||'') +'</code></td><td>'+ (r.provider||'') +'</td><td>'+ (r.model||'') +'</td></tr>';
          });
          html += '</tbody></table>';
          $('#het-ai-history').html(html);
        });
      }

      function generateAI(){
        var scope = $('#het-ai-scope').val();
        var plugin = ($('#het-ai-plugin').val()||'').trim();
        if(scope==='plugin' && !plugin){
          $('#het-ai-status').text('Podaj plugin_slug dla zakresu "Tylko jedna wtyczka".');
          return;
        }
        $('#het-ai-status').text('Generowanie…');
        $('#het-ai-result').html('<em>Generowanie…</em>');
        $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_ai_generate', nonce:HET_INSPECTOR.nonce, scope:scope, plugin_slug:plugin, mode:'manual'})
          .done(function(res){
            if(!res || !res.success){ $('#het-ai-status').text('Błąd.'); return; }
            var rep = res.data.report || {};
            $('#het-ai-status').text('OK • ' + (rep.created_at||'') + ' • ' + (rep.provider||'local'));
            $('#het-ai-result').text(rep.result || '—');
            fetchAIHistory();
          }).fail(function(){ $('#het-ai-status').text('Błąd AJAX'); });
      }

      fetchAISettings().then(function(){
        $(document).on('click', '#ai-save', function(e){ e.preventDefault(); saveAISettings(); });
      });
      fetchAIHistory();
      $('#het-ai-generate').on('click', function(e){ e.preventDefault(); generateAI(); });
      setInterval(function(){ if(document.hidden) return; fetchAIHistory(); }, ms);
    }
  });


  // --- Snapshoty + Diff + Preflight (Pack 6) ---
  function renderSnapshotsList(rows){
    if(!rows || !rows.length){ return '<p>Brak snapshotów.</p>'; }
    var html = '<table class="widefat striped"><thead><tr><th>ID</th><th>Typ</th><th>Data</th><th>Hash</th></tr></thead><tbody>';
    rows.forEach(function(r){
      html += '<tr><td>'+r.id+'</td><td>'+r.snapshot_type+'</td><td>'+r.created_at+'</td><td><code>'+r.payload_hash.substring(0,12)+'</code></td></tr>';
    });
    html += '</tbody></table>';
    return html;
  }

  function refreshSnapshots(){
    var type = $('#het-snap-type').val() || 'plugins';
    return $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_snapshots_list', nonce:HET_INSPECTOR.nonce, type:type})
      .done(function(res){
        if(!res || !res.success){ $('#het-snapshots-list').html('<p>Błąd.</p>'); return; }
        var rows = res.data.rows || [];
        $('#het-snapshots-list').html(renderSnapshotsList(rows));

        // wypełnij selecty A/B
        var opts = rows.map(function(r){ return '<option value="'+r.id+'">#'+r.id+' • '+r.created_at+'</option>'; }).join('');
        $('#het-diff-a').html(opts);
        $('#het-diff-b').html(opts);
      });
  }

  function runDiff(){
    var a = $('#het-diff-a').val();
    var b = $('#het-diff-b').val();
    if(!a || !b){ $('#het-snap-diff').html('<p>Wybierz A i B.</p>'); return; }
    $('#het-snap-diff').html('Ładowanie…');
    $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_snapshots_diff', nonce:HET_INSPECTOR.nonce, a:a, b:b})
      .done(function(res){
        if(!res || !res.success){ $('#het-snap-diff').html('<p>Błąd diff.</p>'); return; }
        var d = res.data.diff || {};
        var html = '<div class="het-diff">';
        html += '<p><strong>Typ:</strong> '+res.data.type+' • A #'+res.data.a.id+' ('+res.data.a.created_at+') • B #'+res.data.b.id+' ('+res.data.b.created_at+')</p>';
        html += '<h3>Dodane</h3><pre>'+JSON.stringify(d.added||[], null, 2)+'</pre>';
        html += '<h3>Usunięte</h3><pre>'+JSON.stringify(d.removed||[], null, 2)+'</pre>';
        html += '<h3>Zmienione</h3><pre>'+JSON.stringify(d.changed||[], null, 2)+'</pre>';
        html += '</div>';
        $('#het-snap-diff').html(html);
      });
  }

  function makeSnapshotsNow(){
    $('#het-snap-make').prop('disabled', true).text('Tworzę…');
    $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_snapshots_make', nonce:HET_INSPECTOR.nonce})
      .always(function(){ $('#het-snap-make').prop('disabled', false).text('Zrób snapshot teraz'); })
      .done(function(){ refreshSnapshots(); });
  }


function fetchSafeStatus(){
  return $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_safe_status', nonce:HET_INSPECTOR.nonce})
    .done(function(res){
      if(!res || !res.success){ $('#het-safe-status').text('Błąd.'); return; }
      var d = res.data || {};
      var status = d.enabled ? 'WŁĄCZONY' : 'WYŁĄCZONY';
      $('#het-safe-status').html('<strong>Status:</strong> '+status+' • próg: '+(d.threshold||3)+' • okno: '+(d.window_sec||600)+'s');
      var blocked = d.blocked || [];
      if(!blocked.length){
        $('#het-safe-blocked').html('<div class="description">Brak zablokowanych wtyczek.</div>');
      } else {
        var html = '<div><strong>Zablokowane wtyczki:</strong></div><ul>';
        blocked.forEach(function(slug){
          html += '<li><code>'+slug+'</code> <button class="button button-small het-safe-unblock" data-slug="'+slug+'">Odblokuj</button></li>';
        });
        html += '</ul>';
        $('#het-safe-blocked').html(html);
      }
    });
}

function toggleSafeMode(){
  return $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_safe_toggle', nonce:HET_INSPECTOR.nonce})
    .done(function(){ fetchSafeStatus(); });
}

function preupdateSnapshot(){
  $('#het-preflight-out').html('Tworzę snapshot przed aktualizacją…');
  return $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_preupdate_snapshot', nonce:HET_INSPECTOR.nonce})
    .done(function(res){
      if(!res || !res.success){ $('#het-preflight-out').html('<p>Błąd snapshotu.</p>'); return; }
      $('#het-preflight-out').html('<p><strong>OK.</strong> Snapshot przed aktualizacją zapisany (#'+res.data.id+').</p>');
    });
}

function postupdateDiff(){
  $('#het-preflight-out').html('Tworzę snapshot po aktualizacji i liczę diff…');
  return $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_postupdate_diff', nonce:HET_INSPECTOR.nonce})
    .done(function(res){
      if(!res || !res.success){ $('#het-preflight-out').html('<p>Błąd diff.</p>'); return; }
      var d = res.data.diff || {};
      var html = '<p><strong>Diff:</strong> pre #'+res.data.pre.id+' → post #'+res.data.post.id+'</p>';
      html += '<h3>Dodane</h3><pre>'+JSON.stringify(d.added||[], null, 2)+'</pre>';
      html += '<h3>Usunięte</h3><pre>'+JSON.stringify(d.removed||[], null, 2)+'</pre>';
      html += '<h3>Zmienione</h3><pre>'+JSON.stringify(d.changed||[], null, 2)+'</pre>';
      $('#het-preflight-out').html(html);
    });
}

  function runPreflight(){
    $('#het-preflight-out').html('Ładowanie…');
    $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_preflight_run', nonce:HET_INSPECTOR.nonce})
      .done(function(res){
        if(!res || !res.success){ $('#het-preflight-out').html('<p>Błąd preflight.</p>'); return; }
        var rows = (res.data.results||[]);
        var html = '<table class="widefat striped"><thead><tr><th>Check</th><th>Status</th><th>Szczegóły</th><th>Rekomendacja</th></tr></thead><tbody>';
        rows.forEach(function(r){
          html += '<tr><td>'+r.check+'</td><td><strong>'+r.status+'</strong></td><td><pre style="white-space:pre-wrap;max-width:520px">'+JSON.stringify(r.details,null,2)+'</pre></td><td style="max-width:420px">'+(r.recommendation||'')+'</td></tr>';
        });
        html += '</tbody></table>';
        html += '<p class="description">Wygenerowano: '+res.data.generated_at+'</p>';
        $('#het-preflight-out').html(html);
      });
  }

  $(function(){
    if($('#het-snapshots-list').length){
      refreshSnapshots();
      $('#het-snap-refresh').on('click', function(e){ e.preventDefault(); refreshSnapshots(); });
      $('#het-snap-type').on('change', function(){ refreshSnapshots(); });
      $('#het-diff-run').on('click', function(e){ e.preventDefault(); runDiff(); });
      $('#het-snap-make').on('click', function(e){ e.preventDefault(); makeSnapshotsNow(); });
    }
    if($('#het-preflight-out').length){
      $('#het-preflight-run').on('click', function(e){ e.preventDefault(); runPreflight(); });
      $('#het-preflight-snap-pre').on('click', function(e){ e.preventDefault(); preupdateSnapshot(); });
      $('#het-preflight-snap-post').on('click', function(e){ e.preventDefault(); postupdateDiff(); });
      $('#het-safe-toggle').on('click', function(e){ e.preventDefault(); toggleSafeMode(); });
      $(document).on('click', '.het-safe-unblock', function(e){ e.preventDefault(); var slug=$(this).data('slug'); $.post(HET_INSPECTOR.ajax_url, {action:'het_inspector_safe_unblock', nonce:HET_INSPECTOR.nonce, slug:slug}).done(function(){ fetchSafeStatus(); }); });
      fetchSafeStatus();
    }
  });

})(jQuery);
