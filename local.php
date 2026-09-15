<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * local.php — mini-app PÚBLICA do link temporário (Direct → horário ao vivo).
 *
 * Fluxo didático em 3 passos:
 *   1) escolher a linha (menu com busca);
 *   2) compartilhar a localização (GPS do navegador);
 *   3) ver os próximos ônibus ao vivo no ponto mais próximo + pedir aviso no Direct.
 *
 * Sem login: protegido pelo token (não adivinhável, expira em 24h, ligado à conversa).
 * Endpoints AJAX no mesmo arquivo via ?acao=... .
 */

require __DIR__ . '/init.php';
require __DIR__ . '/lib_onibus.php';
require __DIR__ . '/lib_direct.php';
require __DIR__ . '/lib_push.php';

$db = db();
$t  = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$token = onibus_token_obter($db, $t);   // opcional: só habilita aviso via Direct
$acao = (string) ($_GET['acao'] ?? $_POST['acao'] ?? '');

/* ------------------------- Endpoints AJAX (JSON) -------------------------
   O app funciona SEM token (consulta pública + push no aparelho). O token só
   adiciona a opção de aviso pelo DIRECT (liga o link à conversa do Instagram). */
if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');

    if ($acao === 'linhas') {
        echo json_encode(['ok' => true, 'linhas' => onibus_menu_linhas($db, (string) ($_GET['q'] ?? ''), 40)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'prev') {
        $lat = (float) ($_POST['lat'] ?? 0);
        $lng = (float) ($_POST['lng'] ?? 0);
        $linhaId = (int) ($_POST['linha_id'] ?? 0);
        if (abs($lat) < 0.0001 || abs($lng) < 0.0001 || $linhaId <= 0) {
            echo json_encode(['ok' => false, 'erro' => 'Dados incompletos.']);
            exit;
        }
        if ($token) {
            $db->prepare('UPDATE onibus_local_tokens SET lat=?, lng=? WHERE token=?')->execute([$lat, $lng, $token['token']]);
        }
        echo json_encode(onibus_prev_por_gps($db, $linhaId, $lat, $lng), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // aviso pelo DIRECT (requer token/conversa)
    if ($acao === 'alerta') {
        if (!$token) {
            echo json_encode(['ok' => false, 'erro' => 'Aviso pelo Direct indisponível (abra pelo link do Direct).']);
            exit;
        }
        $linhaId = (int) ($_POST['linha_id'] ?? 0);
        $stopId  = (string) ($_POST['stop_id'] ?? '');
        if ($linhaId <= 0 || $stopId === '') {
            echo json_encode(['ok' => false, 'erro' => 'Dados incompletos.']);
            exit;
        }
        $v = $db->query('SELECT * FROM onibus_linhas WHERE id=' . $linhaId)->fetch();
        $nome = $v ? onibus_nome_parada($v, $stopId) : '';
        onibus_alerta_criar($db, (int) $token['cliente_id'], (string) $token['sender_id'], $linhaId, $stopId, $nome);
        $cli = $db->query('SELECT * FROM ' . DB_PREFIX . 'clientes WHERE id=' . (int) $token['cliente_id'])->fetch();
        if ($cli) {
            dm_enviar($db, $cli, (string) $token['sender_id'], "✅ Combinado! Vou te mandando o tempo do ônibus até ele chegar no seu ponto. 🚍\n(pra parar, é só dizer \"parar\".)", 'auto_onibus');
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    // aviso por WEB PUSH (aparelho) — não precisa de token
    if ($acao === 'push_sub') {
        $linhaId  = (int) ($_POST['linha_id'] ?? 0);
        $stopId   = (string) ($_POST['stop_id'] ?? '');
        $endpoint = (string) ($_POST['endpoint'] ?? '');
        $p256dh   = (string) ($_POST['p256dh'] ?? '');
        $auth     = (string) ($_POST['auth'] ?? '');
        if ($linhaId <= 0 || $stopId === '' || $endpoint === '' || $p256dh === '' || $auth === '') {
            echo json_encode(['ok' => false, 'erro' => 'Dados incompletos.']);
            exit;
        }
        $v = $db->query('SELECT * FROM onibus_linhas WHERE id=' . $linhaId)->fetch();
        $nome = $v ? onibus_nome_parada($v, $stopId) : '';
        onibus_alerta_criar_push($db, $linhaId, $stopId, $nome, $endpoint, $p256dh, $auth,
            $token ? (int) $token['cliente_id'] : null, $token ? (string) $token['sender_id'] : null);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'erro' => 'ação inválida']);
    exit;
}

/* ----------------------------- Página (HTML) ---------------------------- */
$temToken = (bool) $token;                 // habilita opção "avisar no Direct"
$codePre  = $token ? trim((string) $token['route_code']) : '';
$vapidPub = push_vapid_public();
?><!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#2563EB">
  <title>Ônibus ao vivo · <?= e(APP_NAME) ?></title>
  <!-- PWA (app instalável de ônibus) -->
  <link rel="manifest" href="<?= BASE_URL ?>/onibus.webmanifest">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Ônibus Maceió">
  <link rel="apple-touch-icon" href="<?= BASE_URL ?>/icons/icon-192.png">
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>/icons/icon-192.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <style>
    /* Visual claro estilo CittaMobi (teal-verde) */
    *{box-sizing:border-box;margin:0;padding:0}
    :root{--gr:#00A99D;--gr2:#00897B;--ink:#1f2a37;--sub:#6b7a8d;--line:#e7ecf0;--bg:#eef2f5}
    body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--ink);
      min-height:100vh;display:flex;justify-content:center}
    .app{width:100%;max-width:460px;padding:0 14px 24px}
    .top{background:linear-gradient(135deg,var(--gr),var(--gr2));color:#fff;text-align:center;
      margin:0 -14px 16px;padding:22px 16px 18px;border-radius:0 0 22px 22px;box-shadow:0 6px 18px rgba(0,169,157,.28)}
    .top .bus{font-size:34px}
    .top h1{font-size:18px;font-weight:900;margin-top:2px}
    .top p{font-size:12.5px;opacity:.92;margin-top:2px}
    .steps{display:flex;gap:6px;justify-content:center;margin:0 0 14px}
    .dot{width:8px;height:8px;border-radius:50%;background:#cfd8e0;transition:.2s}
    .dot.on{background:var(--gr);transform:scale(1.25)}
    .card{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px 16px;box-shadow:0 6px 18px rgba(31,42,55,.06)}
    h2{font-size:16px;font-weight:800;margin-bottom:4px}
    .sub{font-size:13px;color:var(--sub);margin-bottom:14px;line-height:1.5}
    .search{width:100%;padding:13px 14px;border-radius:12px;border:1px solid var(--line);background:#f7fafc;
      color:var(--ink);font-family:inherit;font-size:15px;margin-bottom:12px}
    .search::placeholder{color:#9aa7b4}
    .search:focus{outline:none;border-color:var(--gr);background:#fff}
    .lista{display:flex;flex-direction:column;gap:8px;max-height:56vh;overflow:auto}
    .linha{display:flex;align-items:center;gap:12px;background:#fff;border:1px solid var(--line);border-radius:12px;
      padding:11px 13px;cursor:pointer;transition:.15s;text-align:left}
    .linha:hover,.linha:active{border-color:var(--gr);background:#f0fbfa}
    .cod{background:var(--gr);color:#fff;font-weight:900;font-size:13px;border-radius:8px;padding:4px 9px;min-width:52px;text-align:center}
    .linfo b{display:block;font-size:14px;color:var(--ink);line-height:1.25}
    .linfo span{font-size:11.5px;color:var(--sub)}
    button.big{width:100%;border:none;cursor:pointer;font-family:inherit;font-weight:800;font-size:16px;
      padding:15px;border-radius:12px;background:var(--gr);color:#fff;transition:filter .15s;margin-top:8px;
      box-shadow:0 4px 12px rgba(0,169,157,.28)}
    button.big:hover{filter:brightness(1.06)}button.big:disabled{opacity:.55;cursor:default}
    button.ghost{background:#fff;border:1px solid var(--line);color:var(--ink);font-weight:700;font-size:14px;
      padding:12px;border-radius:12px;width:100%;cursor:pointer;margin-top:10px}
    button.ghost:hover{border-color:var(--gr);color:var(--gr2)}
    .hint{font-size:12px;color:var(--sub);text-align:center;margin-top:12px}
    .res-linha{font-size:16px;font-weight:800;margin-bottom:4px}
    .res-ponto{font-size:13px;color:var(--sub);margin-bottom:14px}
    .mins{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:6px}
    .min{background:#f0fbfa;border:1px solid #bdeae4;border-radius:14px;padding:12px 16px;text-align:center;flex:1;min-width:80px}
    .min b{display:block;font-size:28px;font-weight:900;color:var(--gr2);line-height:1}
    .min span{font-size:11px;color:var(--sub)}
    .msg{margin-top:14px;font-size:14px;font-weight:600;min-height:20px;text-align:center}
    .ok{color:#12805c}.err{color:#c0392b}
    .live{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--gr2);font-weight:800;margin-bottom:10px;text-transform:uppercase;letter-spacing:.04em}
    .live i{width:8px;height:8px;border-radius:50%;background:var(--gr);animation:pulse 1.4s infinite}
    @keyframes pulse{0%,100%{opacity:1}50%{opacity:.25}}
    .foot{text-align:center;font-size:11px;color:#9aa7b4;margin-top:16px}
    .hidden{display:none}
    .mapa{height:220px;border-radius:14px;overflow:hidden;margin:4px 0 14px;border:1px solid var(--line)}
    .leaflet-container{background:#dfe7ec}
    .spin{border:3px solid var(--line);border-top-color:var(--gr);border-radius:50%;width:30px;height:30px;
      animation:rot 1s linear infinite;margin:16px auto}
    @keyframes rot{to{transform:rotate(360deg)}}
  </style>
</head>
<body>
<div class="app">
  <div class="top">
    <div class="bus">🚍</div>
    <h1>Ônibus ao vivo — Maceió</h1>
    <p>Em quantos minutos seu ônibus passa</p>
  </div>

  <div class="steps"><span class="dot on" id="d1"></span><span class="dot" id="d2"></span><span class="dot" id="d3"></span></div>

  <!-- Retomar acompanhamento salvo (ao reabrir o app) -->
  <div class="card hidden" id="resume" style="border-color:var(--gr);background:#f0fbfa">
    <h2 id="resumeT">🔔 Você está acompanhando</h2>
    <p class="sub" id="resumeS" style="margin-bottom:10px"></p>
    <button class="big" id="btnResume">🚍 Ver ao vivo agora</button>
  </div>

  <!-- PASSO 1: escolher a linha -->
  <div class="card" id="passo1">
    <h2>1. Qual é a sua linha?</h2>
    <p class="sub">Toque na linha que você quer pegar. Pode buscar pelo número ou pelo bairro.</p>
    <input class="search" id="busca" placeholder="Ex.: 0004, centro, rio largo…" autocomplete="off">
    <div class="lista" id="lista"><div class="spin"></div></div>
  </div>

  <!-- PASSO 2: localização -->
  <div class="card hidden" id="passo2">
    <h2>2. Onde você está?</h2>
    <p class="sub" id="p2sub">Vou usar sua localização só pra achar o ponto mais perto de você. Nada é guardado além disso.</p>
    <button class="big" id="btnGps">📍 Usar minha localização</button>
    <button class="ghost" id="voltar1">↩ Trocar de linha</button>
    <div class="msg" id="msg2"></div>
  </div>

  <!-- PASSO 3: resultado -->
  <div class="card hidden" id="passo3">
    <div class="live"><i></i> AO VIVO <span id="liveTick" style="color:#64748b;font-weight:600"></span></div>
    <div class="res-linha" id="resLinha"></div>
    <div class="res-ponto" id="resPonto"></div>
    <div id="mapa" class="mapa hidden"></div>
    <div id="resMins"></div>
    <div id="avisoBox" style="display:none">
      <p class="sub" style="margin:14px 0 8px;text-align:center">🔔 Quer ser avisado quando estiver chegando?</p>
      <button class="big" id="btnPush">📲 Avisar neste aparelho</button>
      <?php if ($temToken): ?><button class="ghost" id="btnAlerta">💬 Avisar no meu Direct</button><?php endif; ?>
    </div>
    <button class="ghost" id="btnInstalar" style="display:none">➕ Instalar como app</button>
    <button class="ghost" id="voltar1b">↩ Ver outra linha</button>
    <div class="msg" id="msg3"></div>
  </div>

  <div class="foot"><?= e(APP_NAME) ?> · dados em tempo real: CittaMobi</div>
</div>

<script>
(function () {
  var T = <?= json_encode($t) ?>, CODE_PRE = <?= json_encode($codePre) ?>;
  var VAPID = <?= json_encode($vapidPub) ?>;
  var base = location.pathname;
  var sel = null, coords = null;
  var map = null, mUser = null, mStop = null, mBuses = [], refTimer = null, tickTimer = null, prox = 30;
  var el = function (id) { return document.getElementById(id); };
  function pararLoop() { if (refTimer) { clearInterval(refTimer); refTimer = null; } if (tickTimer) { clearInterval(tickTimer); tickTimer = null; } }
  function step(n) { ['d1','d2','d3'].forEach(function (d, i) { el(d).classList.toggle('on', i < n); });
    el('passo1').classList.toggle('hidden', n !== 1);
    el('passo2').classList.toggle('hidden', n !== 2);
    el('passo3').classList.toggle('hidden', n !== 3);
    if (n !== 3) { pararLoop(); } }

  function carregarLinhas(q) {
    el('lista').innerHTML = '<div class="spin"></div>';
    fetch(base + '?acao=linhas&t=' + encodeURIComponent(T) + '&q=' + encodeURIComponent(q || ''))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok || !d.linhas.length) { el('lista').innerHTML = '<p class="hint">Nenhuma linha encontrada.</p>'; return; }
        el('lista').innerHTML = '';
        d.linhas.forEach(function (l) {
          var b = document.createElement('button');
          b.className = 'linha';
          b.innerHTML = '<span class="cod">' + (l.code || '—') + '</span><span class="linfo"><b></b><span></span></span>';
          b.querySelector('.linfo b').textContent = l.nome;
          b.querySelector('.linfo span').textContent = l.empresa;
          b.addEventListener('click', function () { sel = l; el('p2sub').innerHTML = 'Linha <b>' + (l.code || '') + '</b> — ' + l.nome + '.<br>Vou achar o ponto mais perto de você.'; step(2); });
          el('lista').appendChild(b);
        });
      })
      .catch(function () { el('lista').innerHTML = '<p class="hint">Falha ao carregar. Recarregue a página.</p>'; });
  }

  var deb;
  el('busca').addEventListener('input', function (e) { clearTimeout(deb); var q = e.target.value; deb = setTimeout(function () { carregarLinhas(q); }, 300); });
  el('voltar1').addEventListener('click', function () { step(1); });
  el('voltar1b').addEventListener('click', function () { step(1); el('busca').value=''; carregarLinhas(''); });

  el('btnGps').addEventListener('click', function () {
    if (!navigator.geolocation) { el('msg2').className = 'msg err'; el('msg2').textContent = 'Seu navegador não permite localização.'; return; }
    var b = el('btnGps'); b.disabled = true; el('msg2').className = 'msg'; el('msg2').textContent = 'Obtendo sua localização…';
    navigator.geolocation.getCurrentPosition(function (pos) {
      coords = { lat: pos.coords.latitude, lng: pos.coords.longitude };
      buscarPrevisao();
    }, function (err) {
      b.disabled = false; el('msg2').className = 'msg err';
      el('msg2').textContent = err.code === 1 ? 'Você precisa permitir o acesso à localização.' : 'Não consegui obter sua localização.';
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
  });

  function icone(emoji, cor) {
    return L.divIcon({ className: '', html: '<div style="font-size:26px;filter:drop-shadow(0 2px 3px rgba(0,0,0,.5))">' + emoji + '</div>', iconSize: [30, 30], iconAnchor: [15, 15] });
  }

  function renderMapa(d) {
    if (!window.L || d.stop_lat == null) { return; }
    el('mapa').classList.remove('hidden');
    if (!map) {
      map = L.map('mapa', { zoomControl: false, attributionControl: false }).setView([d.stop_lat, d.stop_lng], 15);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
    }
    setTimeout(function () { map.invalidateSize(); }, 120);
    if (!mUser && coords) { mUser = L.marker([coords.lat, coords.lng], { icon: icone('🧍') }).addTo(map).bindPopup('Você'); }
    if (!mStop) { mStop = L.marker([d.stop_lat, d.stop_lng], { icon: icone('🚏') }).addTo(map).bindPopup('Seu ponto'); }
    else { mStop.setLatLng([d.stop_lat, d.stop_lng]); }
    mBuses.forEach(function (m) { map.removeLayer(m); }); mBuses = [];
    (d.buses || []).forEach(function (b) {
      mBuses.push(L.marker([b.lat, b.lng], { icon: icone('🚌') }).addTo(map).bindPopup('Ônibus · ~' + b.min + ' min'));
    });
    var pts = [[d.stop_lat, d.stop_lng]];
    if (coords) { pts.push([coords.lat, coords.lng]); }
    (d.buses || []).forEach(function (b) { pts.push([b.lat, b.lng]); });
    if (pts.length > 1) { map.fitBounds(pts, { padding: [40, 40], maxZoom: 16 }); }
  }

  var curLinha = 0, curStop = '', prepTentativas = 0;
  function fmtDist(m) { return m >= 1000 ? (m / 1000).toFixed(1) + ' km' : m + ' m'; }
  function pintarResultado(d) {
    el('resLinha').textContent = '🚍 ' + d.titulo;
    el('resPonto').textContent = '📍 ' + d.parada + (d.dist ? ' (~' + fmtDist(d.dist) + ')' : '');
    var aviso = d.longe
      ? '<div style="background:#fff4e5;border:1px solid #ffd59e;color:#8a5a00;border-radius:12px;padding:10px 12px;font-size:13px;font-weight:600;margin-bottom:10px">⚠️ Essa linha não passa pertinho de você. O ponto mais próximo dela fica a <b>~' + fmtDist(d.dist) + '</b>. Confira se é a linha/sentido certos.</div>'
      : '';
    if (!d.minutos || !d.minutos.length) {
      el('resMins').innerHTML = aviso + '<p class="hint">Nenhum ônibus previsto ao vivo agora. Pode não estar circulando neste horário.</p>';
      el('avisoBox').style.display = 'none';
    } else {
      el('resMins').innerHTML = aviso + '<div class="mins">' + d.minutos.map(function (m, i) {
        return '<div class="min"><b>' + m + '</b><span>' + (i === 0 ? 'próximo' : 'depois') + ' · min</span></div>';
      }).join('') + '</div>';
      curLinha = d.linha_id; curStop = d.stop_id;
      el('avisoBox').style.display = 'block';
    }
    renderMapa(d);
  }

  function consultar(silent) {
    if (!silent) { el('resMins').innerHTML = '<div class="spin"></div>'; el('msg3').textContent = ''; el('avisoBox').style.display = 'none'; }
    var ctrl = new AbortController();
    var to = setTimeout(function () { ctrl.abort(); }, 20000); // nunca trava: corta em 20s
    var fd = new URLSearchParams();
    fd.set('t', T); fd.set('linha_id', sel.id); fd.set('lat', coords.lat); fd.set('lng', coords.lng);
    return fetch(base + '?acao=prev', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString(), signal: ctrl.signal })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        clearTimeout(to); el('btnGps').disabled = false;
        // linha ainda sendo preparada (geocode) -> tenta poucas vezes, NUNCA em loop
        if (d && d.preparando) {
          if (prepTentativas < 4) {
            prepTentativas++;
            el('resMins').innerHTML = '<div class="spin"></div>';
            el('msg3').className = 'msg'; el('msg3').textContent = 'Preparando os pontos dessa linha… (' + prepTentativas + '/4)';
            return new Promise(function (res) { setTimeout(function () { consultar(silent).then(res); }, 7000); });
          }
          el('resMins').innerHTML = ''; el('msg3').className = 'msg err';
          el('msg3').textContent = '⏳ Essa linha ainda está sendo preparada. Tente de novo em 1 minutinho.';
          return false;
        }
        if (!d || !d.ok) { if (!silent) { el('resMins').innerHTML = ''; el('msg3').className = 'msg err'; el('msg3').textContent = '⚠️ ' + ((d && d.erro) || 'Falha.'); } return false; }
        pintarResultado(d); return true;
      })
      .catch(function () {
        clearTimeout(to); el('btnGps').disabled = false;
        if (!silent) { el('resMins').innerHTML = ''; el('msg3').className = 'msg err'; el('msg3').textContent = '⚠️ Demorou demais / falhou. Toque em "Ver outra linha" e tente de novo.'; }
        return false;
      });
  }

  function buscarPrevisao() {
    step(3);
    el('resLinha').textContent = sel ? ('🚍 Linha ' + (sel.code || '') + ' — ' + sel.nome) : '';
    el('resPonto').textContent = '';
    pararLoop();
    prepTentativas = 0;
    consultar(false).then(function (ok) {
      if (!ok) { return; }   // só liga a atualização automática se deu certo
      prox = 30;
      refTimer = setInterval(function () { prox = 30; consultar(true); }, 30000);
      tickTimer = setInterval(function () { prox = Math.max(0, prox - 1); el('liveTick').textContent = '· atualiza em ' + prox + 's'; }, 1000);
    });
  }

  // ---- Aviso no Direct (só quando aberto pelo link do Direct) ----
  var btnAlerta = el('btnAlerta');
  if (btnAlerta) {
    btnAlerta.addEventListener('click', function () {
      btnAlerta.disabled = true;
      var fd = new URLSearchParams();
      fd.set('t', T); fd.set('linha_id', curLinha); fd.set('stop_id', curStop);
      fetch(base + '?acao=alerta', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.ok) { el('msg3').className = 'msg ok'; el('msg3').innerHTML = '✅ Pronto! Te aviso no seu Direct quando estiver chegando.'; el('avisoBox').style.display = 'none'; }
          else { btnAlerta.disabled = false; el('msg3').className = 'msg err'; el('msg3').textContent = '⚠️ ' + (d.erro || 'Falha.'); }
        })
        .catch(function () { btnAlerta.disabled = false; el('msg3').className = 'msg err'; el('msg3').textContent = '⚠️ Falha.'; });
    });
  }

  // ---- Memória local: guarda a linha/ponto acompanhados p/ reabrir depois ----
  function favSalvar(extra) {
    try {
      var f = favLer() || {};
      if (sel) { f.id = sel.id; f.code = sel.code; f.nome = sel.nome; }
      if (curStop) { f.stop = curStop; }
      if (coords) { f.lat = coords.lat; f.lng = coords.lng; }
      Object.assign(f, extra || {});
      localStorage.setItem('onibus_fav', JSON.stringify(f));
    } catch (e) {}
  }
  function favLer() { try { return JSON.parse(localStorage.getItem('onibus_fav') || 'null'); } catch (e) { return null; } }

  // ---- Instalar como app (PWA) ----
  var bip = null, instalado = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); bip = e; el('btnInstalar').style.display = 'block'; });
  window.addEventListener('appinstalled', function () { instalado = true; bip = null; el('btnInstalar').style.display = 'none'; });
  el('btnInstalar').addEventListener('click', function () { instalarPWA(); });
  function instalarPWA() {
    if (bip) { bip.prompt(); bip.userChoice.finally(function () { bip = null; el('btnInstalar').style.display = 'none'; }); return true; }
    return false;
  }
  function ehIOS() { return /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream; }

  // ---- Aviso por PUSH (neste aparelho) + instalação do app ----
  function b64ToU8(base64) {
    var pad = '='.repeat((4 - base64.length % 4) % 4);
    var b = (base64 + pad).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(b), arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { arr[i] = raw.charCodeAt(i); }
    return arr;
  }
  var swReg = null;
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js', { scope: '<?= BASE_URL ?>/' })
      .then(function (r) { swReg = r; }).catch(function () {});
  }
  el('btnPush').addEventListener('click', function () {
    var b = el('btnPush');
    // iOS só aceita push com o app INSTALADO (adicionar à tela de início)
    if (ehIOS() && !instalado) {
      el('msg3').className = 'msg';
      el('msg3').innerHTML = '📲 Pra ativar os avisos no iPhone:<br>1) toque em <b>Compartilhar</b>;<br>2) <b>Adicionar à Tela de Início</b>;<br>3) abra o app e toque aqui de novo.';
      return;
    }
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      el('msg3').className = 'msg err';
      el('msg3').innerHTML = 'Seu navegador não suporta avisos aqui. Abra no Chrome/Safari ou instale o app. 📲';
      return;
    }
    b.disabled = true; el('msg3').className = 'msg'; el('msg3').textContent = 'Ativando avisos…';
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') { b.disabled = false; el('msg3').className = 'msg err'; el('msg3').textContent = 'Você precisa permitir as notificações.'; return; }
      return (swReg ? Promise.resolve(swReg) : navigator.serviceWorker.ready).then(function (reg) {
        return reg.pushManager.getSubscription().then(function (s) {
          return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToU8(VAPID) });
        });
      }).then(function (sub) {
        var j = sub.toJSON();
        var fd = new URLSearchParams();
        fd.set('t', T); fd.set('linha_id', curLinha); fd.set('stop_id', curStop);
        fd.set('endpoint', sub.endpoint); fd.set('p256dh', j.keys.p256dh); fd.set('auth', j.keys.auth);
        return fetch(base + '?acao=push_sub', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() }).then(function (r) { return r.json(); });
      }).then(function (d) {
        if (d && d.ok) {
          favSalvar({ push: true });                     // guarda p/ reabrir depois
          var extra = (!instalado && (bip || 'onbeforeinstallprompt' in window)) ? ' Instale o app pra abrir rapidinho e receber melhor. 👇' : '';
          el('msg3').className = 'msg ok';
          el('msg3').innerHTML = '✅ Pronto! Este aparelho vai te avisar quando o ônibus estiver chegando.' + extra;
          el('avisoBox').style.display = 'none';
          if (!instalado) { if (!instalarPWA()) { el('btnInstalar').style.display = 'block'; } } // oferece instalar
        } else { b.disabled = false; el('msg3').className = 'msg err'; el('msg3').textContent = '⚠️ ' + ((d && d.erro) || 'Falha ao ativar.'); }
      });
    }).catch(function () { b.disabled = false; el('msg3').className = 'msg err'; el('msg3').textContent = '⚠️ Não consegui ativar os avisos neste aparelho.'; });
  });

  // ---- Retomar acompanhamento ao reabrir o app ----
  function mostrarRetomar() {
    var f = favLer();
    if (!f || !f.id) { return; }
    el('resumeS').innerHTML = 'Linha <b>' + (f.code || '') + '</b> — ' + (f.nome || '') + (f.push ? '<br>🔔 avisos ligados neste aparelho' : '');
    el('resume').classList.remove('hidden');
    el('btnResume').onclick = function () {
      sel = { id: f.id, code: f.code, nome: f.nome };
      el('resume').classList.add('hidden');
      // usa GPS atual (mais preciso); se negar, cai no último local salvo
      if (navigator.geolocation) {
        el('msg2'); step(2); el('p2sub').innerHTML = 'Confirmando sua localização…';
        navigator.geolocation.getCurrentPosition(function (pos) {
          coords = { lat: pos.coords.latitude, lng: pos.coords.longitude }; buscarPrevisao();
        }, function () {
          if (f.lat && f.lng) { coords = { lat: f.lat, lng: f.lng }; buscarPrevisao(); }
          else { step(2); }
        }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
      } else if (f.lat && f.lng) { coords = { lat: f.lat, lng: f.lng }; buscarPrevisao(); }
    };
  }

  // salva o acompanhamento sempre que uma consulta dá certo
  var _pintar = pintarResultado;
  pintarResultado = function (d) { _pintar(d); if (d && d.minutos && d.minutos.length) { favSalvar(); } };

  step(1);
  mostrarRetomar();
  carregarLinhas(CODE_PRE);
})();
</script>
</body>
</html>
