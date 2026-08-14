<?php
declare(strict_types=1);

/**
 * onibus.php — aba "Horário dos Ônibus" (Maceió/Rio Largo).
 *
 * - Consulta horários/rotas por linha (dados raspados do CittaMobi via cron).
 * - Previsão AO VIVO sob demanda (protobuf), com aviso de que é experimental.
 * - Liga a auto-resposta do Direct por perfil (dm_onibus_ativo).
 *
 * A RASPAGEM pesada roda no cron_onibus.php. Aqui só há ações pontuais
 * (atualizar catálogo / detalhar 1 linha / previsão), sempre a pedido do usuário.
 */

require __DIR__ . '/init.php';
require __DIR__ . '/lib_onibus.php';
require __DIR__ . '/lib_direct.php';
exigir_login();

$db = db();

/**
 * Renderiza o corpo "vivo" de um card de linha (rota + horários + caixa de tempo real).
 * Usado tanto na primeira renderização quanto na atualização via AJAX (ação "detalhar"),
 * garantindo markup idêntico nos dois caminhos.
 */
function onibus_card_body_html(array $l): string
{
    $horarios = json_decode((string) ($l['horarios'] ?? ''), true) ?: [];
    $horarios = $horarios ? onibus_ordena_dias($horarios) : [];
    $paradas  = json_decode((string) ($l['paradas'] ?? ''), true) ?: [];
    $temTR    = ($l['service_id'] ?? '') !== '' && !empty($paradas);
    ob_start(); ?>
        <?php if ($paradas): ?>
          <div class="ob-rota"><i class="fa-solid fa-route"></i>
            <?= e(mb_strimwidth((string) ($paradas[0]['nome'] ?? ''), 0, 46, '…')) ?>
            &nbsp;→&nbsp;
            <?= e(mb_strimwidth((string) ($paradas[count($paradas) - 1]['nome'] ?? ''), 0, 46, '…')) ?>
          </div>
        <?php endif; ?>

        <?php if ($horarios): ?>
          <div class="ob-dias">
            <?php foreach ($horarios as $dia => $faixa): ?>
              <div class="ob-dia<?= $faixa === '-' ? ' ob-dia--off' : '' ?>">
                <span><?= e((string) $dia) ?></span><b><?= $faixa === '-' ? 'sem circulação' : e((string) $faixa) ?></b>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="ob-vazio">Horários ainda não carregados — clique em <i class="fa-solid fa-rotate"></i> para buscar agora (ou aguarde o cron).</div>
        <?php endif; ?>

        <?php if ($temTR): ?><div class="ob-trbox" id="tr-<?= (int) $l['id'] ?>" hidden></div><?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

/* ---------- AJAX: previsão ao vivo de uma linha (JSON) ---------- */
if (($_GET['acao'] ?? '') === 'previsao') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int) ($_GET['id'] ?? 0);
    $l = $db->query('SELECT service_id, paradas FROM onibus_linhas WHERE id=' . $id)->fetch();
    if (!$l || $l['service_id'] === '') {
        echo json_encode(['ok' => false, 'erro' => 'Linha sem dados de tempo real (detalhe a linha primeiro).']);
        exit;
    }
    $paradas = json_decode((string) $l['paradas'], true) ?: [];
    $stopIdx = max(0, (int) ($_GET['stop'] ?? 0));
    $stopId = (string) ($paradas[$stopIdx]['id'] ?? ($paradas[0]['id'] ?? ''));
    $pv = onibus_previsao((string) $l['service_id'], $stopId);
    echo json_encode([
        'ok'       => $pv['ok'],
        'erro'     => $pv['erro'],
        'parada'   => (string) ($paradas[$stopIdx]['nome'] ?? ''),
        'veiculos' => array_map(fn($v) => ['veiculo' => $v['veiculo'], 'min' => $v['minutos']], $pv['veiculos']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- POST: ações ---------- */
$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('CSRF inválido.');
    }
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'config') {
        cfg_set('onibus_ativo', isset($_POST['onibus_ativo']) ? '1' : '0');
        cfg_set('onibus_cidade_slug', trim((string) ($_POST['onibus_cidade_slug'] ?? '')) ?: ONIBUS_CIDADE_PADRAO);
        cfg_set('onibus_token', trim((string) ($_POST['onibus_token'] ?? '')));
        foreach ($db->query('SELECT id FROM ' . DB_PREFIX . 'clientes')->fetchAll() as $c) {
            pcfg_set((int) $c['id'], 'dm_onibus_ativo', isset($_POST['dm_onibus_' . (int) $c['id']]) ? '1' : '0');
        }
        $msg = 'Configuração salva.';
    } elseif ($acao === 'catalogo') {
        $r = onibus_scrape_catalogo($db);
        $msg = $r['ok'] ? "Catálogo atualizado: {$r['n']} linhas." : '';
        $err = $r['ok'] ? '' : ('Falha ao atualizar catálogo: ' . $r['erro']);
    } elseif ($acao === 'detalhar') {
        $id = (int) ($_POST['id'] ?? 0);
        $isAjax = ($_POST['ajax'] ?? '') === '1';
        $l = $db->query('SELECT id, slug FROM onibus_linhas WHERE id=' . $id)->fetch();
        if ($l) {
            $r = onibus_scrape_linha($db, $l);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                $full   = $db->query('SELECT * FROM onibus_linhas WHERE id=' . $id)->fetch() ?: [];
                $ultima = (string) ($db->query('SELECT MAX(atualizado_em) FROM onibus_linhas')->fetchColumn() ?: '');
                echo json_encode([
                    'ok'     => (bool) $r['ok'],
                    'erro'   => $r['ok'] ? '' : (string) $r['erro'],
                    'html'   => ($r['ok'] && $full) ? onibus_card_body_html($full) : '',
                    'ultima' => $ultima ? date('d/m H:i', strtotime($ultima)) : '—',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $msg = $r['ok'] ? 'Linha atualizada (horários, paradas e tempo real).' : '';
            $err = $r['ok'] ? '' : ('Falha ao detalhar linha: ' . $r['erro']);
        } elseif ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'erro' => 'Linha não encontrada.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

/* ---------- dados da tela ---------- */
$q = trim((string) ($_GET['q'] ?? ''));
$resultados = $q !== '' ? onibus_buscar($db, $q, 12) : [];

$total    = (int) $db->query('SELECT COUNT(*) FROM onibus_linhas')->fetchColumn();
$detalhadas = (int) $db->query('SELECT COUNT(*) FROM onibus_linhas WHERE detalhe_em IS NOT NULL')->fetchColumn();
$ultima   = (string) ($db->query('SELECT MAX(atualizado_em) FROM onibus_linhas')->fetchColumn() ?: '');
$clientes = $db->query('SELECT id, nome FROM ' . DB_PREFIX . 'clientes ORDER BY nome')->fetchAll();

/* Acompanhamentos (assinaturas de aviso "me avise quando o ônibus chegar") */
$alertas = $db->query('SELECT a.*, c.nome AS cliente, l.route_code, l.service_mnemonic, l.route_mnemonic
    FROM onibus_alertas a
    LEFT JOIN ' . DB_PREFIX . 'clientes c ON c.id = a.cliente_id
    LEFT JOIN onibus_linhas l ON l.id = a.linha_id
    ORDER BY a.ativo DESC, a.criado_em DESC LIMIT 60')->fetchAll();
$alertasAtivos = (int) $db->query('SELECT COUNT(*) FROM onibus_alertas WHERE ativo=1')->fetchColumn();

$page_title = 'Horário dos Ônibus';
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <div class="page-head">
    <div>
      <h1>Horário dos Ônibus</h1>
      <p>Linhas de Maceió e Rio Largo — horários, rotas e tempo real (fonte: CittaMobi).</p>
    </div>
  </div>

  <?php if ($msg): ?><div class="ob-alert ob-alert--ok"><i class="fa-solid fa-circle-check"></i> <?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="ob-alert ob-alert--err"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($err) ?></div><?php endif; ?>

  <div class="ob-stats">
    <div class="ob-stat"><span class="ob-stat__n"><?= number_format($total, 0, ',', '.') ?></span><span class="ob-stat__l">linhas no catálogo</span></div>
    <div class="ob-stat"><span class="ob-stat__n"><?= number_format($detalhadas, 0, ',', '.') ?></span><span class="ob-stat__l">com horários carregados</span></div>
    <div class="ob-stat"><span class="ob-stat__n" id="statUltima" style="font-size:16px"><?= $ultima ? e(date('d/m H:i', strtotime($ultima))) : '—' ?></span><span class="ob-stat__l">última atualização</span></div>
    <div class="ob-stat"><span class="ob-stat__n"><?= (int) $alertasAtivos ?></span><span class="ob-stat__l">avisos ativos agora</span></div>
  </div>

  <!-- Acompanhamentos (quem pediu "me avise quando o ônibus chegar") -->
  <details class="ob-cfg" <?= $alertasAtivos > 0 ? 'open' : '' ?>>
    <summary><i class="fa-solid fa-bell"></i> Acompanhamentos de ônibus (<?= (int) $alertasAtivos ?> ativo<?= $alertasAtivos === 1 ? '' : 's' ?>)</summary>
    <div style="padding:6px 2px 14px">
      <?php if (!$alertas): ?>
        <p class="ob-vazio">Ninguém pediu aviso de chegada ainda. Quando um seguidor tocar em “🔔 me avise”, aparece aqui.</p>
      <?php else: ?>
        <div class="ob-tab-wrap" style="overflow-x:auto">
        <table class="ob-tab">
          <thead><tr><th>Status</th><th>Perfil</th><th>Linha</th><th>Ponto</th><th>Faltam</th><th>Avisos</th><th>Criado</th></tr></thead>
          <tbody>
          <?php foreach ($alertas as $a):
            $nomeLinha = trim((string) $a['route_code'] . ' ' . (string) ($a['service_mnemonic'] ?: $a['route_mnemonic'])); ?>
            <tr>
              <td data-label="Status"><?php if ((int) $a['ativo'] === 1): ?><span class="ob-pill ob-pill--on">acompanhando</span>
                  <?php elseif ($a['avisado_em']): ?><span class="ob-pill ob-pill--done">concluído</span>
                  <?php else: ?><span class="ob-pill">encerrado</span><?php endif; ?></td>
              <td data-label="Perfil"><?= e((string) ($a['cliente'] ?? '—')) ?></td>
              <td data-label="Linha"><?= e($nomeLinha !== '' ? $nomeLinha : '—') ?></td>
              <td data-label="Ponto"><?= e(mb_strimwidth((string) $a['stop_nome'], 0, 34, '…')) ?></td>
              <td data-label="Faltam"><?= $a['ultimo_min'] !== null ? '~' . (int) $a['ultimo_min'] . ' min' : '—' ?></td>
              <td data-label="Avisos"><?= (int) $a['avisos'] ?></td>
              <td data-label="Criado"><?= e(date('d/m H:i', strtotime((string) $a['criado_em']))) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </details>

  <!-- Busca -->
  <form method="get" class="ob-search">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Buscar por número (ex.: 0004) ou destino/bairro (ex.: rio largo, centro)…" autofocus>
    <button class="btn--auto" type="submit">Buscar</button>
  </form>

  <?php if ($q !== ''): ?>
    <?php if (!$resultados): ?>
      <div class="empty"><i class="fa-solid fa-bus"></i><p>Nenhuma linha encontrada para “<?= e($q) ?>”.</p></div>
    <?php else: ?>
      <?php foreach ($resultados as $l):
        $horarios = json_decode((string) $l['horarios'], true) ?: [];
        $horarios = $horarios ? onibus_ordena_dias($horarios) : [];
        $paradas  = json_decode((string) $l['paradas'], true) ?: [];
        $temTR    = ($l['service_id'] ?? '') !== '' && !empty($paradas);
      ?>
      <div class="ob-card">
        <div class="ob-card__head">
          <div>
            <span class="ob-code"><?= e(trim((string) $l['route_code'])) ?></span>
            <b><?= e((string) ($l['service_mnemonic'] ?: $l['route_mnemonic'])) ?></b>
            <span class="ob-comp"><?= e((string) $l['company']) ?></span>
          </div>
          <div class="ob-card__acts">
            <?php if ($temTR): ?>
              <button class="btn-inline ob-tr" data-id="<?= (int) $l['id'] ?>" type="button"><i class="fa-solid fa-satellite-dish"></i> Ao vivo</button>
            <?php endif; ?>
            <form method="post" class="ob-reload-form" data-id="<?= (int) $l['id'] ?>" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="acao" value="detalhar">
              <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
              <button class="btn-inline" type="submit" title="Recarregar horários/paradas desta linha"><i class="fa-solid fa-rotate"></i></button>
            </form>
          </div>
        </div>

        <div class="ob-card__body" id="cardbody-<?= (int) $l['id'] ?>">
          <?= onibus_card_body_html($l) ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Configuração -->
  <details class="ob-cfg"<?= $q === '' ? ' open' : '' ?>>
    <summary><i class="fa-solid fa-gear"></i> Configuração &amp; auto-resposta no Direct</summary>
    <form method="post" class="ob-cfg__body">
      <?= csrf_field() ?>
      <input type="hidden" name="acao" value="config">

      <label class="switch-row">
        <input type="checkbox" name="onibus_ativo" <?= onibus_ativo() ? 'checked' : '' ?>>
        <span><b>Módulo de ônibus ligado</b> — libera consulta e auto-resposta no Direct.</span>
      </label>

      <div class="ob-cfg__grid">
        <div class="field">
          <label>Cidade (slug CittaMobi)</label>
          <input type="text" name="onibus_cidade_slug" value="<?= e(onibus_cidade_slug()) ?>" placeholder="alagoas/maceio">
        </div>
        <div class="field">
          <label>Token da fonte (header <code>username</code>)</label>
          <input type="text" name="onibus_token" value="<?= e((string) cfg_get('onibus_token', '')) ?>" placeholder="(padrão embutido — só preencha se precisar trocar)">
        </div>
      </div>

      <div class="ob-cfg__perfis">
        <p class="ob-cfg__t">Responder horários no Direct destes perfis quando alguém perguntar por uma linha:</p>
        <?php if (!$clientes): ?>
          <p class="ob-vazio">Nenhum cliente cadastrado.</p>
        <?php else: foreach ($clientes as $c): ?>
          <label class="switch-row switch-row--mini">
            <input type="checkbox" name="dm_onibus_<?= (int) $c['id'] ?>" <?= pcfg_get((int) $c['id'], 'dm_onibus_ativo', '0') === '1' ? 'checked' : '' ?>>
            <span><?= e((string) $c['nome']) ?></span>
          </label>
        <?php endforeach; endif; ?>
      </div>

      <div class="form-actions" style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn--auto" type="submit"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
      </div>
    </form>

    <form method="post" class="ob-cfg__cat">
      <?= csrf_field() ?>
      <input type="hidden" name="acao" value="catalogo">
      <button class="btn-inline" type="submit"><i class="fa-solid fa-download"></i> Atualizar catálogo agora</button>
      <span class="ob-hint">A raspagem completa (horários de todas as linhas) roda pelo <code>cron_onibus.php</code>. A previsão ao vivo é experimental e depende da fonte.</span>
    </form>
  </details>
</main>

<style>
  .ob-alert{display:flex;align-items:center;gap:9px;padding:11px 16px;border-radius:10px;margin-bottom:14px;font-weight:600;font-size:14px}
  .ob-alert--ok{background:#dcfce7;color:#166534}
  .ob-alert--err{background:#fee2e2;color:#991b1b}
  .ob-stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
  .ob-stat{background:var(--cor-card,#fff);border:1px solid var(--cor-borda,#e5e7eb);border-radius:12px;padding:12px 18px;min-width:150px}
  .ob-stat__n{display:block;font-size:24px;font-weight:800;color:var(--cor-texto,#111);line-height:1.1}
  .ob-stat__l{font-size:12px;color:var(--cor-texto-sec,#6b7280);font-weight:600}
  .ob-search{display:flex;align-items:center;gap:10px;background:var(--cor-card,#fff);border:1px solid var(--cor-borda,#e5e7eb);border-radius:12px;padding:8px 14px;margin-bottom:18px}
  .ob-search i{color:var(--cor-texto-sec,#9ca3af)}
  .ob-search input{flex:1;border:none;outline:none;background:transparent;font-size:15px;color:var(--cor-texto,#111);font-family:inherit}
  .ob-card{background:var(--cor-card,#fff);border:1px solid var(--cor-borda,#e5e7eb);border-radius:14px;padding:14px 16px;margin-bottom:12px}
  .ob-card__head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap}
  .ob-card__head b{font-size:15px;color:var(--cor-texto,#111)}
  .ob-code{display:inline-block;background:#2563EB;color:#fff;font-weight:800;font-size:13px;border-radius:7px;padding:2px 9px;margin-right:8px;vertical-align:middle}
  .ob-comp{display:block;font-size:12px;color:var(--cor-texto-sec,#6b7280);font-weight:600;margin-top:2px}
  .ob-card__acts{display:flex;gap:6px;align-items:center}
  .ob-rota{font-size:12.5px;color:var(--cor-texto-sec,#6b7280);margin:10px 0 6px;font-weight:600}
  .ob-rota i{color:#2563EB;margin-right:5px}
  .ob-dias{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px;margin-top:8px}
  .ob-dia{display:flex;justify-content:space-between;gap:8px;background:var(--cor-fundo,#f8fafc);border-radius:8px;padding:6px 10px;font-size:13px}
  .ob-dia span{color:var(--cor-texto-sec,#6b7280);font-weight:600}
  .ob-dia b{color:var(--cor-texto,#111);font-variant-numeric:tabular-nums}
  .ob-dia--off b{color:#b91c1c;font-weight:600;font-size:12px}
  .ob-vazio,.ob-hint{font-size:12.5px;color:var(--cor-texto-sec,#6b7280);margin-top:8px}
  .ob-trbox{margin-top:10px;padding:10px 12px;border-radius:10px;background:#eff6ff;color:#1e3a8a;font-size:13.5px;font-weight:600}
  .ob-trbox.err{background:#fef3c7;color:#92400e}
  .ob-cfg{margin-top:22px;background:var(--cor-card,#fff);border:1px solid var(--cor-borda,#e5e7eb);border-radius:14px;padding:4px 16px}
  .ob-cfg>summary{cursor:pointer;font-weight:700;padding:12px 0;color:var(--cor-texto,#111)}
  .ob-cfg__grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media(max-width:640px){.ob-cfg__grid{grid-template-columns:1fr}}
  .ob-cfg__t{font-size:13px;font-weight:700;margin:14px 0 6px;color:var(--cor-texto,#111)}
  .ob-cfg__cat{display:flex;align-items:center;gap:12px;flex-wrap:wrap;border-top:1px solid var(--cor-borda,#eee);padding:14px 0;margin-top:6px}
  .ob-tab{width:100%;border-collapse:collapse;font-size:13px}
  .ob-tab th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:var(--cor-texto-sec,#6b7280);padding:8px 10px;border-bottom:1px solid var(--cor-borda,#e5e7eb)}
  .ob-tab td{padding:9px 10px;border-bottom:1px solid var(--cor-borda,#f1f5f9);color:var(--cor-texto,#111);white-space:nowrap}
  .ob-pill{display:inline-block;font-size:11px;font-weight:700;border-radius:20px;padding:3px 9px;background:#e5e7eb;color:#374151}
  .ob-pill--on{background:#dcfce7;color:#166534}
  .ob-pill--done{background:#dbeafe;color:#1e40af}

  /* ---------------- Versão mobile (celular) ---------------- */
  @media (max-width:600px){
    .ob-stats{gap:8px}
    .ob-stat{flex:1 1 calc(50% - 8px);min-width:0;padding:10px 12px}   /* 2 por linha */
    .ob-stat__n{font-size:20px}
    .ob-search{flex-wrap:wrap;padding:10px 12px}
    .ob-search input{flex:1 1 100%;order:2;padding:4px 0}
    .ob-search i{order:1}
    .ob-search .btn--auto{order:3;width:100%;margin-top:4px}
    .ob-card{padding:12px 13px}
    .ob-card__acts{width:100%;justify-content:flex-end;margin-top:4px}
    .ob-dias{grid-template-columns:1fr}
    .ob-cfg{padding:4px 12px}

    /* tabela de acompanhamentos vira "cartões" empilhados */
    .ob-tab thead{position:absolute;left:-9999px}   /* esconde cabeçalho */
    .ob-tab, .ob-tab tbody, .ob-tab tr, .ob-tab td{display:block;width:100%}
    .ob-tab tr{border:1px solid var(--cor-borda,#e5e7eb);border-radius:12px;padding:6px 4px;margin-bottom:10px}
    .ob-tab td{border:none;padding:6px 12px;white-space:normal;display:flex;justify-content:space-between;gap:12px;align-items:center}
    .ob-tab td::before{content:attr(data-label);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--cor-texto-sec,#6b7280)}
    .ob-tab td:first-child{border-bottom:1px dashed var(--cor-borda,#eef2f6);padding-bottom:9px;margin-bottom:3px}
    /* o wrapper de rolagem horizontal não é mais necessário no empilhado */
    .ob-tab-wrap{overflow-x:visible!important}
  }
</style>

<script>
(function () {
  var BASE = '<?= BASE_URL ?>';
  var LIVE_MS = 30000;            // intervalo de auto-atualização da previsão "ao vivo"
  var timers = {};               // id -> timeout handle do polling ao vivo

  /* ---------- Previsão "ao vivo" (tempo real), com auto-atualização ---------- */
  function pintaLive(box, d, id) {
    if (!d.ok) { box.className = 'ob-trbox err'; box.textContent = '⚠️ ' + (d.erro || 'Sem tempo real agora.'); return; }
    if (!d.veiculos || !d.veiculos.length) { box.className = 'ob-trbox err'; box.textContent = '⚠️ Nenhum ônibus previsto no momento para esta parada.'; return; }
    var mins = d.veiculos.filter(function (v) { return v.min > 0; }).slice(0, 4)
      .map(function (v) { return v.min + ' min'; });
    box.className = 'ob-trbox';
    box.textContent = '🛰️ Próximos ônibus (' + (d.parada || '1º ponto') + '): ' + (mins.join(', ') || '—') + ' · atualiza a cada 30s';
  }

  function carregaLive(id, primeira) {
    var box = document.getElementById('tr-' + id);
    if (!box || box.hidden) { return; }               // parou (card recarregado/oculto)
    if (primeira) { box.className = 'ob-trbox'; box.textContent = 'Consultando tempo real…'; }
    fetch(BASE + '/onibus.php?acao=previsao&id=' + encodeURIComponent(id))
      .then(function (r) { return r.json(); })
      .then(function (d) { pintaLive(box, d, id); })
      .catch(function () { if (primeira) { box.className = 'ob-trbox err'; box.textContent = '⚠️ Falha ao consultar.'; } })
      .finally(function () {
        var atual = document.getElementById('tr-' + id);
        if (!atual || atual.hidden) { return; }        // não reagenda se sumiu/fechou
        clearTimeout(timers[id]);
        timers[id] = setTimeout(function () { carregaLive(id, false); }, LIVE_MS);
      });
  }

  function bindLiveBtn(btn) {
    if (btn.dataset.bound) { return; }
    btn.dataset.bound = '1';
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-id');
      var box = document.getElementById('tr-' + id);
      if (!box) { return; }
      box.hidden = false;
      carregaLive(id, true);                           // liga a auto-atualização
    });
  }
  document.querySelectorAll('.ob-tr').forEach(bindLiveBtn);

  /* ---------- Recarregar linha (↻) via AJAX, sem recarregar a página ---------- */
  document.querySelectorAll('.ob-reload-form').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var id  = form.getAttribute('data-id');
      var box = document.getElementById('cardbody-' + id);
      var ico = form.querySelector('button i');
      var btn = form.querySelector('button');
      if (!box) { form.submit(); return; }             // fallback: sem o container, envia normal
      if (timers[id]) { clearTimeout(timers[id]); }    // para o polling antigo dessa linha
      if (ico) { ico.classList.add('fa-spin'); }
      if (btn) { btn.disabled = true; }
      var fd = new FormData(form);
      fd.append('ajax', '1');
      fetch(BASE + '/onibus.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok) {
            box.innerHTML = d.html || '';
            var st = document.getElementById('statUltima');
            if (st && d.ultima) { st.textContent = d.ultima; }
            box.querySelectorAll('.ob-tr').forEach(bindLiveBtn); // (caso passe a ter tempo real)
          } else {
            box.insertAdjacentHTML('afterbegin',
              '<div class="ob-vazio" style="color:#b45309">⚠️ ' + ((d && d.erro) || 'Falha ao atualizar.') + '</div>');
          }
        })
        .catch(function () {
          box.insertAdjacentHTML('afterbegin', '<div class="ob-vazio" style="color:#b45309">⚠️ Falha ao atualizar.</div>');
        })
        .finally(function () {
          if (ico) { ico.classList.remove('fa-spin'); }
          if (btn) { btn.disabled = false; }
        });
    });
  });
})();
</script>

<?php require __DIR__ . '/partials/foot.php'; ?>
