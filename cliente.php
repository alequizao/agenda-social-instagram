<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require __DIR__ . '/init.php';
exigir_login();

require __DIR__ . '/lib_historico.php';

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
$st->execute([$id]);
$c = $st->fetch();
if (!$c) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// abas: "agenda" (agendamentos do sistema) | "historico" (o que ja foi ao ar no IG)
$aba = (($_GET['aba'] ?? '') === 'historico') ? 'historico' : 'agenda';
$conectado = !empty($c['access_token']) && !empty($c['ig_user_id']);

// ---- POST: sincronizar historico com o Instagram ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('CSRF invalido.');
    }
    if (($_POST['acao'] ?? '') === 'sync_historico') {
        if (!$conectado) {
            $_SESSION['flash'] = ['Conecte o Instagram deste cliente antes de sincronizar.', 'erro'];
        } else {
            $rm = historico_sync_midias(db(), $c, 50);
            $rs = historico_capturar_stories(db(), $c);
            if ($rm['ok']) {
                $msg = "Histórico atualizado: {$rm['novas']} novo(s) post(s), {$rm['insights']} com estatística";
                $msg .= $rs['ok'] ? "; {$rs['capturados']} story(ies) ativo(s) capturado(s)." : '.';
                $_SESSION['flash'] = [$msg, 'ok'];
            } else {
                $_SESSION['flash'] = ['Não foi possível sincronizar: ' . $rm['erro'], 'erro'];
            }
        }
    }
    header('Location: ' . BASE_URL . '/cliente.php?id=' . $id . '&aba=historico&mes=' . urlencode((string) ($_GET['mes'] ?? date('Y-m'))));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// mes visivel (YYYY-MM), padrao mes atual
$mesParam = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $mesParam)) {
    $mesParam = date('Y-m');
}
$ini = DateTime::createFromFormat('Y-m-d', $mesParam . '-01');
if (!$ini) {
    $ini = new DateTime('first day of this month');
}
$ini->setTime(0, 0, 0);
$fim = (clone $ini)->modify('first day of next month');

$mesAtual = (int) $ini->format('n');
$anoAtual = (int) $ini->format('Y');
$mesAnterior = (clone $ini)->modify('-1 month')->format('Y-m');
$mesProximo  = (clone $ini)->modify('+1 month')->format('Y-m');

$porDia = [];
if ($aba === 'historico') {
    // o que JA foi ao ar no Instagram (feed/reels + stories capturados) + estatisticas
    $porDia = historico_do_mes(db(), $id, $ini->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s'));
} else {
    // publicacoes agendadas/feitas por ESTE sistema
    $q = db()->prepare(
        'SELECT p.id, p.tipo, p.legenda, p.agendado_para, p.status,
                (SELECT m.arquivo FROM publicacao_midia m WHERE m.publicacao_id = p.id ORDER BY m.posicao, m.id LIMIT 1) AS capa,
                (SELECT m.tipo    FROM publicacao_midia m WHERE m.publicacao_id = p.id ORDER BY m.posicao, m.id LIMIT 1) AS capa_tipo,
                (SELECT COUNT(*)  FROM publicacao_midia m WHERE m.publicacao_id = p.id) AS qtd
         FROM publicacoes p
         WHERE p.cliente_id = ? AND p.agendado_para >= ? AND p.agendado_para < ?
           AND p.status IN ("agendado","processando","publicado","erro","cancelado")
         ORDER BY p.agendado_para'
    );
    $q->execute([$id, $ini->format('Y-m-d H:i:s'), $fim->format('Y-m-d H:i:s')]);
    foreach ($q->fetchAll() as $p) {
        $porDia[substr($p['agendado_para'], 0, 10)][] = $p;
    }
}

$totalMes = array_sum(array_map('count', $porDia));

// foto/dados ao vivo do perfil (quando conectado)
$perfil = null;
if ($conectado) {
    $pf = ig_perfil((string) $c['access_token'], (string) $c['ig_user_id']);
    if ($pf['ok']) {
        $perfil = $pf['dados'];
    }
}

// monta a grade (domingo a sabado)
$primeiroDiaSemana = (int) $ini->format('w'); // 0=dom
$diasNoMes = (int) $ini->format('t');
$meses = meses_pt();
$hoje = date('Y-m-d');

$page_title = $c['nome'];
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <a class="back" href="dashboard.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
    Voltar para clientes
  </a>

  <div class="cli-head">
    <?php if ($perfil && !empty($perfil['profile_picture_url'])): ?>
      <div class="cli-head__avatar"><img src="<?= e($perfil['profile_picture_url']) ?>" alt=""></div>
    <?php else: ?>
      <?= avatar_cliente($c, 'cli-head__avatar') ?>
    <?php endif; ?>
    <div>
      <h1><?= e($c['nome']) ?></h1>
      <div class="cli-head__sub">
        <span><?= $c['ig_username'] ? '@' . e($c['ig_username']) : 'sem @ definido' ?></span>
        <span>&middot;</span>
        <span class="dot <?= $conectado ? 'on' : 'off' ?>"></span>
        <span><?= $conectado ? 'Instagram conectado' : 'Instagram nao conectado' ?></span>
      </div>
    </div>
    <div class="cli-head__acoes">
      <a class="btn-ghost" href="cliente_instagram.php?id=<?= (int) $c['id'] ?>"><?= $conectado ? 'Instagram conectado' : 'Conectar Instagram' ?></a>
      <a class="btn-inline"
         href="publicacao_form.php?cliente_id=<?= (int) $c['id'] ?>&data=<?= e(date('Y-m-d')) ?>">+ Nova publicacao</a>
    </div>
  </div>

  <?php if (!empty($flash)): ?>
    <div class="aviso aviso--<?= e($flash[1]) ?>"><?= e($flash[0]) ?></div>
  <?php endif; ?>

  <div class="cli-tabs">
    <a class="cli-tab<?= $aba === 'agenda' ? ' is-on' : '' ?>" href="cliente.php?id=<?= $id ?>&mes=<?= e($mesParam) ?>">
      <i class="fa-solid fa-calendar-days"></i> Agenda
    </a>
    <a class="cli-tab<?= $aba === 'historico' ? ' is-on' : '' ?>" href="cliente.php?id=<?= $id ?>&aba=historico&mes=<?= e($mesParam) ?>">
      <i class="fa-solid fa-clock-rotate-left"></i> Histórico &amp; estatísticas
    </a>
    <?php if ($aba === 'historico'): ?>
      <form method="post" style="margin:0 0 0 auto">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="sync_historico">
        <button type="submit" class="btn-ghost"<?= $conectado ? '' : ' disabled' ?>
                title="Puxa os últimos posts e estatísticas do Instagram e captura os stories ativos">
          <i class="fa-solid fa-rotate"></i> Sincronizar com o Instagram
        </button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($aba === 'historico'): ?>
    <p class="cal__resumo" style="margin-top:0">
      <i class="fa-solid fa-circle-info"></i>
      Feed/Reels têm histórico completo. <b>Stories só aparecem a partir de quando a captura começou</b>
      (a API do Instagram não fornece stories antigos). Clique em <b>Sincronizar</b> para trazer os mais recentes.
    </p>
  <?php endif; ?>

  <div class="cal">
    <div class="cal__bar">
      <div class="cal__title"><?= e($meses[$mesAtual]) ?> <?= $anoAtual ?></div>
      <?php $abaQs = $aba === 'historico' ? '&aba=historico' : ''; ?>
      <div class="cal__nav">
        <a class="cal__btn" href="cliente.php?id=<?= $id ?><?= $abaQs ?>&mes=<?= e($mesAnterior) ?>" aria-label="Mes anterior">&#8249;</a>
        <a class="cal__btn cal__btn--text" href="cliente.php?id=<?= $id ?><?= $abaQs ?>&mes=<?= e(date('Y-m')) ?>">Hoje</a>
        <a class="cal__btn" href="cliente.php?id=<?= $id ?><?= $abaQs ?>&mes=<?= e($mesProximo) ?>" aria-label="Proximo mes">&#8250;</a>
      </div>
    </div>

    <div class="cal__grid cal__head">
      <?php foreach (['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'] as $d): ?>
        <div class="cal__weekday"><?= $d ?></div>
      <?php endforeach; ?>
    </div>

    <div class="cal__grid">
      <?php for ($i = 0; $i < $primeiroDiaSemana; $i++): ?>
        <div class="cal__cell cal__cell--empty"></div>
      <?php endfor; ?>

      <?php for ($d = 1; $d <= $diasNoMes; $d++):
        $dataIso = sprintf('%04d-%02d-%02d', $anoAtual, $mesAtual, $d);
        $posts = $porDia[$dataIso] ?? [];
        $ehHoje = $dataIso === $hoje;
      ?>
        <div class="cal__cell<?= $ehHoje ? ' cal__cell--today' : '' ?>">
          <?php if ($aba === 'agenda'): ?>
            <a class="cal__add" href="publicacao_form.php?cliente_id=<?= $id ?>&data=<?= $dataIso ?>"
               aria-label="Agendar em <?= $d ?>"></a>
          <?php endif; ?>
          <div class="cal__daynum"><?= $d ?></div>
          <div class="cal__posts">
            <?php if ($aba === 'historico'): ?>
              <?php foreach ($posts as $p):
                $ehStory = $p['kind'] === 'story';
                $hora = substr((string) $p['publicado_em'], 11, 5);
                if ($ehStory) {
                    $tipoTxt = 'Story';
                    $viram = $p['alcance'] !== null ? number_format((int) $p['alcance'], 0, ',', '.') : '—';
                    $inter = (int) $p['respostas'] + (int) $p['toques_frente'] + (int) $p['toques_voltar'];
                    $stat = '👁 ' . $viram . ' viram · 💬 ' . number_format($inter, 0, ',', '.') . ' interaç.';
                } else {
                    $tipoTxt = ($p['produto'] === 'REELS') ? 'Reels' : (($p['media_type'] === 'CAROUSEL_ALBUM') ? 'Carrossel' : 'Feed');
                    $likes = $p['curtidas'] !== null ? number_format((int) $p['curtidas'], 0, ',', '.') : '0';
                    $alc   = $p['alcance'] !== null ? ' · ' . number_format((int) $p['alcance'], 0, ',', '.') . ' alc.' : '';
                    $stat  = '♥ ' . $likes . $alc;
                }
                $tipoCls = $ehStory ? 'story' : 'feed';
              ?>
                <a class="post-chip post-chip--<?= $tipoCls ?> is-historico"
                   href="<?= e($p['permalink'] ?: '#') ?>"<?= $p['permalink'] ? ' target="_blank" rel="noopener"' : '' ?>
                   title="<?= e($tipoTxt) ?> · <?= e($hora) ?> · <?= e($stat) ?>">
                  <?php if (!empty($p['thumb_url'])): ?>
                    <span class="post-chip__thumb" style="background-image:url('<?= e($p['thumb_url']) ?>')"></span>
                  <?php else: ?>
                    <span class="post-chip__thumb post-chip__thumb--icon"></span>
                  <?php endif; ?>
                  <span class="post-chip__time"><?= e($hora) ?></span>
                  <span class="post-chip__type"><?= e($tipoTxt) ?></span>
                  <span class="post-chip__stat"><?= e($stat) ?></span>
                </a>
              <?php endforeach; ?>
            <?php else: ?>
              <?php foreach ($posts as $p): ?>
                <a class="post-chip post-chip--<?= e($p['tipo']) ?> is-<?= e($p['status']) ?>"
                   href="publicacao_form.php?id=<?= (int) $p['id'] ?>"
                   title="<?= e(rotulo_tipo($p['tipo'])) ?> &middot; <?= e(substr($p['agendado_para'], 11, 5)) ?>">
                  <?php $prevA = $p['capa'] ? midia_preview((string) $p['capa']) : ''; ?>
                  <?php if ($prevA && midia_eh_imagem($prevA)): ?>
                    <span class="post-chip__thumb" style="background-image:url('<?= e(asset_v($prevA)) ?>')"></span>
                  <?php else: ?>
                    <span class="post-chip__thumb post-chip__thumb--icon"></span>
                  <?php endif; ?>
                  <span class="post-chip__time"><?= e(substr($p['agendado_para'], 11, 5)) ?></span>
                  <span class="post-chip__type"><?= e(rotulo_tipo($p['tipo'])) ?></span>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  </div>

  <?php if ($aba === 'historico'):
    $tFeed = 0; $tStory = 0; $sumAlc = 0; $sumLik = 0; $stViram = 0; $stInter = 0;
    foreach ($porDia as $dia) {
        foreach ($dia as $p) {
            if ($p['kind'] === 'story') {
                $tStory++;
                $stViram += (int) $p['alcance'];
                $stInter += (int) $p['respostas'] + (int) $p['toques_frente'] + (int) $p['toques_voltar'];
            } else {
                $tFeed++; $sumLik += (int) $p['curtidas'];
            }
            $sumAlc += (int) $p['alcance'];
        }
    }
  ?>
    <p class="cal__resumo">
      <?= $tFeed ?> feed/reels · <?= $tStory ?> stories · alcance somado <?= number_format($sumAlc, 0, ',', '.') ?><br>
      <b>Stories:</b> 👁 <?= number_format($stViram, 0, ',', '.') ?> viram · 💬 <?= number_format($stInter, 0, ',', '.') ?> interações · ♥ <?= number_format($sumLik, 0, ',', '.') ?> curtidas (feed) neste mês.
    </p>
  <?php else: ?>
    <p class="cal__resumo"><?= $totalMes ?> publicacao(oes) neste mes.</p>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
