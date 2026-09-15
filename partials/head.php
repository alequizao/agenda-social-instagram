<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/** Cabecalho compartilhado (padrao Designi Alequizao). Espera $page_title e (opcional) $body_class. */
$page_title = $page_title ?? APP_NAME;
$body_class = $body_class ?? '';
$u = usuario_logado();

$cur = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$clientesAtivo = in_array($cur, ['dashboard.php', 'cliente.php', 'cliente_instagram.php', 'publicacao_form.php'], true);
$novoAtivo      = ($cur === 'cliente_form.php');
$noticiasAtivo  = ($cur === 'noticias.php');
$aprovAtivo     = ($cur === 'aprovacao.php');
$agendAtivo     = ($cur === 'agendados.php');
$errosAtivo     = ($cur === 'erros.php');
$directAtivo    = ($cur === 'direct.php');
$onibusAtivo    = ($cur === 'onibus.php');
$jogoAtivo      = ($cur === 'jogo.php');
$cfgAtivo       = ($cur === 'config_ia.php');

/* contadores p/ badges da sidebar */
$qtdAprov = 0;
$qtdNovas = 0;
$qtdAgend = 0;
$qtdErros = 0;
if ($u) {
    // cache de 20s em sessão: evita 5 COUNTs no banco a CADA load de página
    $bc = $_SESSION['badges_cache'] ?? null;
    if (is_array($bc) && (time() - (int) ($bc['t'] ?? 0)) < 20) {
        [$qtdAprov, $qtdNovas, $qtdAgend, $qtdErros, $qtdDM] = $bc['v'];
    } else {
        try {
            $qtdAprov = (int) db()->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes WHERE status="aguardando_aprovacao"')->fetchColumn();
            $qtdNovas = (int) db()->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'noticias_descobertas WHERE status="nova"')->fetchColumn();
            $qtdAgend = (int) db()->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes WHERE origem="ia" AND status="agendado" AND agendado_para > NOW()')->fetchColumn();
            $qtdErros = (int) db()->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes WHERE status="erro"')->fetchColumn();
            $qtdDM = (int) db()->query('SELECT COALESCE(SUM(nao_lidas),0) FROM ' . DB_PREFIX . 'dm_conversas')->fetchColumn();
        } catch (Throwable $e) {
            $qtdAprov = 0;
            $qtdNovas = 0;
            $qtdAgend = 0;
            $qtdErros = 0;
            $qtdDM = 0;
        }
        $_SESSION['badges_cache'] = ['t' => time(), 'v' => [$qtdAprov, $qtdNovas, $qtdAgend, $qtdErros, $qtdDM]];
    }
}
$autoPausada = $u ? automacao_pausada() : false;

/* Pausa AUTOMÁTICA (rate limit da Meta): segundos restantes até a próxima tentativa.
   Lê o arquivo direto p/ não acoplar o lib_publicador a toda página. */
$pausaAutoSeg = 0;
$proxPostSeg  = 0;
if ($u && !$autoPausada) {
    $pf = __DIR__ . '/../logs/rate_limit.pause';
    if (is_file($pf)) {
        $ate = (int) trim((string) @file_get_contents($pf));
        $pausaAutoSeg = max(0, $ate - time());
    }
    if ($pausaAutoSeg === 0) {
        try { // sem pausa: quanto falta para o próximo post agendado
            $prox = db()->query('SELECT MIN(agendado_para) FROM ' . DB_PREFIX . 'publicacoes
                WHERE status="agendado"')->fetchColumn();
            if ($prox) {
                $proxPostSeg = max(0, strtotime((string) $prox) - time());
            }
        } catch (Throwable $e) {
            $proxPostSeg = 0;
        }
    }
}
?><!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script>
    /* tema claro/escuro: aplica antes de pintar p/ evitar flash */
    (function () {
      try {
        var t = localStorage.getItem('tema');
        if (!t) { t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
        document.documentElement.setAttribute('data-theme', t);
      } catch (e) {}
    })();
  </script>
  <title><?= e($page_title) ?> &middot; <?= e(APP_NAME) ?></title>

  <!-- PWA (app instalável) -->
  <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
  <meta name="theme-color" content="#2563EB">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Agenda Social">
  <link rel="apple-touch-icon" href="<?= BASE_URL ?>/icons/apple-touch-icon.png">
  <link rel="icon" href="<?= BASE_URL ?>/favicon.ico?v=<?= APP_VERSAO ?>" sizes="32x32">
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>/favicon-32.png" sizes="32x32">
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>/icons/icon-192.png" sizes="192x192">
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js', { scope: '<?= BASE_URL ?>/' }).catch(function(){});
      });
    }
    /* Botão "Instalar app" (PWA) */
    (function () {
      var bip = null;
      window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault(); bip = e;
        var b = document.getElementById('btnInstalar'); if (b) b.classList.remove('is-hidden');
      });
      window.addEventListener('appinstalled', function () {
        var b = document.getElementById('btnInstalar'); if (b) b.classList.add('is-hidden'); bip = null;
      });
      window.addEventListener('load', function () {
        var b = document.getElementById('btnInstalar'); if (!b) return;
        var ua = navigator.userAgent || '';
        var isIOS = /iphone|ipad|ipod/i.test(ua);
        var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        if (standalone) { b.classList.add('is-hidden'); return; } // já instalado
        b.addEventListener('click', function () {
          if (bip) {
            bip.prompt();
            bip.userChoice.finally(function () { bip = null; b.classList.add('is-hidden'); });
          } else if (isIOS) {
            alert('Para instalar no iPhone/iPad:\n\n1) Toque no botão Compartilhar (quadrado com seta para cima);\n2) Escolha "Adicionar à Tela de Início".');
          } else {
            alert('Para instalar:\n\nNo Chrome/Edge, abra o menu (⋮) e escolha "Instalar app" / "Adicionar à tela inicial". Se não aparecer, recarregue a página e tente de novo.');
          }
        });
      });
    })();
  </script>

  <!-- Padrao visual Designi Alequizao: Inter + Font Awesome -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset_v('app.css')) ?>">
  <style>
    .btn-auto { display:inline-flex; align-items:center; gap:7px; border:none; cursor:pointer;
      padding:8px 14px; border-radius:9px; font-weight:700; font-size:13px; font-family:inherit; }
    .btn-auto--off { background:#fef3c7; color:#92400e; }
    .btn-auto--off:hover { background:#fde68a; }
    .btn-auto--on { background:#16a34a; color:#fff; }
    .btn-auto--on:hover { background:#15803d; }
    .btn-auto--install { background:#2563EB; color:#fff; }
    .btn-auto--install:hover { background:#1d4ed8; }
    .btn-auto--install.is-hidden { display:none; }
    @media (max-width:560px){ .btn-auto--install span{ display:none; } }
    [data-theme="dark"] .btn-auto--off { background:rgba(245,158,11,.18); color:#fcd34d; }
    .auto-banner { display:flex; align-items:center; gap:12px; flex-wrap:wrap;
      background:#dc2626; color:#fff; padding:10px 18px; font-weight:600; font-size:14px; }
    .auto-banner i { font-size:16px; }
    .auto-banner__btn { margin-left:auto; display:inline-flex; align-items:center; gap:6px;
      background:#fff; color:#b91c1c; border:none; cursor:pointer; padding:6px 12px;
      border-radius:8px; font-weight:700; font-size:13px; font-family:inherit; }
    .auto-banner--rl { background:#f59e0b; color:#3b2600; }
    .auto-banner--rl b { font-variant-numeric:tabular-nums; background:rgba(0,0,0,.12);
      padding:1px 7px; border-radius:6px; }
    .status-bar { display:flex; align-items:center; gap:9px; padding:8px 18px;
      background:var(--cor-card,#f8fafc); color:var(--cor-texto-sec,#64748b);
      font-size:13px; font-weight:600; border-bottom:1px solid rgba(0,0,0,.06); }
    .status-bar b { color:var(--cor-texto,#1e293b); font-variant-numeric:tabular-nums; }
  </style>
</head>
<body class="<?= e($body_class) ?><?= $u ? ' app' : ' guest' ?>">
<?php if ($u): ?>
<aside class="sidebar" id="sidebar">
  <a class="sidebar__brand" href="<?= BASE_URL ?>/dashboard.php">
    <span class="sidebar__logo"><i class="fa-solid fa-calendar-days"></i></span>
    <span>
      <span class="sidebar__name"><?= e(APP_NAME) ?></span>
      <span class="sidebar__sub">Mesa de Redação · Maceió</span>
    </span>
  </a>

  <div class="nav-section">Menu</div>
  <a class="nav-item<?= $clientesAtivo ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/dashboard.php"><i class="fa-solid fa-building"></i> Clientes</a>
  <a class="nav-item<?= $novoAtivo ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/cliente_form.php"><i class="fa-solid fa-plus"></i> Novo cliente</a>

  <div class="nav-section">Redação</div>
  <a class="nav-item<?= $noticiasAtivo ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/noticias.php">
    <i class="fa-solid fa-pen-nib"></i> Mesa de Redação
    <?php if ($qtdNovas > 0): ?><span class="nav-badge"><?= $qtdNovas ?></span><?php endif; ?>
  </a>
  <a class="nav-item<?= $aprovAtivo ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/aprovacao.php">
    <i class="fa-solid fa-clipboard-check"></i> Aprovação
    <?php if ($qtdAprov > 0): ?><span class="nav-badge"><?= $qtdAprov ?></span><?php endif; ?>
  </a>
  <a class="nav-item<?= $agendAtivo ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/agendados.php">
    <i class="fa-solid fa-calendar-check"></i> Agendados
    <?php if ($qtdAgend > 0): ?><span class="nav-badge"><?= $qtdAgend ?></span><?php endif; ?>
  </a>
  <a class="nav-item<?= $errosAtivo ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/erros.php">
    <i class="fa-solid fa-triangle-exclamation"></i> Erros
    <?php if ($qtdErros > 0): ?><span class="nav-badge nav-badge--erro"><?= $qtdErros ?></span><?php endif; ?>
  </a>
  <a class="nav-item<?= $directAtivo ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/direct.php">
    <i class="fa-regular fa-comments"></i> Direct
    <span class="nav-badge" id="navDmBadge" style="<?= (!empty($qtdDM) && $qtdDM > 0) ? '' : 'display:none' ?>"><?= !empty($qtdDM) ? (int) $qtdDM : '' ?></span>
  </a>
  <a class="nav-item<?= $onibusAtivo ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/onibus.php"><i class="fa-solid fa-bus"></i> Horário dos Ônibus</a>
  <a class="nav-item<?= $jogoAtivo ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/jogo.php"><i class="fa-solid fa-gamepad"></i> Jogo da Forca</a>
  <a class="nav-item<?= $cfgAtivo ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/config_ia.php"><i class="fa-solid fa-robot"></i> Configuração</a>
  <a class="nav-item<?= (($cur ?? '') === 'manual.php') ? ' is-active' : '' ?>" href="<?= BASE_URL ?>/manual.php"><i class="fa-solid fa-circle-question"></i> Manual de uso</a>

  <div class="sidebar__foot">
    <a class="nav-item" href="<?= BASE_URL ?>/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
    <div class="sidebar__ver">Versão <?= e(defined('APP_VERSAO') ? APP_VERSAO : '3.7.0') ?></div>
    <div class="sidebar__ver" style="opacity:.75;line-height:1.5">
      Desenvolvido por <b><?= e(DEV_NOME) ?></b><br>
      <?= e(DEV_FONE) ?> · <?= e(DEV_EMAIL) ?>
    </div>
  </div>
</aside>
<div class="overlay" id="overlay"></div>

<div class="content">
  <header class="topbar">
    <button class="hamburger" id="hamburger" aria-label="Abrir menu"><i class="fa-solid fa-bars"></i></button>
    <form method="post" action="<?= BASE_URL ?>/automacao_toggle.php" style="margin:0" onsubmit="return confirm('<?= $autoPausada ? 'Religar a automação? As publicações voltam a sair (no ritmo seguro).' : 'Pausar a automação? Nada será publicado até você religar. (As respostas do Direct continuam normalmente.)' ?>');">
      <?= csrf_field() ?>
      <input type="hidden" name="estado" value="<?= $autoPausada ? '0' : '1' ?>">
      <button type="submit" class="btn-auto <?= $autoPausada ? 'btn-auto--on' : 'btn-auto--off' ?>" title="<?= $autoPausada ? 'Religar automação' : 'Pausar automação' ?>">
        <i class="fa-solid fa-<?= $autoPausada ? 'play' : 'pause' ?>"></i>
        <span><?= $autoPausada ? 'Religar automação' : 'Pausar automação' ?></span>
      </button>
    </form>
    <button class="btn-auto btn-auto--install" id="btnInstalar" type="button" title="Instalar como aplicativo">
      <i class="fa-solid fa-download"></i> <span>Instalar app</span>
    </button>
    <button class="theme-toggle" id="themeToggle" type="button" aria-label="Alternar tema claro/escuro" title="Alternar tema claro/escuro">
      <i class="fa-solid fa-moon"></i><i class="fa-solid fa-sun"></i>
    </button>
    <div class="topbar__user">
      <span class="nome"><?= e($u['nome']) ?></span>
      <span class="av"><?= e(iniciais($u['nome'])) ?></span>
      <a class="topbar__logout" href="<?= BASE_URL ?>/logout.php" title="Sair" aria-label="Sair"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </header>
  <?php if ($autoPausada): ?>
  <div class="auto-banner">
    <i class="fa-solid fa-circle-pause"></i>
    <span>Automação pausada — nenhum post está sendo publicado. (O Direct e as respostas de stories seguem normais.)</span>
    <form method="post" action="<?= BASE_URL ?>/automacao_toggle.php" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="estado" value="0">
      <button type="submit" class="auto-banner__btn"><i class="fa-solid fa-play"></i> Religar agora</button>
    </form>
  </div>
  <?php elseif ($pausaAutoSeg > 0): ?>
  <div class="auto-banner auto-banner--rl">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <span>Pausa automática (limite do Instagram). Próxima tentativa em
      <b id="autoClock" data-seg="<?= (int) $pausaAutoSeg ?>" data-modo="rl">--:--</b>.</span>
  </div>
  <?php elseif ($proxPostSeg > 0): ?>
  <div class="status-bar">
    <i class="fa-regular fa-clock"></i>
    <span>Próxima postagem em
      <b id="autoClock" data-seg="<?= (int) $proxPostSeg ?>" data-modo="post">--:--</b>.</span>
  </div>
  <?php endif; ?>
  <script>
  (function () {
    var el = document.getElementById('autoClock');
    if (!el) { return; }
    var seg = parseInt(el.getAttribute('data-seg'), 10) || 0;
    var modo = el.getAttribute('data-modo');
    function fmt(s) {
      if (s <= 0) { return '00:00'; }
      var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
      var mm = (m < 10 ? '0' : '') + m, xx = (x < 10 ? '0' : '') + x;
      return h > 0 ? h + ':' + mm + ':' + xx : mm + ':' + xx;
    }
    function tick() {
      if (seg <= 0) {
        el.textContent = modo === 'rl' ? 'tentando agora…' : 'publicando…';
        // recarrega p/ pegar o novo estado (publicou? re-pausou?)
        setTimeout(function () { location.reload(); }, 8000);
        return;
      }
      el.textContent = fmt(seg);
      seg--;
      setTimeout(tick, 1000);
    }
    tick();
  })();
  </script>
<?php endif; ?>
