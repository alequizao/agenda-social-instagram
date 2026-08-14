<?php
require __DIR__ . '/init.php';
require __DIR__ . '/lib_publicador.php';
exigir_login();

pub_marcar_atrasados_como_erro(db());

$flash = '';
$flashTipo = 'ok';

$ehAjax = (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
    || !empty($_POST['ajax']) || !empty($_GET['ajax']);

/* Apaga arquivos de mídia de uma publicação (disco). */
function err_apagar_arquivos(PDO $db, int $pubId): void
{
    $st = $db->prepare('SELECT arquivo FROM ' . DB_PREFIX . 'publicacao_midia WHERE publicacao_id=?');
    $st->execute([$pubId]);
    foreach ($st->fetchAll() as $m) {
        $f = __DIR__ . '/' . ltrim((string) $m['arquivo'], '/');
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/* Lista de publicações que falharam (status erro). Mais recentes primeiro. */
function err_buscar_itens(PDO $db): array
{
    return $db->query('SELECT p.id, p.tipo, p.legenda, p.fonte_nome, p.agendado_para, p.status,
            p.erro_msg, p.tentativas, p.atualizado_em,
            (SELECT m.arquivo FROM ' . DB_PREFIX . 'publicacao_midia m WHERE m.publicacao_id=p.id ORDER BY m.posicao, m.id LIMIT 1) AS capa,
            (SELECT m.tipo    FROM ' . DB_PREFIX . 'publicacao_midia m WHERE m.publicacao_id=p.id ORDER BY m.posicao, m.id LIMIT 1) AS capa_tipo,
            c.nome AS cliente
         FROM ' . DB_PREFIX . 'publicacoes p
         JOIN ' . DB_PREFIX . 'clientes c ON c.id = p.cliente_id
         WHERE p.status="erro"
         ORDER BY p.atualizado_em DESC, p.id DESC
         LIMIT 300')->fetchAll();
}

/* Quantos erros existem ao todo. */
function err_total(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes WHERE status="erro"')->fetchColumn();
}

/* HTML de UM card de erro. */
function err_item_html(array $p): string
{
    $prev      = $p['capa'] ? midia_preview((string) $p['capa']) : '';
    $posterUrl = ($prev && midia_eh_imagem($prev)) ? asset_v($prev) : '';
    $mediaUrl  = $p['capa'] ? asset_v((string) $p['capa']) : '';
    $isVideo   = $p['capa_tipo'] === 'video';
    $quando    = $p['atualizado_em'] ? date('d/m H:i', strtotime((string) $p['atualizado_em'])) : '—';
    $tent      = (int) $p['tentativas'];
    $infoLine  = strtoupper((string) $p['tipo']) . ' · falhou ' . $quando . ' · ' . (string) $p['cliente'] . ' · ' . (string) ($p['fonte_nome'] ?: '—');

    ob_start(); ?>
    <div class="news-item news-item--erro" data-id="<?= (int) $p['id'] ?>"
         data-poster="<?= e($posterUrl) ?>"
         data-media="<?= e($mediaUrl) ?>"
         data-tipo="<?= $isVideo ? 'video' : 'image' ?>"
         data-legenda="<?= e((string) ($p['legenda'] ?: '')) ?>"
         data-info="<?= e($infoLine) ?>">
      <div class="news-item__thumb err-prev" title="Pré-visualizar" style="cursor:zoom-in">
        <?php if ($posterUrl !== ''): ?>
          <img src="<?= e($posterUrl) ?>" alt="" loading="lazy">
          <?php if ($isVideo): ?><span class="thumb-vid"><i class="fa-solid fa-play"></i></span><?php endif; ?>
        <?php else: ?>
          <span class="news-item__noimg"><i class="fa-solid fa-image"></i></span>
        <?php endif; ?>
      </div>
      <div class="news-item__body">
        <div class="news-item__meta">
          <span class="tag tag--erro"><i class="fa-solid fa-triangle-exclamation"></i> <?= strtoupper(e((string) $p['tipo'])) ?></span>
          <?php if ($tent > 0): ?><span class="tag"><?= $tent ?> tentativa(s)</span><?php endif; ?>
          <span class="news-item__time">
            <i class="fa-regular fa-clock"></i> falhou <?= e($quando) ?> · <?= e($p['cliente']) ?>
          </span>
        </div>
        <h3 class="news-item__titulo"><?= e(mb_strimwidth((string) ($p['legenda'] ?: 'Story sem legenda'), 0, 110, '…')) ?></h3>
        <div class="err-msg"><i class="fa-solid fa-circle-info"></i> <?= e((string) ($p['erro_msg'] ?: 'Erro desconhecido.')) ?></div>
        <div class="news-item__acoes">
          <form method="post" class="err-form-republicar" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="acao" value="republicar">
            <button type="submit" class="btn-inline"><i class="fa-solid fa-rotate-right"></i> Republicar</button>
          </form>
          <button type="button" class="btn-ghost err-prev"><i class="fa-solid fa-eye"></i> Pré-visualizar</button>
          <a class="btn-ghost" href="publicacao_form.php?id=<?= (int) $p['id'] ?>"><i class="fa-solid fa-pen"></i> Editar</a>
          <form method="post" class="err-form-descartar" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="acao" value="descartar">
            <button type="submit" class="btn-ghost"><i class="fa-solid fa-trash-can"></i> Descartar</button>
          </form>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/* HTML do conteúdo (lista OU estado vazio). */
function err_conteudo_html(array $itens): string
{
    if (!$itens) {
        return '<div class="empty">'
            . '<div class="empty__mark" style="color:#16a34a"><i class="fa-solid fa-circle-check"></i></div>'
            . '<h2>Nenhum erro</h2>'
            . '<p>Tudo certo. Posts que falharem em definitivo (depois das tentativas automáticas) aparecem aqui para você resolver e republicar.</p>'
            . '<a class="btn-inline" href="agendados.php"><i class="fa-solid fa-calendar-check"></i> Ver agendados</a>'
            . '</div>';
    }
    $out = '<div class="news-list">';
    foreach ($itens as $p) {
        $out .= err_item_html($p);
    }
    return $out . '</div>';
}

/* ===== Ações (POST) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('CSRF invalido.');
    }
    $acao = (string) ($_POST['acao'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);
    $db   = db();

    if ($acao === 'republicar' && $id > 0) {
        if (pub_republicar($db, $id)) {
            $flash = 'Post recolocado na fila. Vai publicar em instantes (respeitando o intervalo seguro).';
        } else {
            $flash = 'Esse post não está mais em erro.';
            $flashTipo = 'erro';
        }
    } elseif ($acao === 'republicar_todos') {
        $rows = $db->query('SELECT id FROM ' . DB_PREFIX . 'publicacoes WHERE status="erro"')->fetchAll();
        $n = 0;
        foreach ($rows as $r) {
            if (pub_republicar($db, (int) $r['id'])) {
                $n++;
            }
        }
        $flash = $n . ' post(s) recolocado(s) na fila. Vão sair espaçados, no ritmo seguro.';
    } elseif ($acao === 'descartar' && $id > 0) {
        $st = $db->prepare('SELECT status FROM ' . DB_PREFIX . 'publicacoes WHERE id=? LIMIT 1');
        $st->execute([$id]);
        if ((string) ($st->fetchColumn() ?: '') === 'erro') {
            err_apagar_arquivos($db, $id);
            $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes SET status="cancelado", erro_msg="Descartado pelo usuário (erro)" WHERE id=?')->execute([$id]);
            $flash = 'Post descartado.';
        } else {
            $flash = 'Esse post não está mais em erro.';
            $flashTipo = 'erro';
        }
    } elseif ($acao === 'descartar_todos') {
        $rows = $db->query('SELECT id FROM ' . DB_PREFIX . 'publicacoes WHERE status="erro"')->fetchAll();
        foreach ($rows as $r) {
            err_apagar_arquivos($db, (int) $r['id']);
        }
        $n = $db->exec('UPDATE ' . DB_PREFIX . 'publicacoes SET status="cancelado", erro_msg="Descartado em massa (erro)" WHERE status="erro"');
        $flash = (int) $n . ' erro(s) descartado(s).';
    }

    if ($ehAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'        => $flashTipo === 'ok',
            'flash'     => $flash,
            'flashTipo' => $flashTipo,
            'total'     => err_total($db),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['flash'] = [$flash, $flashTipo];
    header('Location: ' . BASE_URL . '/erros.php');
    exit;
}

/* ===== Refresh AJAX da lista ===== */
if (($_GET['ajax'] ?? '') === 'lista') {
    $itens = err_buscar_itens(db());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'total'    => err_total(db()),
        'count'    => count($itens),
        'conteudo' => err_conteudo_html($itens),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($_SESSION['flash'])) {
    [$flash, $flashTipo] = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$itens = err_buscar_itens(db());
$total = err_total(db());

$page_title = 'Relatório de erros';
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <div class="page-head">
    <div>
      <h1>Relatório de erros</h1>
      <p>Posts que falharam em definitivo (após as tentativas automáticas). Veja o motivo, resolva e <b>republique</b>.</p>
    </div>
    <?php if ($itens): ?>
    <div style="display:flex;gap:8px;margin:0">
      <form method="post" id="errRepublicarTodos" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="republicar_todos">
        <button type="submit" class="btn-inline"><i class="fa-solid fa-rotate-right"></i> Republicar todos</button>
      </form>
      <form method="post" id="errDescartarTodos" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="descartar_todos">
        <button type="submit" class="btn-ghost"><i class="fa-solid fa-trash-can"></i> Descartar todos</button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <div class="aviso aviso--ok" id="errFlash" style="display:<?= $flash ? 'block' : 'none' ?>"><?= e($flash) ?></div>

  <div class="filtros">
    <span class="filtros__info" id="errResumo"><?= $total ?> com erro</span>
    <span class="filtros__live" id="errLive" title="Atualiza sozinho"><i class="fa-solid fa-circle"></i> ao vivo</span>
  </div>

  <div id="errConteudo"><?= err_conteudo_html($itens) ?></div>
</main>

<!-- Popup de pré-visualização -->
<div class="ag-modal" id="errModal" aria-hidden="true">
  <div class="ag-modal__back" data-close></div>
  <div class="ag-modal__card" role="dialog" aria-modal="true">
    <button class="ag-modal__x" data-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
    <div class="ag-modal__media" id="errModalMedia"></div>
    <div class="ag-modal__side">
      <div class="ag-modal__info" id="errModalInfo"></div>
      <div class="ag-modal__cap" id="errModalCap"></div>
    </div>
  </div>
</div>

<style>
.filtros { display: flex; align-items: center; gap: 12px; }
.filtros__live { font-size: 12px; font-weight: 700; color: #16a34a; display: inline-flex; align-items: center; gap: 5px; }
.filtros__live i { font-size: 8px; animation: agpulse 1.6s ease-in-out infinite; }
@keyframes agpulse { 0%,100% { opacity: .35 } 50% { opacity: 1 } }
.news-item.is-saindo { opacity: 0; transform: translateX(12px); transition: opacity .25s, transform .25s; }
.news-item--erro { border-left: 3px solid #dc2626; }
.tag--erro { background: #fee2e2; color: #b91c1c; }
.nav-badge--erro { background: #dc2626 !important; color: #fff !important; }
.err-msg {
  margin: 6px 0 10px; padding: 8px 10px; border-radius: 8px;
  background: rgba(220,38,38,.08); color: #b91c1c; font-size: 13px; line-height: 1.45;
  word-break: break-word;
}
[data-theme="dark"] .err-msg { background: rgba(220,38,38,.16); color: #fca5a5; }
[data-theme="dark"] .tag--erro { background: rgba(220,38,38,.22); color: #fca5a5; }
.ag-modal { position: fixed; inset: 0; z-index: 2000; display: none; }
.ag-modal.is-open { display: block; }
.ag-modal__back { position: absolute; inset: 0; background: rgba(0,0,0,.78); }
.ag-modal__card {
  position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
  display: flex; gap: 0; max-width: 860px; width: calc(100% - 32px); max-height: 92vh;
  background: var(--cor-card,#fff); border-radius: 16px; overflow: hidden; box-shadow: 0 24px 60px -20px rgba(0,0,0,.6);
}
.ag-modal__media { background: #0b0b0c; display: grid; place-items: center; flex: 0 0 auto; }
.ag-modal__media img, .ag-modal__media video { display: block; max-height: 92vh; max-width: 46vw; width: auto; height: auto; }
.ag-modal__side { flex: 1 1 auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; overflow-y: auto; }
.ag-modal__info { font-size: 12px; font-weight: 700; letter-spacing: .03em; color: var(--cor-texto-sec,#777); text-transform: uppercase; }
.ag-modal__cap { font-size: 14px; line-height: 1.5; white-space: pre-wrap; color: var(--cor-texto,#333); }
.ag-modal__x {
  position: absolute; top: 10px; right: 10px; z-index: 3; width: 38px; height: 38px; border-radius: 50%;
  border: none; background: rgba(0,0,0,.55); color: #fff; font-size: 16px; cursor: pointer;
}
@media (max-width: 680px) {
  .ag-modal__card { flex-direction: column; width: calc(100% - 16px); }
  .ag-modal__media img, .ag-modal__media video { max-width: 100%; max-height: 62vh; }
}
</style>
<script>
(function () {
  var modal = document.getElementById('errModal'),
      mMedia = document.getElementById('errModalMedia'),
      mInfo  = document.getElementById('errModalInfo'),
      mCap   = document.getElementById('errModalCap'),
      conteudo = document.getElementById('errConteudo'),
      resumo   = document.getElementById('errResumo'),
      flashBox = document.getElementById('errFlash');
  var modalAberto = false;

  function abrir(item) {
    var poster = item.getAttribute('data-poster'),
        media  = item.getAttribute('data-media'),
        tipo   = item.getAttribute('data-tipo'),
        cap    = item.getAttribute('data-legenda'),
        info   = item.getAttribute('data-info');
    if (tipo === 'video' && media) {
      mMedia.innerHTML = '<video src="' + media + '" poster="' + (poster||'') + '" controls autoplay loop playsinline></video>';
    } else if (poster) {
      mMedia.innerHTML = '<img src="' + poster + '" alt="prévia">';
    } else {
      mMedia.innerHTML = '<div style="padding:60px;color:#888">Sem prévia de imagem.</div>';
    }
    mInfo.textContent = info || '';
    mCap.textContent  = cap || 'Story sem legenda.';
    modal.classList.add('is-open'); modal.setAttribute('aria-hidden', 'false'); modalAberto = true;
  }
  function fechar() {
    modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); modalAberto = false;
    mMedia.innerHTML = '';
  }

  function flash(msg, tipo) {
    if (!msg) { return; }
    flashBox.textContent = msg;
    flashBox.className = 'aviso aviso--' + (tipo || 'ok');
    flashBox.style.display = 'block';
    clearTimeout(flash._t);
    flash._t = setTimeout(function () { flashBox.style.display = 'none'; }, 4500);
  }
  function aplicarResumo(d) {
    if (typeof d.total !== 'undefined') {
      resumo.textContent = d.total + ' com erro';
    }
  }

  function postForm(form) {
    var fd = new FormData(form);
    fd.append('ajax', '1');
    return fetch('erros.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); });
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.classList.contains('err-form-republicar')) {
      e.preventDefault();
      var item = f.closest('.news-item');
      postForm(f).then(function (d) {
        flash(d.flash, d.flashTipo);
        aplicarResumo(d);
        if (d.ok && item) {
          item.classList.add('is-saindo');
          setTimeout(function () { item.remove(); if (!conteudo.querySelector('.news-item')) { refresh(); } }, 260);
        }
      }).catch(function () { flash('Falha ao republicar. Tente de novo.', 'erro'); });
    } else if (f.classList.contains('err-form-descartar')) {
      e.preventDefault();
      if (!confirm('Descartar este post com erro? Ele não será publicado.')) { return; }
      var item2 = f.closest('.news-item');
      postForm(f).then(function (d) {
        flash(d.flash, d.flashTipo);
        aplicarResumo(d);
        if (d.ok && item2) {
          item2.classList.add('is-saindo');
          setTimeout(function () { item2.remove(); if (!conteudo.querySelector('.news-item')) { refresh(); } }, 260);
        }
      }).catch(function () { flash('Falha ao descartar.', 'erro'); });
    } else if (f.id === 'errRepublicarTodos') {
      e.preventDefault();
      if (!confirm('Republicar TODOS os posts com erro? Eles voltam pra fila e saem espaçados.')) { return; }
      postForm(f).then(function (d) { flash(d.flash, d.flashTipo); aplicarResumo(d); refresh(); })
        .catch(function () { flash('Falha ao republicar todos.', 'erro'); });
    } else if (f.id === 'errDescartarTodos') {
      e.preventDefault();
      if (!confirm('Descartar TODOS os posts com erro?')) { return; }
      postForm(f).then(function (d) { flash(d.flash, d.flashTipo); aplicarResumo(d); refresh(); })
        .catch(function () { flash('Falha ao descartar todos.', 'erro'); });
    }
  });

  document.addEventListener('click', function (e) {
    var trg = e.target.closest('.err-prev');
    if (trg) { var item = trg.closest('.news-item'); if (item) { abrir(item); } return; }
    if (e.target.closest('[data-close]')) { fechar(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fechar(); } });

  function refresh() {
    if (modalAberto) { return; }
    fetch('erros.php?ajax=lista', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        aplicarResumo(d);
        if (typeof d.conteudo === 'string') { conteudo.innerHTML = d.conteudo; }
      }).catch(function () {});
  }
  setInterval(refresh, 15000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });
})();
</script>
<?php require __DIR__ . '/partials/foot.php'; ?>
