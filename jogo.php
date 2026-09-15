<?php

declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * jogo.php — painel do Jogo da Forca do Direct.
 * Mostra ranking, partidas recentes, palavras mais difíceis e liga/desliga por perfil.
 */

require __DIR__ . '/init.php';
require __DIR__ . '/lib_direct.php';
require __DIR__ . '/lib_jogo.php';
require __DIR__ . '/lib_jogos.php';
exigir_login();

$db = db();
$clientes = $db->query('SELECT id, nome FROM ' . DB_PREFIX . 'clientes ORDER BY nome')->fetchAll();
$cliSel = (int) ($_GET['cli'] ?? ($_POST['cli'] ?? 0));
if (!$cliSel && $clientes) {
    $cliSel = (int) $clientes[0]['id'];
}
$flash = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('CSRF invalido.');
    }
    $acao = (string) ($_POST['acao'] ?? '');
    if ($acao === 'toggle') {
        $novo = pcfg_get($cliSel, 'dm_jogo_ativo', '0') === '1' ? '0' : '1';
        pcfg_set($cliSel, 'dm_jogo_ativo', $novo);
        $flash = $novo === '1' ? 'Jogo LIGADO neste perfil.' : 'Jogo desligado neste perfil.';
    } elseif ($acao === 'enviar_ranking') {
        $cli = $db->query('SELECT * FROM ' . DB_PREFIX . 'clientes WHERE id=' . $cliSel)->fetch();
        $alvo = trim((string) ($_POST['remetente_id'] ?? ''));
        if ($cli && $alvo !== '') {
            $r = dm_enviar($db, $cli, $alvo, jogo_ranking_texto($db, $cliSel, 7), 'auto_jogo');
            $flash = !empty($r['ok']) ? 'Ranking enviado no Direct.' : ('Falhou: ' . ($r['erro'] ?? ''));
        }
    } elseif ($acao === 'zerar') {
        // apaga o histórico de partidas deste perfil (o ranking recomeça do zero)
        $st = $db->prepare('DELETE FROM ' . DB_PREFIX . 'jogo_partidas WHERE cliente_id=?');
        $st->execute([$cliSel]);
        $n = $st->rowCount();
        $st2 = $db->prepare('DELETE FROM ' . DB_PREFIX . 'jogo_resultados WHERE cliente_id=?');
        $st2->execute([$cliSel]);
        $db->prepare('DELETE FROM ' . DB_PREFIX . 'jogo_sessoes WHERE cliente_id=?')->execute([$cliSel]);
        $flash = 'Ranking zerado: ' . ($n + $st2->rowCount()) . ' partida(s) apagada(s) em todos os jogos.';
    } elseif ($acao === 'zerar_pessoa') {
        $alvo = trim((string) ($_POST['remetente_id'] ?? ''));
        if ($alvo !== '') {
            $st = $db->prepare('DELETE FROM ' . DB_PREFIX . 'jogo_partidas WHERE cliente_id=? AND remetente_id=?');
            $st->execute([$cliSel, $alvo]);
            $n = $st->rowCount();
            $st2 = $db->prepare('DELETE FROM ' . DB_PREFIX . 'jogo_resultados WHERE cliente_id=? AND remetente_id=?');
            $st2->execute([$cliSel, $alvo]);
            $db->prepare('DELETE FROM ' . DB_PREFIX . 'jogo_sessoes WHERE cliente_id=? AND remetente_id=?')->execute([$cliSel, $alvo]);
            $flash = 'Histórico da pessoa apagado (' . ($n + $st2->rowCount()) . ' partida(s)).';
        }
    } elseif ($acao === 'encerrar') {
        $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_partidas SET status="desistiu" WHERE id=? AND status="ativa"')
           ->execute([(int) ($_POST['id'] ?? 0)]);
        $flash = 'Partida encerrada.';
    }
    $_SESSION['flash'] = [$flash, 'ok'];
    header('Location: ' . BASE_URL . '/jogo.php?cli=' . $cliSel);
    exit;
}

$ativo = pcfg_get($cliSel, 'dm_jogo_ativo', '0') === '1';

$kpi = $db->prepare('SELECT COUNT(*) total,
        SUM(status="ganhou") ganhou,
        SUM(status="perdeu") perdeu,
        SUM(hoje) hoje,
        COUNT(DISTINCT remetente_id) jogadores
    FROM (
        SELECT remetente_id, status, (criado_em >= CURDATE()) hoje
          FROM ' . DB_PREFIX . 'jogo_partidas WHERE cliente_id=?
        UNION ALL
        SELECT remetente_id, status, (criado_em >= CURDATE()) hoje
          FROM ' . DB_PREFIX . 'jogo_resultados WHERE cliente_id=?
    ) u');
$kpi->execute([$cliSel, $cliSel]);
$k = $kpi->fetch() ?: [];

// partidas em andamento: forca + sessões do arcade
$at = $db->prepare('SELECT
        (SELECT COUNT(*) FROM ' . DB_PREFIX . 'jogo_partidas WHERE cliente_id=? AND status="ativa")
      + (SELECT COUNT(*) FROM ' . DB_PREFIX . 'jogo_sessoes  WHERE cliente_id=? AND status="ativa") AS n');
$at->execute([$cliSel, $cliSel]);
$k['ativas'] = (int) $at->fetchColumn();

// totais só da forca (o bloco do arcade lista jogo a jogo)
$kf = $db->prepare('SELECT COUNT(*) total, SUM(status="ganhou") ganhou, SUM(criado_em >= CURDATE()) hoje
    FROM ' . DB_PREFIX . 'jogo_partidas WHERE cliente_id=?');
$kf->execute([$cliSel]);
$forcaTot = $kf->fetch() ?: [];

$ranking = jogos_ranking($db, $cliSel, 7, 10);

$stA = $db->prepare('SELECT jogo, COUNT(*) n, SUM(status="ganhou") g, SUM(criado_em >= CURDATE()) hoje
    FROM ' . DB_PREFIX . 'jogo_resultados WHERE cliente_id=? GROUP BY jogo ORDER BY n DESC');
$stA->execute([$cliSel]);
$arcade = $stA->fetchAll();
$stS = $db->prepare('SELECT s.jogo, s.criado_em, COALESCE(NULLIF(c.nome,""), s.remetente_id) quem
    FROM ' . DB_PREFIX . 'jogo_sessoes s
    LEFT JOIN ' . DB_PREFIX . 'dm_conversas c ON c.cliente_id=s.cliente_id AND c.remetente_id=s.remetente_id
    WHERE s.cliente_id=? AND s.status="ativa" ORDER BY s.id DESC LIMIT 20');
$stS->execute([$cliSel]);
$sessoesAtivas = $stS->fetchAll();
$catJogos = jogos_catalogo();

$stP = $db->prepare('SELECT * FROM (
        SELECT "forca" jogo, j.remetente_id, j.status, j.criado_em,
               j.palavra AS titulo, j.dica,
               CONCAT(j.erros, "/", j.max_erros, " erros") AS extra,
               j.chave, j.letras
          FROM ' . DB_PREFIX . 'jogo_partidas j WHERE j.cliente_id=?
        UNION ALL
        SELECT r.jogo, r.remetente_id, r.status, r.criado_em,
               r.detalhe AS titulo, "" dica,
               CONCAT(r.pontos, " ponto(s)") AS extra,
               "" chave, "" letras
          FROM ' . DB_PREFIX . 'jogo_resultados r WHERE r.cliente_id=?
    ) u ORDER BY criado_em DESC LIMIT 40');
$stP->execute([$cliSel, $cliSel]);
$partidas = $stP->fetchAll();
foreach ($partidas as $i => $pp) {
    $n = $db->prepare('SELECT nome FROM ' . DB_PREFIX . 'dm_conversas WHERE cliente_id=? AND remetente_id=?');
    $n->execute([$cliSel, (string) $pp['remetente_id']]);
    $partidas[$i]['quem'] = trim((string) $n->fetchColumn()) ?: (string) $pp['remetente_id'];
}

$stJ = $db->prepare('SELECT u.remetente_id,
        COALESCE(NULLIF(c.nome,""), u.remetente_id) AS quem, c.segue, c.seguidores,
        COUNT(*) partidas,
        SUM(u.status="ganhou") vitorias,
        SUM(u.status="perdeu") derrotas,
        SUM(u.status="ativa")  ativas,
        MAX(u.quando) ultima
    FROM (
        SELECT remetente_id, status, atualizado_em quando FROM ' . DB_PREFIX . 'jogo_partidas WHERE cliente_id=?
        UNION ALL
        SELECT remetente_id, status, criado_em     quando FROM ' . DB_PREFIX . 'jogo_resultados WHERE cliente_id=?
    ) u
    LEFT JOIN ' . DB_PREFIX . 'dm_conversas c ON c.cliente_id=' . (int) $cliSel . ' AND c.remetente_id=u.remetente_id
    GROUP BY u.remetente_id, quem, c.segue, c.seguidores
    ORDER BY ultima DESC LIMIT 100');
$stJ->execute([$cliSel, $cliSel]);
$jogadores = $stJ->fetchAll();

$fun = $db->prepare('SELECT COUNT(*) inic,
        SUM(status IN ("ganhou","perdeu","empatou")) term,
        SUM(status IN ("desistiu","expirada")) abandonou,
        COUNT(DISTINCT CASE WHEN criado_em >= (NOW() - INTERVAL 7 DAY) THEN remetente_id END) ativos7
    FROM (
        SELECT remetente_id, status, criado_em FROM ' . DB_PREFIX . 'jogo_partidas WHERE cliente_id=?
        UNION ALL
        SELECT remetente_id, status, criado_em FROM ' . DB_PREFIX . 'jogo_sessoes  WHERE cliente_id=?
    ) u');
$fun->execute([$cliSel, $cliSel]);
$f = $fun->fetch() ?: [];

$stH = $db->prepare('SELECT HOUR(criado_em) h, COUNT(*) n FROM (
        SELECT criado_em FROM ' . DB_PREFIX . 'jogo_partidas WHERE cliente_id=?
        UNION ALL
        SELECT criado_em FROM ' . DB_PREFIX . 'jogo_resultados WHERE cliente_id=?
    ) u GROUP BY h ORDER BY n DESC LIMIT 3');
$stH->execute([$cliSel, $cliSel]);
$pico = $stH->fetchAll();

$stD = $db->prepare('SELECT * FROM ' . DB_PREFIX . 'jogo_dia WHERE cliente_id=? AND dia=CURDATE()');
$stD->execute([$cliSel]);
$doDia = $stD->fetch();
$rankDia = jogo_ranking_dia($db, $cliSel, 10);

$stW = $db->prepare('SELECT palavra, COUNT(*) n, SUM(status="perdeu") perdeu
    FROM ' . DB_PREFIX . 'jogo_partidas WHERE cliente_id=? GROUP BY palavra
    HAVING perdeu > 0 ORDER BY perdeu DESC, n DESC LIMIT 10');
$stW->execute([$cliSel]);
$dificeis = $stW->fetchAll();

/* ---- AJAX: devolve só o bloco que muda (KPIs, arcade, ranking, partidas) ---- */
if (($_GET['ajax'] ?? '') === 'live') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    require __DIR__ . '/partials/jogo_live.php';
    exit;
}

$page_title = 'Jogo da Forca';
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <div class="page-head">
    <div>
      <h1><i class="fa-solid fa-gamepad"></i> Jogo da Forca</h1>
      <p>Quem manda <b>“jogo”</b> no Direct vê o menu do arcade; <b>“forca”</b> começa uma partida de forca. Jogos independentes — não usam o conteúdo do perfil.</p>
    </div>
    <form method="post" style="display:flex;gap:8px;align-items:center">
      <?= csrf_field() ?>
      <input type="hidden" name="cli" value="<?= $cliSel ?>">
      <input type="hidden" name="acao" value="toggle">
      <button class="btn-inline" style="background:<?= $ativo ? '#16a34a' : '#94a3b8' ?>;color:#fff">
        <i class="fa-solid fa-power-off"></i> <?= $ativo ? 'Ligado neste perfil' : 'Desligado neste perfil' ?>
      </button>
    </form>
  </div>

  <?php if (count($clientes) > 1): ?>
    <div class="formbox" style="margin-bottom:14px">
      <form method="get" class="cfg-grid">
        <div class="field field--full">
          <label>Perfil</label>
          <select name="cli" onchange="this.form.submit()">
            <?php foreach ($clientes as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $cliSel ? 'selected' : '' ?>>@<?= e((string) $c['nome']) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="hint">Cada perfil liga/desliga o jogo separadamente. As demais opções ficam em <a href="<?= BASE_URL ?>/direct.php?cli=<?= $cliSel ?>">Direct → Configurações</a>.</small>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <div id="jogoLive" data-cli="<?= $cliSel ?>">
<?php require __DIR__ . '/partials/jogo_live.php'; ?>
  </div>

  <p class="hint" style="margin:14px 2px 0;display:flex;align-items:center;gap:8px">
    <i class="fa-solid fa-rotate" id="jogoLiveIcon"></i>
    <span>Atualizando sozinho a cada 10s · <b id="jogoLiveHora">agora</b></span>
    <button type="button" class="btn-ghost" id="jogoLiveBtn" style="margin-left:auto">Pausar</button>
  </p>
</main>
<script>
(function () {
  var box = document.getElementById('jogoLive');
  if (!box) { return; }
  var hora = document.getElementById('jogoLiveHora');
  var icon = document.getElementById('jogoLiveIcon');
  var btn  = document.getElementById('jogoLiveBtn');
  var cli  = box.getAttribute('data-cli') || '';
  var ligado = true, ocupado = false, timer = null;

  function marcar() {
    var d = new Date();
    if (hora) {
      hora.textContent = d.toLocaleTimeString('pt-BR');
    }
  }
  function atualizar() {
    if (!ligado || ocupado || document.hidden) { return; }
    // não troca o conteúdo enquanto o usuário está confirmando algo
    ocupado = true;
    if (icon) { icon.classList.add('fa-spin'); }
    fetch('<?= BASE_URL ?>/jogo.php?ajax=live&cli=' + encodeURIComponent(cli), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : null; })
      .then(function (html) {
        if (html && html.indexOf('kpi-grid') !== -1) {
          box.innerHTML = html;
          marcar();
        }
      })
      .catch(function () {})
      .then(function () {
        ocupado = false;
        if (icon) { icon.classList.remove('fa-spin'); }
      });
  }
  function agendar() {
    if (timer) { clearInterval(timer); }
    timer = setInterval(atualizar, 10000);
  }
  if (btn) {
    btn.addEventListener('click', function () {
      ligado = !ligado;
      btn.textContent = ligado ? 'Pausar' : 'Retomar';
      if (ligado) { atualizar(); }
    });
  }
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && ligado) { atualizar(); }
  });
  marcar();
  agendar();
})();
</script>
<?php require __DIR__ . '/partials/foot.php'; ?>
