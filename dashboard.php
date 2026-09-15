<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require __DIR__ . '/init.php';
exigir_login();

$clientes = db()->query(
    'SELECT c.*,
            (SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes p
              WHERE p.cliente_id = c.id AND p.status IN ("agendado","processando")) AS pendentes
       FROM ' . DB_PREFIX . 'clientes c
   ORDER BY c.nome'
)->fetchAll();

/* ---- Agregados para os cards de estatistica do topo ---- */
$totalClientes   = count($clientes);
$totalConectados = 0;
$totalAgendadas  = 0;
foreach ($clientes as $c) {
    if (!empty($c['access_token']) && !empty($c['ig_user_id'])) {
        $totalConectados++;
    }
    $totalAgendadas += (int) $c['pendentes'];
}

/* ---- Modulo IA: custo do mes e rascunhos aguardando ---- */
$inicioMes  = date('Y-m-01 00:00:00');
$custoMesUsd = (float) db()->query('SELECT COALESCE(SUM(custo_usd),0) FROM ' . DB_PREFIX . 'custos_ia
    WHERE criado_em >= ' . db()->quote($inicioMes))->fetchColumn();
$pendAprov  = (int) db()->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes WHERE status="aguardando_aprovacao"')->fetchColumn();
$usdBrl     = (float) cfg_get('ia_usd_brl', '5.40');
$custoMesBrl = $custoMesUsd * $usdBrl;

/* ---- Limite de publicação da Meta (anti-bloqueio) p/ o perfil ativo da IA ---- */
$cliAtivo = (int) (db()->query('SELECT cliente_id FROM ' . DB_PREFIX . 'config_perfil WHERE chave="ia_ativo" AND valor="1" ORDER BY cliente_id LIMIT 1')->fetchColumn() ?: cfg_get('ia_cliente_id', '0'));
$limMeta = $cliAtivo > 0 ? pub_limite_status($cliAtivo) : ['ok' => false];
$capDia  = (int) cfg_get('pub_cap_dia', '40');

$page_title = 'Clientes';
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <div class="page-head">
    <div>
      <h1>Clientes</h1>
      <p>Escolha um cliente para ver o calendário e agendar publicações.</p>
    </div>
    <a class="btn-inline" href="cliente_form.php"><i class="fa-solid fa-plus"></i> Novo cliente</a>
  </div>

  <!-- Cards de estatistica -->
  <?php if (ui_ver('ui_stats_topo')): ?>
  <div class="stats-row">
    <div class="stat-card">
      <span class="stat-card__ic" style="background:#dbeafe;color:#2563eb;"><i class="fa-solid fa-building"></i></span>
      <div><div class="num"><?= (int) $totalClientes ?></div><div class="lbl">Clientes</div></div>
    </div>
    <div class="stat-card">
      <span class="stat-card__ic" style="background:#dcfce7;color:#16a34a;"><i class="fa-solid fa-circle-check"></i></span>
      <div><div class="num"><?= (int) $totalConectados ?></div><div class="lbl">Conectados ao Instagram</div></div>
    </div>
    <div class="stat-card">
      <span class="stat-card__ic" style="background:#fff4e0;color:#c47f12;"><i class="fa-solid fa-calendar-days"></i></span>
      <div><div class="num"><?= (int) $totalAgendadas ?></div><div class="lbl">Publicações agendadas</div></div>
    </div>
    <a class="stat-card" href="aprovacao.php" style="text-decoration:none;color:inherit;">
      <span class="stat-card__ic" style="background:#ede9fe;color:#7c3aed;"><i class="fa-solid fa-clipboard-check"></i></span>
      <div><div class="num"><?= (int) $pendAprov ?></div><div class="lbl">Notícias IA p/ aprovar</div></div>
    </a>
    <div class="stat-card" title="Custo de IA acumulado no mês atual">
      <span class="stat-card__ic" style="background:#dcfce7;color:#16a34a;"><i class="fa-solid fa-coins"></i></span>
      <div>
        <div class="num">R$ <?= number_format($custoMesBrl, 2, ',', '.') ?></div>
        <div class="lbl">Custo IA no mês (US$ <?= number_format($custoMesUsd, 2, '.', ',') ?>)</div>
      </div>
    </div>
    <?php
      $rest = !empty($limMeta['ok']) ? (int) $limMeta['restante'] : null;
      $corLim = $rest === null ? '#94a3b8' : ($rest <= 10 ? '#dc2626' : ($rest <= 25 ? '#d97706' : '#16a34a'));
      $bgLim  = $rest === null ? '#f1f5f9' : ($rest <= 10 ? '#fee2e2' : ($rest <= 25 ? '#fef3c7' : '#dcfce7'));
    ?>
    <a class="stat-card" href="noticias.php" style="text-decoration:none;color:inherit" title="Limite de publicação da Meta nas últimas 24h. Cap interno: <?= $capDia ?>/dia.">
      <span class="stat-card__ic" style="background:<?= $bgLim ?>;color:<?= $corLim ?>;"><i class="fa-solid fa-shield-halved"></i></span>
      <div>
        <?php if ($rest !== null): ?>
          <div class="num" style="color:<?= $corLim ?>"><?= (int) $limMeta['usado'] ?>/<?= (int) $limMeta['total'] ?></div>
          <div class="lbl">Limite Meta 24h · restam <?= $rest ?> (cap <?= $capDia ?>/dia)</div>
        <?php else: ?>
          <div class="num" style="color:#94a3b8">—</div>
          <div class="lbl">Limite Meta (conecte uma conta)</div>
        <?php endif; ?>
      </div>
    </a>
  </div>
  <?php endif; ?>

  <div class="section-head">
    <h2>Seus clientes</h2>
  </div>

  <?php if (!$clientes): ?>
    <div class="empty">
      <div class="empty__mark"><i class="fa-solid fa-user-plus"></i></div>
      <h2>Nenhum cliente ainda</h2>
      <p>Cadastre o primeiro cliente, conecte o Instagram dele e comece a agendar.</p>
      <a class="btn-inline" href="cliente_form.php"><i class="fa-solid fa-plus"></i> Cadastrar cliente</a>
    </div>
  <?php else: ?>
    <div class="grid-clientes">
      <?php
      /* Perfil/insights vêm de CACHE por perfil (TTL 15 min). No máximo 1 cliente
         é atualizado ao vivo por load (timeout curto), os demais servem o cache —
         antes eram 2 chamadas Graph x 25s POR CLIENTE a cada load (500/504). */
      $refreshRestantes = 1;
      foreach ($clientes as $c):
        $cid = (int) $c['id'];
        $conectado = !empty($c['access_token']) && !empty($c['ig_user_id']);
        $perfil = null;
        $alcance7d = null;   // alcance dos ultimos 7 dias (insights)
        if ($conectado) {
            $tok = (string) $c['access_token'];
            $igid = (string) $c['ig_user_id'];
            $pf = ig_cache_por_perfil($cid, 'cache_ig_perfil', 900, $refreshRestantes > 0,
                fn() => ig_perfil($tok, $igid, 8));
            $perfil = $pf['dados'];
            if (ui_ver('ui_card_alcance')) {
                $ins = ig_cache_por_perfil($cid, 'cache_ig_insights', 900, $refreshRestantes > 0,
                    fn() => ig_insights_conta($tok, $igid, 7, 8));
                if (isset($ins['dados']['reach'])) {
                    $alcance7d = (int) $ins['dados']['reach'];
                }
                $pf['live'] = $pf['live'] || $ins['live'];
            }
            if ($pf['live']) {
                $refreshRestantes--;
            }
        }
        $handle = $perfil['username'] ?? $c['ig_username'] ?? '';
      ?>
        <div class="card<?= $conectado ? '' : ' card--off' ?>">
          <a class="card__open" href="cliente.php?id=<?= (int) $c['id'] ?>" aria-label="Abrir <?= e($c['nome']) ?>"></a>

          <div class="card__actions">
            <a class="card__act" href="cliente_form.php?id=<?= (int) $c['id'] ?>" title="Editar" aria-label="Editar">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </a>
            <form method="post" action="cliente_excluir.php" onsubmit="return confirm('Excluir <?= e(addslashes($c['nome'])) ?> e todas as publicações dele?');">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button type="submit" class="card__act card__act--del" title="Excluir" aria-label="Excluir">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
              </button>
            </form>
          </div>

          <?php if ($perfil && !empty($perfil['profile_picture_url'])): ?>
            <div class="card__avatar"><img src="<?= e($perfil['profile_picture_url']) ?>" alt=""></div>
          <?php elseif (!empty($c['foto_perfil']) && is_file(__DIR__ . '/' . $c['foto_perfil'])): ?>
            <div class="card__avatar"><img src="<?= e(asset_v($c['foto_perfil'])) ?>" alt=""></div>
          <?php else: ?>
            <div class="card__avatar"><?= e(iniciais($c['nome'] ?? '?')) ?></div>
          <?php endif; ?>

          <div class="card__name"><?= e($c['nome']) ?></div>
          <div class="card__handle"><?= $handle ? '@' . e($handle) : 'sem @ definido' ?></div>

          <span class="badge-status <?= $conectado ? 'badge-on' : 'badge-off' ?>">
            <i class="fa-solid <?= $conectado ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i>
            <?= $conectado ? 'Conectado' : 'Não conectado' ?>
          </span>

          <?php if ($perfil && ui_ver('ui_card_stats')): ?>
            <div class="card__stats">
              <div><b><?= number_format((int) ($perfil['followers_count'] ?? 0), 0, ',', '.') ?></b><span>Seguidores</span></div>
              <div><b><?= number_format((int) ($perfil['media_count'] ?? 0), 0, ',', '.') ?></b><span>Posts</span></div>
              <div><b><?= number_format((int) ($perfil['follows_count'] ?? 0), 0, ',', '.') ?></b><span>Seguindo</span></div>
            </div>
          <?php endif; ?>

          <?php if (ui_ver('ui_card_rodape')): ?>
          <div class="card__foot">
            <i class="fa-solid fa-calendar-day"></i>
            <span class="card__count"><?= (int) $c['pendentes'] ?> agendada(s)</span>
            <?php if ($alcance7d !== null && ui_ver('ui_card_alcance')): ?>
              <span class="card__count" style="margin-left:auto;" title="Alcance dos ultimos 7 dias">
                <i class="fa-solid fa-chart-line"></i> <?= number_format($alcance7d, 0, ',', '.') ?> (7d)
              </span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <a class="add-card" href="cliente_form.php">
        <span class="add-card__plus">+</span>
        <span>Novo cliente</span>
      </a>
    </div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
