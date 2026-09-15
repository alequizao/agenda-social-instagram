<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require __DIR__ . '/init.php';
exigir_login();

$idPost = (int) ($_GET['id'] ?? 0);
$editando = false;
$post = ['id' => 0, 'cliente_id' => 0, 'tipo' => 'feed', 'legenda' => '', 'agendado_para' => ''];
$midiasExistentes = [];

if ($idPost > 0) {
    $st = db()->prepare('SELECT * FROM publicacoes WHERE id = ? LIMIT 1');
    $st->execute([$idPost]);
    $row = $st->fetch();
    if (!$row) {
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
    $post = $row;
    $editando = true;
    $clienteId = (int) $row['cliente_id'];

    $mm = db()->prepare('SELECT * FROM publicacao_midia WHERE publicacao_id = ? ORDER BY posicao, id');
    $mm->execute([$idPost]);
    $midiasExistentes = $mm->fetchAll();
} else {
    $clienteId = (int) ($_GET['cliente_id'] ?? 0);
}

$cs = db()->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
$cs->execute([$clienteId]);
$c = $cs->fetch();
if (!$c) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

// tipo de botao a partir do tipo salvo (carrossel volta para feed na UI)
$tipoUI = $post['tipo'] === 'carrossel' ? 'feed' : $post['tipo'];

// valor inicial do datetime-local
if ($editando && $post['agendado_para']) {
    $valData = str_replace(' ', 'T', substr($post['agendado_para'], 0, 16));
} else {
    $dataParam = (string) ($_GET['data'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataParam)) {
        $valData = $dataParam . 'T09:00';
    } else {
        $valData = (new DateTime('+1 hour'))->format('Y-m-d\TH:00');
    }
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $erro = 'Sessao expirada. Recarregue a pagina e tente de novo.';
    } else {
        $tipo = (string) ($_POST['tipo'] ?? 'feed');
        if (!in_array($tipo, ['feed', 'story', 'reel'], true)) {
            $tipo = 'feed';
        }
        $legenda = trim((string) ($_POST['legenda'] ?? ''));
        $valData = (string) ($_POST['agendado_para'] ?? $valData);

        $dtObj = DateTime::createFromFormat('Y-m-d\TH:i', $valData);
        $agendado = $dtObj ? $dtObj->format('Y-m-d H:i:s') : '';

        // normaliza arquivos enviados
        $arquivos = [];
        $f = $_FILES['midia'] ?? null;
        if ($f && isset($f['name'])) {
            if (is_array($f['name'])) {
                for ($i = 0, $n = count($f['name']); $i < $n; $i++) {
                    if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                        continue;
                    }
                    $arquivos[] = [
                        'name' => $f['name'][$i], 'type' => $f['type'][$i],
                        'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i],
                    ];
                }
            } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $arquivos[] = $f;
            }
        }

        $temExistente = $editando && count($midiasExistentes) > 0;

        // validacoes
        if ($agendado === '') {
            $erro = 'Escolha a data e a hora do agendamento.';
        } elseif (!$arquivos && !$temExistente) {
            $erro = 'Suba pelo menos uma midia.';
        } elseif ($tipo === 'story' && count($arquivos) > 1) {
            $erro = 'Story aceita apenas uma midia.';
        } elseif ($tipo === 'reel' && count($arquivos) > 1) {
            $erro = 'Reels aceita apenas um video.';
        } elseif ($tipo === 'feed' && count($arquivos) > 10) {
            $erro = 'Feed aceita no maximo 10 midias no carrossel.';
        } else {
            // processa uploads
            $novas = [];
            foreach ($arquivos as $arq) {
                $m = salvar_midia_post($arq, $clienteId);
                if ($m === null) {
                    $erro = 'Alguma midia nao foi aceita. Use JPG, PNG, WEBP, MP4 ou MOV.';
                    break;
                }
                $novas[] = $m;
            }

            // reels precisa ser video
            if ($erro === '' && $tipo === 'reel') {
                $fonte = $novas ?: array_map(fn($m) => ['tipo' => $m['tipo']], $midiasExistentes);
                if (!$fonte || $fonte[0]['tipo'] !== 'video') {
                    $erro = 'Reels precisa ser um video.';
                }
            }

            // capa do reels (imagem, opcional)
            $capaArquivo = $editando ? ($post['capa_arquivo'] ?? null) : null;
            $capaNova = null;
            if ($erro === '' && $tipo === 'reel' && !empty($_FILES['capa']['name'])) {
                $cm = salvar_midia_post($_FILES['capa'], $clienteId);
                if ($cm === null || $cm['tipo'] !== 'imagem') {
                    if ($cm !== null && is_file(__DIR__ . '/' . $cm['arquivo'])) {
                        @unlink(__DIR__ . '/' . $cm['arquivo']);
                    }
                    $erro = 'A capa do Reels precisa ser uma imagem (JPG, PNG ou WEBP).';
                } else {
                    $capaNova = $cm['arquivo'];
                }
            }

            // se deu erro depois de mover arquivos, remove os orfaos
            if ($erro !== '' && ($novas || $capaNova)) {
                foreach ($novas as $m) {
                    if (is_file(__DIR__ . '/' . $m['arquivo'])) {
                        @unlink(__DIR__ . '/' . $m['arquivo']);
                    }
                }
                if ($capaNova && is_file(__DIR__ . '/' . $capaNova)) {
                    @unlink(__DIR__ . '/' . $capaNova);
                }
                $novas = [];
                $capaNova = null;
            }

            // define a capa final (so vale para reels)
            if ($tipo === 'reel') {
                $capaFinal = $capaNova ?: $capaArquivo;
            } else {
                $capaFinal = null;
            }
            // se trocou a capa ou saiu do reels, apaga a antiga do disco
            if (($capaNova || $tipo !== 'reel') && !empty($post['capa_arquivo'])
                && $post['capa_arquivo'] !== $capaFinal
                && is_file(__DIR__ . '/' . $post['capa_arquivo'])) {
                @unlink(__DIR__ . '/' . $post['capa_arquivo']);
            }

            if ($erro === '') {
                // define tipo final
                $qtd = $novas ? count($novas) : count($midiasExistentes);
                $tipoFinal = $tipo;
                if ($tipo === 'feed' && $qtd > 1) {
                    $tipoFinal = 'carrossel';
                }

                if ($editando) {
                    $up = db()->prepare('UPDATE publicacoes SET tipo=?, legenda=?, capa_arquivo=?, agendado_para=?, status="agendado" WHERE id=?');
                    $up->execute([$tipoFinal, $legenda ?: null, $capaFinal, $agendado, $idPost]);

                    if ($novas) {
                        // remove midias antigas (registros e arquivos)
                        foreach ($midiasExistentes as $old) {
                            if (!empty($old['arquivo']) && is_file(__DIR__ . '/' . $old['arquivo'])) {
                                @unlink(__DIR__ . '/' . $old['arquivo']);
                            }
                        }
                        db()->prepare('DELETE FROM publicacao_midia WHERE publicacao_id=?')->execute([$idPost]);
                        $pos = 0;
                        $insM = db()->prepare('INSERT INTO publicacao_midia (publicacao_id, posicao, tipo, arquivo, url_publica) VALUES (?,?,?,?,?)');
                        foreach ($novas as $m) {
                            $insM->execute([$idPost, $pos++, $m['tipo'], $m['arquivo'], BASE_URL . '/' . $m['arquivo']]);
                        }
                    }
                    $pubId = $idPost;
                } else {
                    $ins = db()->prepare('INSERT INTO publicacoes (cliente_id, tipo, legenda, capa_arquivo, agendado_para, status, criado_por) VALUES (?,?,?,?,?,"agendado",?)');
                    $ins->execute([$clienteId, $tipoFinal, $legenda ?: null, $capaFinal, $agendado, usuario_logado()['id']]);
                    $pubId = (int) db()->lastInsertId();

                    $pos = 0;
                    $insM = db()->prepare('INSERT INTO publicacao_midia (publicacao_id, posicao, tipo, arquivo, url_publica) VALUES (?,?,?,?,?)');
                    foreach ($novas as $m) {
                        $insM->execute([$pubId, $pos++, $m['tipo'], $m['arquivo'], BASE_URL . '/' . $m['arquivo']]);
                    }
                }

                $mes = substr($agendado, 0, 7);
                header('Location: ' . BASE_URL . '/cliente.php?id=' . $clienteId . '&mes=' . $mes);
                exit;
            }
        }

        // mantem o que foi digitado em caso de erro
        $tipoUI = $tipo;
        $post['legenda'] = $legenda;
    }
}

// midias existentes para o preview (JSON)
$midiasJson = [];
foreach ($midiasExistentes as $m) {
    $midiasJson[] = ['url' => BASE_URL . '/' . $m['arquivo'], 'tipo' => $m['tipo']];
}

$handle = $c['ig_username'] ? '@' . $c['ig_username'] : '@seucliente';

$page_title = $editando ? 'Editar publicacao' : 'Nova publicacao';
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <a class="back" href="cliente.php?id=<?= (int) $clienteId ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
    Voltar para <?= e($c['nome']) ?>
  </a>

  <div class="page-head">
    <div>
      <h1><?= $editando ? 'Editar publicacao' : 'Nova publicacao' ?></h1>
      <p>Escolha o tipo, suba a midia e veja o preview antes de agendar.</p>
    </div>
  </div>

  <?php if ($erro !== ''): ?>
    <div class="alert" role="alert" style="max-width:560px;"><?= e($erro) ?></div>
  <?php endif; ?>

  <div class="compose">
    <form class="compose__form" method="post" enctype="multipart/form-data"
          action="publicacao_form.php<?= $editando ? '?id=' . (int) $idPost : '?cliente_id=' . (int) $clienteId ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="tipo" id="tipo" value="<?= e($tipoUI) ?>">

      <div class="field">
        <label>Tipo de publicacao</label>
        <div class="type-pick" id="typePick">
          <button type="button" class="type-btn" data-tipo="feed">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="4"/></svg>
            Feed
          </button>
          <button type="button" class="type-btn" data-tipo="story">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="6" y="3" width="12" height="18" rx="4"/><circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/></svg>
            Story
          </button>
          <button type="button" class="type-btn" data-tipo="reel">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="m10 9 5 3-5 3z" fill="currentColor" stroke="none"/></svg>
            Reels
          </button>
        </div>
      </div>

      <div class="field">
        <label for="midia"><span id="midiaLabel">Midias</span></label>
        <input type="file" id="midia" name="midia[]" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" multiple>
        <p class="hint" id="midiaHint"></p>
      </div>

      <div class="field" id="capaWrap">
        <label for="capa">Capa do Reels (opcional)</label>
        <div class="capa-row">
          <div class="capa-preview" id="capaPreview"><span>Sem capa</span></div>
          <div style="flex:1;">
            <input type="file" id="capa" name="capa" accept="image/jpeg,image/png,image/webp">
            <p class="hint">Imagem que aparece como miniatura na grade do perfil. Se nao escolher, o Instagram usa um quadro do video.</p>
          </div>
        </div>
      </div>

      <div class="field" id="legendaWrap">
        <label for="legenda">Legenda</label>
        <textarea id="legenda" name="legenda" rows="4" placeholder="Escreva a legenda da publicacao"><?= e($post['legenda'] ?? '') ?></textarea>
      </div>

      <div class="field">
        <label for="agendado_para">Data e hora</label>
        <input type="datetime-local" id="agendado_para" name="agendado_para" value="<?= e($valData) ?>" required>
      </div>

      <?php if ($editando): ?>
        <input type="hidden" name="id" value="<?= (int) $idPost ?>">
      <?php endif; ?>
      <div class="form-actions">
        <button type="submit" class="btn btn--auto"><?= $editando ? 'Salvar' : 'Agendar publicacao' ?></button>
        <a class="btn-ghost" href="cliente.php?id=<?= (int) $clienteId ?>">Cancelar</a>
        <?php if ($editando): ?>
          <button type="submit" class="btn-ghost btn-ghost--del" style="margin-left:auto;"
                  formaction="publicacao_excluir.php" formmethod="post" formnovalidate
                  onclick="return confirm('Excluir esta publicacao?');">Excluir</button>
        <?php endif; ?>
      </div>
    </form>

    <div class="compose__preview">
      <div class="preview-phone" id="preview" data-tipo="<?= e($tipoUI) ?>">

        <!-- FEED -->
        <div class="ig ig--feed">
          <div class="ig__top">
            <?= avatar_cliente($c, 'ig__avatar') ?>
            <span class="ig__handle"><?= e($handle) ?></span>
            <span class="ig__more">&#8943;</span>
          </div>
          <div class="ig__media" data-slot="feed">
            <div class="ig__empty">Suba uma imagem ou video</div>
          </div>
          <div class="ig__bar">
            <span class="ig__ic">&#9825;</span><span class="ig__ic">&#9711;</span><span class="ig__ic">&#10148;</span>
            <span class="ig__ic ig__ic--right">&#9634;</span>
          </div>
          <div class="ig__caption"><b><?= e($handle) ?></b> <span data-slot="cap-feed"></span></div>
        </div>

        <!-- STORY -->
        <div class="ig ig--story">
          <div class="ig__story-bar"><span></span></div>
          <div class="ig__story-top">
            <?= avatar_cliente($c, 'ig__avatar ig__avatar--ring') ?>
            <span class="ig__handle"><?= e($handle) ?></span>
            <span class="ig__story-time">agora</span>
          </div>
          <div class="ig__story-media" data-slot="story">
            <div class="ig__empty">Suba uma imagem ou video 9:16</div>
          </div>
        </div>

        <!-- REELS -->
        <div class="ig ig--reels">
          <div class="ig__reels-media" data-slot="reel">
            <div class="ig__empty">Suba um video 9:16</div>
          </div>
          <div class="ig__reels-side">
            <span class="ig__ic">&#9825;</span><span class="ig__ic">&#9711;</span><span class="ig__ic">&#10148;</span><span class="ig__ic">&#8943;</span>
          </div>
          <div class="ig__reels-bottom">
            <div><b><?= e($handle) ?></b></div>
            <div class="ig__reels-cap" data-slot="cap-reel"></div>
            <div class="ig__reels-audio">&#9834; audio original</div>
          </div>
        </div>

      </div>
      <p class="preview-tag">Preview aproximado de como vai aparecer</p>
    </div>
  </div>
</main>

<script>
  window.PUB_EXISTENTES = <?= json_encode($midiasJson, JSON_UNESCAPED_SLASHES) ?>;
  window.PUB_TIPO_INICIAL = <?= json_encode($tipoUI) ?>;
  window.PUB_CAPA = <?= json_encode(!empty($post['capa_arquivo']) ? BASE_URL . '/' . $post['capa_arquivo'] : '', JSON_UNESCAPED_SLASHES) ?>;
</script>
<script>
(function () {
  'use strict';

  var tipoInput = document.getElementById('tipo');
  var fileInput = document.getElementById('midia');
  var legenda   = document.getElementById('legenda');
  var preview   = document.getElementById('preview');
  var typePick  = document.getElementById('typePick');
  var midiaHint = document.getElementById('midiaHint');
  var midiaLabel = document.getElementById('midiaLabel');
  var legendaWrap = document.getElementById('legendaWrap');
  var capaInput = document.getElementById('capa');
  var capaWrap = document.getElementById('capaWrap');
  var capaPreview = document.getElementById('capaPreview');

  if (!preview) { return; }

  var ICON_MUDO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>';
  var ICON_SOM = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a9 9 0 0 1 0 14"/></svg>';

  var objectUrls = [];
  var midiaAtual = [];   // [{url, tipo}]
  var capaUrl = '';
  var capaObjUrl = null;

  var REGRAS = {
    feed:  { multiple: true,  accept: 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime', label: 'Midias (1 ou mais para carrossel)', hint: 'Imagem ou video. Mais de uma vira carrossel (ate 10).', legenda: true },
    story: { multiple: false, accept: 'image/jpeg,image/png,image/webp,video/mp4,video/quicktime', label: 'Midia do story', hint: 'Uma imagem ou video, formato vertical 9:16.', legenda: false },
    reel:  { multiple: false, accept: 'video/mp4,video/quicktime', label: 'Video do reels', hint: 'Apenas video, vertical 9:16.', legenda: true }
  };

  function limparUrls() {
    objectUrls.forEach(function (u) { URL.revokeObjectURL(u); });
    objectUrls = [];
  }

  function ehVideoTipo(t) { return t === 'video'; }

  function elementoMidia(item, contain) {
    var fit = contain ? 'contain' : 'cover';

    if (!ehVideoTipo(item.tipo)) {
      var img = document.createElement('img');
      img.src = item.url;
      img.style.cssText = 'width:100%;height:100%;object-fit:' + fit + ';display:block;';
      return img;
    }

    var wrap = document.createElement('div');
    wrap.style.cssText = 'position:relative;width:100%;height:100%;';

    var v = document.createElement('video');
    v.src = item.url;
    v.muted = true; v.loop = true; v.autoplay = true; v.playsInline = true;
    v.setAttribute('playsinline', '');
    v.style.cssText = 'width:100%;height:100%;object-fit:' + fit + ';display:block;';
    wrap.appendChild(v);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ig__mute';
    btn.innerHTML = ICON_MUDO;
    btn.setAttribute('aria-label', 'Ativar som');
    btn.addEventListener('click', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      v.muted = !v.muted;
      if (!v.muted) {
        var pr = v.play();
        if (pr && pr.catch) { pr.catch(function () {}); }
      }
      btn.innerHTML = v.muted ? ICON_MUDO : ICON_SOM;
      btn.setAttribute('aria-label', v.muted ? 'Ativar som' : 'Mutar');
    });
    wrap.appendChild(btn);

    return wrap;
  }

  function slot(nome) { return preview.querySelector('[data-slot="' + nome + '"]'); }

  function vazio(texto) {
    var d = document.createElement('div');
    d.className = 'ig__empty';
    d.textContent = texto;
    return d;
  }

  function renderFeed() {
    var alvo = slot('feed');
    alvo.innerHTML = '';
    if (!midiaAtual.length) { alvo.appendChild(vazio('Suba uma imagem ou video')); return; }
    alvo.appendChild(elementoMidia(midiaAtual[0], false));
    if (midiaAtual.length > 1) {
      var badge = document.createElement('span');
      badge.className = 'ig__count';
      badge.textContent = '1/' + midiaAtual.length;
      alvo.appendChild(badge);
      var dots = document.createElement('div');
      dots.className = 'ig__dots';
      for (var i = 0; i < midiaAtual.length; i++) {
        var dd = document.createElement('span');
        if (i === 0) { dd.className = 'on'; }
        dots.appendChild(dd);
      }
      alvo.appendChild(dots);
    }
  }

  function renderVertical(nome, vazioTxt) {
    var alvo = slot(nome);
    alvo.innerHTML = '';
    if (!midiaAtual.length) { alvo.appendChild(vazio(vazioTxt)); return; }
    alvo.appendChild(elementoMidia(midiaAtual[0], false));
  }

  function atualizarLegenda() {
    var txt = legenda ? legenda.value : '';
    var f = preview.querySelector('[data-slot="cap-feed"]');
    var r = preview.querySelector('[data-slot="cap-reel"]');
    if (f) { f.textContent = txt; }
    if (r) { r.textContent = txt; }
  }

  function renderCapa() {
    if (!capaPreview) { return; }
    if (capaUrl) {
      capaPreview.innerHTML = '';
      var img = document.createElement('img');
      img.src = capaUrl;
      capaPreview.appendChild(img);
    } else {
      capaPreview.innerHTML = '<span>Sem capa</span>';
    }
  }

  function render() {
    renderFeed();
    renderVertical('story', 'Suba uma imagem ou video 9:16');
    renderVertical('reel', 'Suba um video 9:16');
    atualizarLegenda();
    // capa entra como poster do video do reels
    if (preview.getAttribute('data-tipo') === 'reel' && capaUrl) {
      var rv = preview.querySelector('.ig--reels video');
      if (rv) { rv.poster = capaUrl; }
    }
  }

  function aplicarTipo(tipo, manterMidia) {
    var regra = REGRAS[tipo] || REGRAS.feed;
    tipoInput.value = tipo;
    preview.setAttribute('data-tipo', tipo);

    Array.prototype.forEach.call(typePick.querySelectorAll('.type-btn'), function (b) {
      b.classList.toggle('is-active', b.getAttribute('data-tipo') === tipo);
    });

    fileInput.multiple = regra.multiple;
    fileInput.accept = regra.accept;
    midiaLabel.textContent = regra.label;
    midiaHint.textContent = regra.hint;
    legendaWrap.style.display = regra.legenda ? '' : 'none';
    if (capaWrap) { capaWrap.style.display = (tipo === 'reel') ? '' : 'none'; }

    // se trocou para um tipo de 1 midia e tinha varias, corta
    if (!regra.multiple && midiaAtual.length > 1) {
      midiaAtual = midiaAtual.slice(0, 1);
    }
    if (!manterMidia) { /* mantemos a midia ao trocar de aba */ }
    render();
  }

  // troca de tipo
  typePick.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.type-btn');
    if (!btn) { return; }
    aplicarTipo(btn.getAttribute('data-tipo'), true);
  });

  // selecao de arquivos
  fileInput.addEventListener('change', function () {
    limparUrls();
    midiaAtual = [];
    var arquivos = Array.prototype.slice.call(fileInput.files || []);
    arquivos.forEach(function (file) {
      var url = URL.createObjectURL(file);
      objectUrls.push(url);
      midiaAtual.push({ url: url, tipo: file.type.indexOf('video') === 0 ? 'video' : 'imagem' });
    });
    render();
  });

  if (legenda) {
    legenda.addEventListener('input', atualizarLegenda);
  }

  if (capaInput) {
    capaInput.addEventListener('change', function () {
      if (capaObjUrl) { URL.revokeObjectURL(capaObjUrl); capaObjUrl = null; }
      var f = (capaInput.files || [])[0];
      if (f) {
        capaObjUrl = URL.createObjectURL(f);
        capaUrl = capaObjUrl;
      } else {
        capaUrl = '';
      }
      renderCapa();
      render();
    });
  }

  // estado inicial
  var tipoInicial = window.PUB_TIPO_INICIAL || 'feed';
  if (Array.isArray(window.PUB_EXISTENTES) && window.PUB_EXISTENTES.length) {
    midiaAtual = window.PUB_EXISTENTES.map(function (m) { return { url: m.url, tipo: m.tipo }; });
  }
  if (window.PUB_CAPA) { capaUrl = window.PUB_CAPA; }
  renderCapa();
  aplicarTipo(tipoInicial, true);
})();

</script>
<?php require __DIR__ . '/partials/foot.php'; ?>
