<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require __DIR__ . '/init.php';
exigir_login();

require_once __DIR__ . '/lib_publicador.php';
pub_marcar_atrasados_como_erro(db());

$flash = '';
$flashTipo = 'ok';

/* É uma requisição AJAX? (fetch manda este cabeçalho ou o campo ajax=1) */
$ehAjax = (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest')
    || !empty($_POST['ajax']) || !empty($_GET['ajax']);

/* Apaga arquivos de mídia de uma publicação (disco). */
function ag_apagar_arquivos(PDO $db, int $pubId): void
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

/* Próximos posts automáticos (futuros e em processamento). */
function ag_buscar_itens(PDO $db): array
{
    return $db->query('SELECT p.id, p.tipo, p.legenda, p.fonte_nome, p.agendado_para, p.status,
            (SELECT m.arquivo FROM ' . DB_PREFIX . 'publicacao_midia m WHERE m.publicacao_id=p.id ORDER BY m.posicao, m.id LIMIT 1) AS capa,
            (SELECT m.tipo    FROM ' . DB_PREFIX . 'publicacao_midia m WHERE m.publicacao_id=p.id ORDER BY m.posicao, m.id LIMIT 1) AS capa_tipo,
            c.nome AS cliente
         FROM ' . DB_PREFIX . 'publicacoes p
         JOIN ' . DB_PREFIX . 'clientes c ON c.id = p.cliente_id
         WHERE p.origem="ia" AND p.status IN ("agendado","processando")
         ORDER BY p.agendado_para ASC
         LIMIT 200')->fetchAll();
}

/* Contagem REAL da fila (sem o teto de LIMIT 200 da lista). */
function ag_na_fila(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes
        WHERE origem="ia" AND status IN ("agendado","processando")')->fetchColumn();
}

/* Publicados hoje pela IA. */
function ag_pub_hoje(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes
        WHERE origem="ia" AND status="publicado" AND publicado_em >= ' . $db->quote(date('Y-m-d 00:00:00')))->fetchColumn();
}

/* HTML de UM card da fila (mesmo markup no load e no refresh AJAX). */
function ag_item_html(array $p): string
{
    $quando    = strtotime((string) $p['agendado_para']);
    $atrasado  = $quando <= time();
    $prev      = $p['capa'] ? midia_preview((string) $p['capa']) : '';
    $posterUrl = ($prev && midia_eh_imagem($prev)) ? asset_v($prev) : '';
    $mediaUrl  = $p['capa'] ? asset_v((string) $p['capa']) : '';
    $isVideo   = $p['capa_tipo'] === 'video';
    $infoLine  = strtoupper((string) $p['tipo']) . ' · ' . ($atrasado ? 'a publicar' : date('d/m H:i', $quando)) . ' · ' . (string) $p['cliente'] . ' · ' . ($p['fonte_nome'] ?: '—');
    $proc      = $p['status'] === 'processando';

    ob_start(); ?>
    <div class="news-item" data-id="<?= (int) $p['id'] ?>"
         data-poster="<?= e($posterUrl) ?>"
         data-media="<?= e($mediaUrl) ?>"
         data-tipo="<?= $isVideo ? 'video' : 'image' ?>"
         data-legenda="<?= e((string) ($p['legenda'] ?: '')) ?>"
         data-info="<?= e($infoLine) ?>">
      <div class="news-item__thumb ag-prev" title="Pré-visualizar" style="cursor:zoom-in">
        <?php if ($posterUrl !== ''): ?>
          <img src="<?= e($posterUrl) ?>" alt="" loading="lazy">
          <?php if ($isVideo): ?><span class="thumb-vid"><i class="fa-solid fa-play"></i></span><?php endif; ?>
        <?php else: ?>
          <span class="news-item__noimg"><i class="fa-solid fa-image"></i></span>
        <?php endif; ?>
      </div>
      <div class="news-item__body">
        <div class="news-item__meta">
          <span class="tag tag--<?= $p['tipo'] === 'story' ? 'nova' : 'ok' ?>"><?= strtoupper(e((string) $p['tipo'])) ?></span>
          <?php if ($proc): ?><span class="tag">publicando…</span><?php endif; ?>
          <span class="news-item__fonte"><i class="fa-solid fa-building-columns"></i> <?= e($p['fonte_nome'] ?: '—') ?></span>
          <span class="news-item__time">
            <i class="fa-regular fa-clock"></i>
            <?= $atrasado ? 'a publicar' : date('d/m H:i', $quando) ?> · <?= e($p['cliente']) ?>
          </span>
        </div>
        <h3 class="news-item__titulo"><?= e(mb_strimwidth((string) ($p['legenda'] ?: 'Story sem legenda'), 0, 120, '…')) ?></h3>
        <div class="news-item__acoes">
          <button type="button" class="btn-inline ag-prev"><i class="fa-solid fa-eye"></i> Pré-visualizar</button>
          <a class="btn-ghost" href="publicacao_form.php?id=<?= (int) $p['id'] ?>"><i class="fa-solid fa-pen"></i> Editar</a>
          <?php if ($mediaUrl !== ''): ?>
            <a class="btn-ghost" href="<?= e($mediaUrl) ?>" download="post-<?= (int) $p['id'] ?>" target="_blank"><i class="fa-solid fa-download"></i> Baixar</a>
          <?php endif; ?>
          <form method="post" class="ag-form-postar-agora" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="acao" value="postar_agora">
            <button type="submit" class="btn-ghost" <?= $proc ? 'disabled' : '' ?> style="color: var(--cor-ok, #16a34a); border-color: rgba(22, 163, 74, 0.3);">
              <i class="fa-solid fa-share-from-square"></i> Postar agora
            </button>
          </form>
          <form method="post" class="ag-form-recusar" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <input type="hidden" name="acao" value="recusar">
            <button type="submit" class="btn-ghost" <?= $proc ? 'disabled' : '' ?>>
              <i class="fa-solid fa-xmark"></i> Recusar
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/* HTML do conteúdo (lista de cards OU estado vazio). */
function ag_conteudo_html(array $itens): string
{
    if (!$itens) {
        return '<div class="empty">'
            . '<div class="empty__mark"><i class="fa-solid fa-clock"></i></div>'
            . '<h2>Fila vazia</h2>'
            . '<p>Quando a automação estiver ligada e houver notícias, os próximos posts aparecem aqui para revisão.</p>'
            . '<a class="btn-inline" href="config_ia.php"><i class="fa-solid fa-robot"></i> Configurar automação</a>'
            . '</div>';
    }
    $out = '<div class="news-list">';
    foreach ($itens as $p) {
        $out .= ag_item_html($p);
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

    if ($acao === 'recusar' && $id > 0) {
        $st = db()->prepare('SELECT status FROM ' . DB_PREFIX . 'publicacoes WHERE id=? AND origem="ia" LIMIT 1');
        $st->execute([$id]);
        $stt = (string) ($st->fetchColumn() ?: '');
        if (in_array($stt, ['agendado', 'aguardando_aprovacao', 'revisao_arte', 'erro'], true)) {
            ag_apagar_arquivos(db(), $id);
            db()->prepare('UPDATE ' . DB_PREFIX . 'publicacoes SET status="cancelado", erro_msg="Recusado pelo usuário" WHERE id=?')->execute([$id]);
            $flash = 'Post recusado e removido da fila.';
        } else {
            $flash = 'Esse post não pode mais ser recusado (já está em publicação ou publicado).';
            $flashTipo = 'erro';
        }
    } elseif ($acao === 'postar_agora' && $id > 0) {
        $st = db()->prepare('SELECT * FROM ' . DB_PREFIX . 'publicacoes WHERE id=? AND origem="ia" LIMIT 1');
        $st->execute([$id]);
        $p = $st->fetch();
        if ($p) {
            $stt = (string) $p['status'];
            if (in_array($stt, ['agendado', 'processando', 'erro'], true)) {
                require_once __DIR__ . '/lib_publicador.php';
                try {
                    $res = processar_publicacao(db(), $p, true);
                    if ($res['estado'] === 'publicado') {
                        $flash = 'Post publicado com sucesso no Instagram!';
                    } elseif ($res['estado'] === 'pendente') {
                        $flash = 'Publicação enviada/em andamento no Instagram: ' . ($res['msg'] ?? '');
                    } else {
                        $flash = 'Erro ao publicar no Instagram: ' . ($res['msg'] ?? 'erro desconhecido.');
                        $flashTipo = 'erro';
                    }
                } catch (Throwable $ex) {
                    pub_marcar_erro(db(), $id, 'Excecao manual: ' . $ex->getMessage());
                    $flash = 'Exceção ao publicar: ' . $ex->getMessage();
                    $flashTipo = 'erro';
                }
            } else {
                $flash = 'Esse post não está em estado publicável (já publicado, cancelado ou reprovado).';
                $flashTipo = 'erro';
            }
        } else {
            $flash = 'Publicação não encontrada.';
            $flashTipo = 'erro';
        }
    } elseif ($acao === 'recusar_todos') {
        $rows = db()->query('SELECT id FROM ' . DB_PREFIX . 'publicacoes
            WHERE origem="ia" AND status="agendado" AND agendado_para > NOW()')->fetchAll();
        foreach ($rows as $r) {
            ag_apagar_arquivos(db(), (int) $r['id']);
        }
        $n = db()->exec('UPDATE ' . DB_PREFIX . 'publicacoes
            SET status="cancelado", erro_msg="Recusado em massa" WHERE origem="ia" AND status="agendado" AND agendado_para > NOW()');
        $flash = 'Fila limpa: ' . (int) $n . ' post(s) recusado(s).';
    }

    if ($ehAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'        => $flashTipo === 'ok',
            'flash'     => $flash,
            'flashTipo' => $flashTipo,
            'naFila'    => ag_na_fila(db()),
            'pubHoje'   => ag_pub_hoje(db()),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['flash'] = [$flash, $flashTipo];
    header('Location: ' . BASE_URL . '/agendados.php');
    exit;
}

/* ===== Refresh AJAX da lista (GET ?ajax=lista) ===== */
if (($_GET['ajax'] ?? '') === 'lista') {
    $itens = ag_buscar_itens(db());
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'naFila'   => ag_na_fila(db()),
        'pubHoje'  => ag_pub_hoje(db()),
        'count'    => count($itens),
        'conteudo' => ag_conteudo_html($itens),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($_SESSION['flash'])) {
    [$flash, $flashTipo] = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$itens   = ag_buscar_itens(db());
$naFila  = ag_na_fila(db());
$pubHoje = ag_pub_hoje(db());

$page_title = 'Agendados automáticos';
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <div class="page-head">
    <div>
      <h1>Agendados automáticos</h1>
      <p>O que a automação vai postar. Recuse o que não quiser <b>antes</b> do horário.</p>
    </div>
    <form method="post" id="agRecusarTodos" style="margin:0;<?= $itens ? '' : 'display:none' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="acao" value="recusar_todos">
      <button type="submit" class="btn-ghost"><i class="fa-solid fa-trash-can"></i> Recusar todos</button>
    </form>
  </div>

  <div class="aviso aviso--ok" id="agFlash" style="display:<?= $flash ? 'block' : 'none' ?>"><?= e($flash) ?></div>

  <div class="filtros">
    <span class="filtros__info" id="agResumo"><?= $naFila ?> na fila · <?= $pubHoje ?> publicado(s) hoje</span>
    <span class="filtros__live" id="agLive" title="Atualiza sozinho"><i class="fa-solid fa-circle"></i> ao vivo</span>
  </div>

  <div id="agConteudo"><?= ag_conteudo_html($itens) ?></div>
</main>

<!-- Popup de pré-visualização -->
<div class="ag-modal" id="agModal" aria-hidden="true">
  <div class="ag-modal__back" data-close></div>
  <div class="ag-modal__card" role="dialog" aria-modal="true">
    <button class="ag-modal__x" data-close aria-label="Fechar"><i class="fa-solid fa-xmark"></i></button>
    <div class="ag-modal__media" id="agModalMedia"></div>
    <div class="ag-modal__side">
      <div class="ag-modal__info" id="agModalInfo"></div>
      <div class="ag-modal__cap" id="agModalCap"></div>
      <div class="ag-modal__actions" style="margin-top:auto; display:flex; gap:10px; flex-wrap:wrap; padding-top:15px; border-top:1px solid var(--cor-borda, #eee);">
        <a id="agModalDownload" class="btn btn-inline" href="#" download style="text-decoration:none;"><i class="fa-solid fa-download"></i> Baixar Postagem</a>
        <form id="agModalFormPostar" method="post" style="margin:0;">
          <?= csrf_field() ?>
          <input type="hidden" name="id" id="agModalPostId" value="">
          <input type="hidden" name="acao" value="postar_agora">
          <button type="submit" class="btn btn-inline" style="background-color: var(--cor-ok, #16a34a); color:#fff; border:none;">
            <i class="fa-solid fa-share-from-square"></i> Postar agora
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<style>
.filtros { display: flex; align-items: center; gap: 12px; }
.filtros__live { font-size: 12px; font-weight: 700; color: #16a34a; display: inline-flex; align-items: center; gap: 5px; }
.filtros__live i { font-size: 8px; animation: agpulse 1.6s ease-in-out infinite; }
@keyframes agpulse { 0%,100% { opacity: .35 } 50% { opacity: 1 } }
.news-item.is-saindo { opacity: 0; transform: translateX(12px); transition: opacity .25s, transform .25s; }
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
  var modal = document.getElementById('agModal'),
      mMedia = document.getElementById('agModalMedia'),
      mInfo  = document.getElementById('agModalInfo'),
      mCap   = document.getElementById('agModalCap'),
      conteudo = document.getElementById('agConteudo'),
      resumo   = document.getElementById('agResumo'),
      flashBox = document.getElementById('agFlash'),
      btnTodos = document.getElementById('agRecusarTodos');
  var modalAberto = false;

  /* ---------- Preview ---------- */
  function abrir(item) {
    var id     = item.getAttribute('data-id'),
        poster = item.getAttribute('data-poster'),
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

    var btnDownload = document.getElementById('agModalDownload');
    var inputPostId = document.getElementById('agModalPostId');
    var btnPostarModal = document.querySelector('#agModalFormPostar button');

    if (inputPostId) { inputPostId.value = id; }
    if (btnDownload) {
      if (media) {
        btnDownload.href = media;
        btnDownload.style.display = 'inline-flex';
        btnDownload.setAttribute('download', 'post-' + id);
      } else {
        btnDownload.style.display = 'none';
      }
    }
    if (btnPostarModal) {
      btnPostarModal.disabled = false;
      btnPostarModal.innerHTML = '<i class="fa-solid fa-share-from-square"></i> Postar agora';
    }

    modal.classList.add('is-open'); modal.setAttribute('aria-hidden', 'false'); modalAberto = true;
  }
  function fechar() {
    modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); modalAberto = false;
    mMedia.innerHTML = '';
  }

  /* ---------- Flash ---------- */
  function flash(msg, tipo) {
    if (!msg) { return; }
    flashBox.textContent = msg;
    flashBox.className = 'aviso aviso--' + (tipo || 'ok');
    flashBox.style.display = 'block';
    clearTimeout(flash._t);
    flash._t = setTimeout(function () { flashBox.style.display = 'none'; }, 4000);
  }
  function aplicarResumo(d) {
    if (typeof d.naFila !== 'undefined') {
      resumo.textContent = d.naFila + ' na fila · ' + d.pubHoje + ' publicado(s) hoje';
      btnTodos.style.display = d.naFila > 0 ? '' : 'none';
    }
  }

  /* ---------- Ações via AJAX ---------- */
  function postForm(form, extra) {
    var fd = new FormData(form);
    if (extra) { fd.append(extra[0], extra[1]); }
    fd.append('ajax', '1');
    return fetch('agendados.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); });
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.classList.contains('ag-form-recusar')) {
      e.preventDefault();
      if (!confirm('Recusar este post? Ele não será publicado.')) { return; }
      var item = f.closest('.news-item');
      postForm(f).then(function (d) {
        flash(d.flash, d.flashTipo);
        aplicarResumo(d);
        if (d.ok && item) {
          item.classList.add('is-saindo');
          setTimeout(function () {
            item.remove();
            if (!conteudo.querySelector('.news-item')) { refresh(); }
          }, 260);
        }
      }).catch(function () { flash('Falha ao recusar. Tente de novo.', 'erro'); });
    } else if (f.id === 'agModalFormPostar') {
      e.preventDefault();
      if (!confirm('Postar agora no Instagram?')) { return; }
      var id = document.getElementById('agModalPostId').value;
      var item = document.querySelector('.news-item[data-id="' + id + '"]');
      var btn = f.querySelector('button');
      if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Postando...'; }
      postForm(f).then(function (d) {
        flash(d.flash, d.flashTipo);
        aplicarResumo(d);
        if (d.ok) {
          if (item) {
            item.classList.add('is-saindo');
            setTimeout(function () {
              item.remove();
              if (!conteudo.querySelector('.news-item')) { refresh(); }
            }, 260);
          }
          fechar();
        } else {
          if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-share-from-square"></i> Postar agora'; }
        }
      }).catch(function () {
        flash('Falha ao tentar publicar. Verifique os logs.', 'erro');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-share-from-square"></i> Postar agora'; }
      });
    } else if (f.classList.contains('ag-form-postar-agora')) {
      e.preventDefault();
      if (!confirm('Postar agora no Instagram?')) { return; }
      var item = f.closest('.news-item');
      var btn = f.querySelector('button');
      if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Postando...'; }
      postForm(f).then(function (d) {
        flash(d.flash, d.flashTipo);
        aplicarResumo(d);
        if (d.ok) {
          if (item) {
            item.classList.add('is-saindo');
            setTimeout(function () {
              item.remove();
              if (!conteudo.querySelector('.news-item')) { refresh(); }
            }, 260);
          }
        } else {
          if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-share-from-square"></i> Postar agora'; }
        }
      }).catch(function () {
        flash('Falha ao tentar publicar. Verifique os logs.', 'erro');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-share-from-square"></i> Postar agora'; }
      });
    } else if (f.id === 'agRecusarTodos') {
      e.preventDefault();
      if (!confirm('Recusar TODOS os posts ainda não publicados da fila?')) { return; }
      postForm(f).then(function (d) {
        flash(d.flash, d.flashTipo);
        aplicarResumo(d);
        refresh();
      }).catch(function () { flash('Falha ao limpar a fila.', 'erro'); });
    }
  });

  document.addEventListener('click', function (e) {
    var trg = e.target.closest('.ag-prev');
    if (trg) { var item = trg.closest('.news-item'); if (item) { abrir(item); } return; }
    if (e.target.closest('[data-close]')) { fechar(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { fechar(); } });

  /* ---------- Atualização ao vivo ---------- */
  function refresh() {
    if (modalAberto) { return; } // não recarrega a lista com o preview aberto
    fetch('agendados.php?ajax=lista', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        aplicarResumo(d);
        if (typeof d.conteudo === 'string') { conteudo.innerHTML = d.conteudo; }
      }).catch(function () {});
  }
  setInterval(refresh, 12000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });
})();
</script>
<?php require __DIR__ . '/partials/foot.php'; ?>
