<?php
require __DIR__ . '/init.php';
exigir_login();
require __DIR__ . '/lib_direct.php';

/* ---- Endpoint AJAX: novas mensagens de uma conversa (polling) ---- */
if (($_GET['ajax'] ?? '') === 'thread') {
    header('Content-Type: application/json; charset=utf-8');
    $cid   = (int) ($_GET['c'] ?? 0);
    $after = (int) ($_GET['after'] ?? 0);
    $out   = ['ok' => false, 'msgs' => [], 'unread_total' => 0];
    if ($cid > 0) {
        $st = db()->prepare('SELECT * FROM ' . DB_PREFIX . 'dm_mensagens WHERE conversa_id=? AND id>? ORDER BY id ASC LIMIT 50');
        $st->execute([$cid, $after]);
        foreach ($st->fetchAll() as $m) {
            $out['msgs'][] = ['id' => (int) $m['id'], 'in' => $m['direcao'] === 'in', 'html' => dm_msg_html($m)];
        }
        // marca como lida a conversa aberta
        db()->prepare('UPDATE ' . DB_PREFIX . 'dm_conversas SET nao_lidas=0 WHERE id=?')->execute([$cid]);
        $out['ok'] = true;
    }
    $out['unread_total'] = (int) db()->query('SELECT COALESCE(SUM(nao_lidas),0) FROM ' . DB_PREFIX . 'dm_conversas')->fetchColumn();
    echo json_encode($out);
    exit;
}

/* ---- Endpoint AJAX: lista de conversas ao vivo (novas, prévia, badge, ordem) ---- */
if (($_GET['ajax'] ?? '') === 'convs') {
    header('Content-Type: application/json; charset=utf-8');
    $cli   = (int) ($_GET['cli'] ?? 0);
    $selId = (int) ($_GET['c'] ?? 0);
    echo json_encode([
        'ok'           => true,
        'html'         => dm_lista_html(db(), $cli, $selId),
        'unread_total' => (int) db()->query('SELECT COALESCE(SUM(nao_lidas),0) FROM ' . DB_PREFIX . 'dm_conversas')->fetchColumn(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$flash = '';
$flashTipo = 'ok';

/* Cliente (perfil) selecionado no Direct */
$clientesDM = db()->query('SELECT id, nome FROM ' . DB_PREFIX . 'clientes ORDER BY nome')->fetchAll();
$cliSel = (int) ($_POST['cli'] ?? $_GET['cli'] ?? 0);
if ($cliSel <= 0 && $clientesDM) {
    $cliSel = (int) $clientesDM[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('CSRF invalido.');
    }
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'responder') {
        $cid = (int) ($_POST['conversa_id'] ?? 0);
        $txt = trim((string) ($_POST['texto'] ?? ''));
        $conv = db()->query('SELECT * FROM ' . DB_PREFIX . 'dm_conversas WHERE id=' . $cid)->fetch();
        $temArquivo = !empty($_FILES['midia']['name']) && (int) ($_FILES['midia']['error'] ?? 99) === UPLOAD_ERR_OK;
        if ($conv && ($txt !== '' || $temArquivo)) {
            $cli = db()->query('SELECT id, nome, ig_user_id, access_token FROM ' . DB_PREFIX . 'clientes WHERE id=' . (int) $conv['cliente_id'])->fetch();
            if (!$cli) {
                $flash = 'Falha ao enviar: cliente.';
                $flashTipo = 'erro';
            } else {
                $okGeral = true;
                $erro = '';
                // 1) anexo (foto/vídeo/áudio), se houver
                if ($temArquivo) {
                    $forcar = in_array(($_POST['forcar_tipo'] ?? ''), ['image', 'video', 'audio'], true) ? $_POST['forcar_tipo'] : null;
                    [$ok, $msg] = dm_processar_upload($_FILES['midia'], $forcar);
                    if ($ok) {
                        [$urlPub, $tipo] = $msg;
                        $rm = dm_enviar_midia(db(), $cli, (string) $conv['remetente_id'], $urlPub, $tipo, 'humano');
                        $okGeral = $rm['ok'];
                        $erro = $rm['erro'] ?? '';
                    } else {
                        $okGeral = false;
                        $erro = $msg;
                    }
                }
                // 2) texto, se houver
                if ($okGeral && $txt !== '') {
                    $rt = dm_enviar(db(), $cli, (string) $conv['remetente_id'], $txt, 'humano');
                    $okGeral = $rt['ok'];
                    $erro = $rt['erro'] ?? $erro;
                }
                $flash = $okGeral ? 'Mensagem enviada.' : ('Falha ao enviar: ' . $erro);
                $flashTipo = $okGeral ? 'ok' : 'erro';
            }
        }
    } elseif ($acao === 'toggle_auto') {
        $cid = (int) ($_POST['conversa_id'] ?? 0);
        db()->prepare('UPDATE ' . DB_PREFIX . 'dm_conversas SET auto_ativo = 1-auto_ativo WHERE id=?')->execute([$cid]);
        $flash = 'Auto-resposta alterada nesta conversa.';
    } elseif ($acao === 'sincronizar') {
        $cli = db()->query('SELECT id, nome, ig_user_id, access_token FROM ' . DB_PREFIX . 'clientes WHERE id=' . $cliSel)->fetch();
        if ($cli) {
            $rs = dm_sincronizar_conversas(db(), $cli);
            $flash = $rs['ok'] ? ('Sincronizado: ' . (int) $rs['n'] . ' conversa(s).') : ('Falha ao sincronizar: ' . ($rs['erro'] ?? ''));
            $flashTipo = $rs['ok'] ? 'ok' : 'erro';
        }
    } elseif ($acao === 'limpar_conversa') {
        $cid = (int) ($_POST['conversa_id'] ?? 0);
        db()->prepare('DELETE FROM ' . DB_PREFIX . 'dm_mensagens WHERE conversa_id=?')->execute([$cid]);
        db()->prepare('UPDATE ' . DB_PREFIX . 'dm_conversas SET ultima_msg=NULL, nao_lidas=0 WHERE id=?')->execute([$cid]);
        $flash = 'Mensagens da conversa apagadas.';
    } elseif ($acao === 'excluir_conversa') {
        $cid = (int) ($_POST['conversa_id'] ?? 0);
        db()->prepare('DELETE FROM ' . DB_PREFIX . 'dm_mensagens WHERE conversa_id=?')->execute([$cid]);
        db()->prepare('DELETE FROM ' . DB_PREFIX . 'dm_conversas WHERE id=?')->execute([$cid]);
        $flash = 'Conversa excluída.';
        $_SESSION['flash'] = [$flash, $flashTipo];
        header('Location: ' . BASE_URL . '/direct.php?cli=' . $cliSel);
        exit;
    } elseif ($acao === 'regra_add') {
        $g = trim((string) ($_POST['gatilho'] ?? ''));
        $r = trim((string) ($_POST['resposta'] ?? ''));
        if ($g !== '' && $r !== '') {
            db()->prepare('INSERT INTO ' . DB_PREFIX . 'dm_regras (cliente_id, gatilho, resposta) VALUES (?,?,?)')->execute([$cliSel, $g, $r]);
            $flash = 'Regra adicionada.';
        }
    } elseif ($acao === 'regra_del') {
        db()->prepare('DELETE FROM ' . DB_PREFIX . 'dm_regras WHERE id=?')->execute([(int) ($_POST['id'] ?? 0)]);
        $flash = 'Regra removida.';
    } elseif ($acao === 'salvar_cfg') {
        // por cliente: modo/prompt | global: verify token (webhook do app)
        $modo = (string) ($_POST['dm_modo'] ?? 'off');
        if (!in_array($modo, ['off', 'palavra', 'ia', 'palavra_ia'], true)) {
            $modo = 'off';
        }
        pcfg_set($cliSel, 'dm_modo', $modo);
        // mantém os toggles antigos em sincronia (compatibilidade)
        pcfg_set($cliSel, 'dm_auto_ativo', ($modo === 'palavra' || $modo === 'palavra_ia') ? '1' : '0');
        pcfg_set($cliSel, 'dm_ia_fallback', ($modo === 'ia' || $modo === 'palavra_ia') ? '1' : '0');
        pcfg_set($cliSel, 'dm_ia_prompt', trim((string) ($_POST['dm_ia_prompt'] ?? '')));
        // Jogo da Forca no Direct
        pcfg_set($cliSel, 'dm_jogo_ativo', isset($_POST['dm_jogo_ativo']) ? '1' : '0');
        pcfg_set($cliSel, 'dm_jogo_ia', isset($_POST['dm_jogo_ia']) ? '1' : '0');
        pcfg_set($cliSel, 'dm_jogo_gatilho', trim((string) ($_POST['dm_jogo_gatilho'] ?? '')) ?: 'forca, jogo da forca');
        pcfg_set($cliSel, 'dm_jogo_erros', (string) max(3, min(10, (int) ($_POST['dm_jogo_erros'] ?? 6))));
        pcfg_set($cliSel, 'dm_jogo_timeout', (string) max(0, (int) ($_POST['dm_jogo_timeout'] ?? 60)));
        pcfg_set($cliSel, 'dm_jogo_tema', trim((string) ($_POST['dm_jogo_tema'] ?? '')));
        pcfg_set($cliSel, 'dm_jogo_palavras', trim((string) ($_POST['dm_jogo_palavras'] ?? '')));
        pcfg_set($cliSel, 'dm_jogo_botoes', isset($_POST['dm_jogo_botoes']) ? '1' : '0');
        pcfg_set($cliSel, 'dm_jogo_arte', isset($_POST['dm_jogo_arte']) ? '1' : '0');
        pcfg_set($cliSel, 'dm_jogo_noticia', isset($_POST['dm_jogo_noticia']) ? '1' : '0');
        foreach (['termo', 'quiz', 'enigma', 'velha', 'anagrama', 'adivinha'] as $jg) {
            pcfg_set($cliSel, 'dm_jogo_' . $jg, isset($_POST['dm_jogo_' . $jg]) ? '1' : '0');
        }
        pcfg_set($cliSel, 'dm_jogo_quiz_tema', trim((string) ($_POST['dm_jogo_quiz_tema'] ?? '')));
        pcfg_set($cliSel, 'dm_jogo_palavra_dia', isset($_POST['dm_jogo_palavra_dia']) ? '1' : '0');
        pcfg_set($cliSel, 'dm_jogo_resultado', isset($_POST['dm_jogo_resultado']) ? '1' : '0');
        pcfg_set($cliSel, 'dm_jogo_ranking_semanal', isset($_POST['dm_jogo_ranking_semanal']) ? '1' : '0');
        pcfg_set($cliSel, 'dm_jogo_max_dia', (string) max(0, min(100, (int) ($_POST['dm_jogo_max_dia'] ?? 10))));
        cfg_set('dm_verify_token', trim((string) ($_POST['dm_verify_token'] ?? '')));
        $flash = 'Configurações do Direct salvas.';
    }
    // requisição AJAX -> responde JSON (sem reload)
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        $resp = ['ok' => $flashTipo === 'ok', 'flash' => $flash, 'flashTipo' => $flashTipo];
        if ($acao === 'toggle_auto') {
            $resp['auto'] = (int) db()->query('SELECT auto_ativo FROM ' . DB_PREFIX . 'dm_conversas WHERE id=' . (int) ($_POST['conversa_id'] ?? 0))->fetchColumn();
        }
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['flash'] = [$flash, $flashTipo];
    header('Location: ' . BASE_URL . '/direct.php?cli=' . $cliSel . (isset($_GET['c']) ? '&c=' . (int) $_GET['c'] : ''));
    exit;
}
if (!empty($_SESSION['flash'])) {
    [$flash, $flashTipo] = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$cstmt = db()->prepare('SELECT cv.*, c.nome AS cliente FROM ' . DB_PREFIX . 'dm_conversas cv
    JOIN ' . DB_PREFIX . 'clientes c ON c.id = cv.cliente_id
    WHERE cv.cliente_id = ? ORDER BY cv.ultima_em DESC LIMIT 100');
$cstmt->execute([$cliSel]);
$convs = $cstmt->fetchAll();

/* Backfill: tenta descobrir o @username de quem ainda não tem nome. Só tenta uma vez
   por conversa (perfil_em IS NULL) e com poucas chamadas, p/ não travar a página. */
$pendentes = array_filter($convs, static fn($c) => trim((string) ($c['nome'] ?? '')) === '' && empty($c['perfil_em']));
if ($pendentes) {
    $cliDM = db()->query('SELECT id, nome, ig_user_id, access_token FROM ' . DB_PREFIX . 'clientes WHERE id=' . $cliSel)->fetch();
    if ($cliDM && (string) ($cliDM['access_token'] ?? '') !== '') {
        $n = 0;
        foreach ($convs as &$cvRef) {
            if ($n >= 3) {
                break;
            }
            if (trim((string) ($cvRef['nome'] ?? '')) === '' && empty($cvRef['perfil_em'])) {
                $novo = dm_perfil_buscar(db(), $cliDM, (string) $cvRef['remetente_id'], 6);
                if ($novo !== null) {
                    $cvRef['nome'] = $novo;
                }
                $n++;
            }
        }
        unset($cvRef);
    }
}

$selId = (int) ($_GET['c'] ?? 0);
$sel = null;
$msgs = [];
if ($selId > 0) {
    $sel = db()->query('SELECT cv.*, c.nome AS cliente FROM ' . DB_PREFIX . 'dm_conversas cv
        JOIN ' . DB_PREFIX . 'clientes c ON c.id=cv.cliente_id WHERE cv.id=' . $selId)->fetch() ?: null;
    if ($sel) {
        if (trim((string) ($sel['nome'] ?? '')) === '' && empty($sel['perfil_em'])) {
            $cliThr = db()->query('SELECT id, nome, ig_user_id, access_token FROM ' . DB_PREFIX . 'clientes WHERE id=' . (int) $sel['cliente_id'])->fetch();
            if ($cliThr) {
                $novoNome = dm_perfil_buscar(db(), $cliThr, (string) $sel['remetente_id'], 6);
                if ($novoNome !== null) {
                    $sel['nome'] = $novoNome;
                }
            }
        }
        db()->prepare('UPDATE ' . DB_PREFIX . 'dm_conversas SET nao_lidas=0 WHERE id=?')->execute([$selId]);
        // igual ao Instagram: as 200 MAIS RECENTES, exibidas da mais antiga (topo) à mais nova (base)
        $msgs = db()->query('SELECT * FROM (SELECT * FROM ' . DB_PREFIX . 'dm_mensagens
            WHERE conversa_id=' . $selId . ' ORDER BY id DESC LIMIT 200) t ORDER BY id ASC')->fetchAll();
    }
}

$rstmt = db()->prepare('SELECT * FROM ' . DB_PREFIX . 'dm_regras WHERE cliente_id=? OR cliente_id IS NULL ORDER BY posicao, id');
$rstmt->execute([$cliSel]);
$regras = $rstmt->fetchAll();
$cfg = [
    'modo'   => dm_modo_atual($cliSel),
    'verify' => (string) cfg_get('dm_verify_token', ''), // global (webhook do app)
    'prompt' => (string) pcfg_get($cliSel, 'dm_ia_prompt', ''),
];

$page_title = 'Direct';
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <div class="page-head">
    <div>
      <h1>Direct — mensagens</h1>
      <p>Caixa de entrada e automação <b>por cliente</b> (palavra-chave + IA).</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <form method="post" style="margin:0" title="Importa conversas recebidas e iniciadas pelo @ conectado">
        <?= csrf_field() ?><input type="hidden" name="cli" value="<?= $cliSel ?>"><input type="hidden" name="acao" value="sincronizar">
        <button class="btn-ghost"><i class="fa-solid fa-rotate"></i> Sincronizar</button>
      </form>
      <?php if (count($clientesDM) > 1): ?>
        <select onchange="location.href='direct.php?cli='+this.value" class="btn-ghost" style="padding:10px">
          <?php foreach ($clientesDM as $cl): ?>
            <option value="<?= (int) $cl['id'] ?>" <?= $cliSel === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash): ?><div class="aviso aviso--<?= e($flashTipo) ?>"><?= e($flash) ?></div><?php endif; ?>

  <?php if (!$cfg['verify']): ?>
    <div class="aviso aviso--erro">
      <b>Webhook ainda não configurado.</b> Defina um <b>Verify token</b> abaixo e cadastre na Meta
      a URL <code><?= e(BASE_URL) ?>/webhook.php</code> assinando o campo <code>messages</code>.
    </div>
  <?php endif; ?>

  <div class="dm<?= $sel ? ' has-sel' : '' ?>">
    <!-- lista de conversas (atualiza ao vivo via AJAX) -->
    <div class="dm__list" id="dmList" data-cli="<?= $cliSel ?>" data-sel="<?= $selId ?>">
      <?php if (!$convs): ?>
        <div class="empty"><div class="empty__mark"><i class="fa-regular fa-comments"></i></div>
          <h2>Sem conversas</h2><p>Quando alguém mandar Direct, aparece aqui.</p></div>
      <?php else: foreach ($convs as $cv): ?>
        <?= dm_conv_item_html($cv, $cliSel, $selId) ?>
      <?php endforeach; endif; ?>
    </div>

    <!-- thread -->
    <div class="dm__thread">
      <?php if (!$sel): ?>
        <div class="empty"><div class="empty__mark"><i class="fa-regular fa-hand-pointer"></i></div>
          <h2>Selecione uma conversa</h2></div>
      <?php else: ?>
        <div class="dm__thread-head">
          <div class="dm__thread-who">
            <a class="dm__back" href="direct.php?cli=<?= $cliSel ?>" aria-label="Voltar"><i class="fa-solid fa-arrow-left"></i></a>
            <?= dm_avatar_html($sel, 44) ?>
            <div class="dm__thread-id">
              <b><?= e(dm_nome_exibir($sel)) ?></b>
              <small>
                <?php if (isset($sel['seguidores']) && $sel['seguidores'] !== null): ?>
                  <span><i class="fa-solid fa-user-group"></i> <?= e(dm_seguidores_fmt($sel['seguidores'])) ?> seguidores</span>
                <?php endif; ?>
                <?= dm_segue_badge($sel) ?>
                <?php if ((int) ($sel['segue'] ?? 0) === 1 && !empty($sel['segue_desde'])): ?>
                  <span title="Data em que o sistema detectou o follow (a Meta não informa a data real)">desde <?= e(date('d/m/Y', strtotime((string) $sel['segue_desde']))) ?></span>
                <?php endif; ?>
              </small>
            </div>
          </div>
          <div class="dm__thread-acoes">
            <button class="btn-ghost" type="button" id="dmSound" title="Ativar/desativar som de novas mensagens">
              <i class="fa-solid fa-volume-high"></i>
            </button>
            <form method="post" style="margin:0">
              <?= csrf_field() ?><input type="hidden" name="cli" value="<?= $cliSel ?>">
              <input type="hidden" name="acao" value="toggle_auto">
              <input type="hidden" name="conversa_id" value="<?= (int) $sel['id'] ?>">
              <button class="btn-ghost" title="Liga/desliga a automação nesta conversa"><i class="fa-solid fa-robot"></i> Auto: <?= ((int) $sel['auto_ativo']) ? 'ON' : 'off' ?></button>
            </form>
            <form method="post" style="margin:0" onsubmit="return confirm('Apagar TODAS as mensagens desta conversa? A conversa continua na lista.');">
              <?= csrf_field() ?><input type="hidden" name="cli" value="<?= $cliSel ?>">
              <input type="hidden" name="acao" value="limpar_conversa">
              <input type="hidden" name="conversa_id" value="<?= (int) $sel['id'] ?>">
              <button class="btn-ghost" title="Limpar mensagens"><i class="fa-solid fa-eraser"></i></button>
            </form>
            <form method="post" style="margin:0" onsubmit="return confirm('Excluir esta conversa e todas as mensagens? Não dá para desfazer.');">
              <?= csrf_field() ?><input type="hidden" name="cli" value="<?= $cliSel ?>">
              <input type="hidden" name="acao" value="excluir_conversa">
              <input type="hidden" name="conversa_id" value="<?= (int) $sel['id'] ?>">
              <button class="btn-ghost btn-danger" title="Excluir conversa"><i class="fa-solid fa-trash-can"></i></button>
            </form>
          </div>
        </div>
        <div class="dm__msgs" id="dmMsgs" data-conv="<?= (int) $sel['id'] ?>">
          <?php foreach ($msgs as $m): ?>
            <?= dm_msg_html($m) ?>
          <?php endforeach; ?>
        </div>
        <form method="post" class="dm__reply" enctype="multipart/form-data" id="dmReply">
          <?= csrf_field() ?><input type="hidden" name="cli" value="<?= $cliSel ?>">
          <input type="hidden" name="acao" value="responder">
          <input type="hidden" name="conversa_id" value="<?= (int) $sel['id'] ?>">
          <input type="hidden" name="forcar_tipo" id="dmForcarTipo" value="">
          <label class="dm__attach" title="Enviar foto, vídeo ou áudio">
            <i class="fa-solid fa-image"></i>
            <input type="file" name="midia" id="dmFile" accept="image/*,video/*,audio/*" onchange="this.form.querySelector('.dm__attach-name').textContent=this.files[0]?this.files[0].name:'';">
          </label>
          <button class="dm__attach" type="button" id="dmRec" title="Gravar áudio"><i class="fa-solid fa-microphone"></i></button>
          <input type="text" name="texto" id="dmTexto" placeholder="Mensagem…" autocomplete="off">
          <span class="dm__attach-name"></span>
          <button class="btn-inline" type="submit"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- configuração + regras -->
  <?php
    $nomePerfil = '';
    foreach ($clientesDM as $cl) {
        if ((int) $cl['id'] === $cliSel) {
            $nomePerfil = (string) $cl['nome'];
        }
    }
    $modoLabel = ['off' => 'Desligado', 'palavra' => 'Apenas palavras-chave', 'ia' => 'Apenas IA', 'palavra_ia' => 'Palavras-chave + IA'][$cfg['modo']] ?? $cfg['modo'];
  ?>
  <div class="section-head">
    <h2>Automação — perfil <b>@<?= e($nomePerfil) ?></b></h2>
  </div>
  <div class="aviso aviso--<?= $cfg['modo'] === 'off' ? 'ok' : 'erro' ?>" style="margin-bottom:12px">
    Estas configurações valem <b>somente para o perfil @<?= e($nomePerfil) ?></b>.
    Modo atual: <b><?= e($modoLabel) ?></b>.
    <?php if (count($clientesDM) > 1): ?>Troque o perfil no seletor no topo da página para configurar outro.<?php endif; ?>
  </div>
  <form method="post" class="formbox">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="salvar_cfg">
    <input type="hidden" name="cli" value="<?= $cliSel ?>">
    <div class="cfg-grid">
      <div class="field field--full">
        <label>Modo de resposta automática</label>
        <select name="dm_modo">
          <option value="off"        <?= $cfg['modo'] === 'off' ? 'selected' : '' ?>>Desligado — só respondo manualmente</option>
          <option value="palavra"    <?= $cfg['modo'] === 'palavra' ? 'selected' : '' ?>>Apenas palavras-chave</option>
          <option value="ia"         <?= $cfg['modo'] === 'ia' ? 'selected' : '' ?>>Apenas IA (OpenAI, tem custo)</option>
          <option value="palavra_ia" <?= $cfg['modo'] === 'palavra_ia' ? 'selected' : '' ?>>Palavras-chave + IA (IA como fallback)</option>
        </select>
        <small class="hint">A automação só responde quando este modo estiver diferente de <b>Desligado</b>. Cada conversa ainda pode ser pausada individualmente no botão <b>Auto</b>.</small>
      </div>
      <div class="field field--full">
        <label>Verify token do Webhook</label>
        <input type="text" name="dm_verify_token" value="<?= e($cfg['verify']) ?>" placeholder="ex.: tevinobuzao-123">
        <small class="hint">Use o mesmo valor ao cadastrar o webhook na Meta. URL: <code><?= e(BASE_URL) ?>/webhook.php</code></small>
      </div>
      <div class="field field--full">
        <label>Tom/instrução da IA (opcional)</label>
        <textarea name="dm_ia_prompt" rows="2" placeholder="Você é o atendente do @tevinobuzao…"><?= e($cfg['prompt']) ?></textarea>
      </div>
      <div class="field field--full" style="border-top:1px solid var(--line);padding-top:12px">
        <label><i class="fa-solid fa-gamepad"></i> Jogo da Forca no Direct</label>
        <label style="font-weight:400"><input type="checkbox" name="dm_jogo_ativo" <?= pcfg_get($cliSel, 'dm_jogo_ativo', '0') === '1' ? 'checked' : '' ?>> Ligado — quem mandar o gatilho começa uma partida</label>
        <small class="hint"><b>“jogo”</b> (ou “jogos”/“jogar”) abre o <b>menu do arcade</b>; <b>“forca”</b> começa a forca. Comandos: <b>dica</b> (custa 1 vida), <b>placar</b>, <b>ranking</b>, <b>parar</b>.</small>
      </div>
      <div class="field">
        <label>Palavras que começam a <b>forca</b></label>
        <input type="text" name="dm_jogo_gatilho" value="<?= e((string) pcfg_get($cliSel, 'dm_jogo_gatilho', 'forca, jogo da forca')) ?>" placeholder="forca, jogo da forca">
      </div>
      <div class="field">
        <label>Erros até enforcar (3–10)</label>
        <input type="number" name="dm_jogo_erros" min="3" max="10" value="<?= (int) pcfg_get($cliSel, 'dm_jogo_erros', '6') ?>">
      </div>
      <div class="field">
        <label>Expirar partida parada (min)</label>
        <input type="number" name="dm_jogo_timeout" min="0" max="1440" value="<?= (int) pcfg_get($cliSel, 'dm_jogo_timeout', '60') ?>">
        <small class="hint">0 = nunca expira.</small>
      </div>
      <div class="field">
        <label>Palavras pela IA (OpenAI)</label>
        <label style="font-weight:400"><input type="checkbox" name="dm_jogo_ia" <?= pcfg_get($cliSel, 'dm_jogo_ia', '0') === '1' ? 'checked' : '' ?>> Sortear palavra e dica com a OpenAI</label>
        <small class="hint">Tem custo (fica no relatório de custos como “jogo”). Se a IA falhar, usa a lista abaixo.</small>
      </div>
      <div class="field field--full">
        <label>Tema para a IA</label>
        <input type="text" name="dm_jogo_tema" value="<?= e((string) pcfg_get($cliSel, 'dm_jogo_tema', '')) ?>" placeholder="ônibus, transporte público, Maceió, Alagoas e cotidiano brasileiro">
      </div>
      <div class="field">
        <label>Botões de letra e arte</label>
        <label style="font-weight:400"><input type="checkbox" name="dm_jogo_botoes" <?= pcfg_get($cliSel, 'dm_jogo_botoes', '1') === '1' ? 'checked' : '' ?>> Mandar botões de letra (a pessoa toca em vez de digitar)</label>
        <label style="font-weight:400"><input type="checkbox" name="dm_jogo_arte" <?= pcfg_get($cliSel, 'dm_jogo_arte', '1') === '1' ? 'checked' : '' ?>> Mandar a arte da forca (imagem) no começo e no fim</label>
      </div>
      <div class="field">
        <label>Máximo de partidas por pessoa/dia</label>
        <input type="number" name="dm_jogo_max_dia" min="0" max="100" value="<?= (int) pcfg_get($cliSel, 'dm_jogo_max_dia', '10') ?>">
        <small class="hint">0 = sem limite. Segura spam e custo de IA.</small>
      </div>
      <div class="field field--full">
        <label>🕹️ Jogos do arcade (quem manda “jogo” vê o menu)</label>
        <div style="display:flex;flex-wrap:wrap;gap:14px">
          <label style="font-weight:400"><input type="checkbox" name="dm_jogo_termo" <?= pcfg_get($cliSel, 'dm_jogo_termo', '1') === '1' ? 'checked' : '' ?>> 🟩 Termo</label>
          <label style="font-weight:400"><input type="checkbox" name="dm_jogo_quiz" <?= pcfg_get($cliSel, 'dm_jogo_quiz', '1') === '1' ? 'checked' : '' ?>> ❓ Quiz</label>
          <label style="font-weight:400"><input type="checkbox" name="dm_jogo_enigma" <?= pcfg_get($cliSel, 'dm_jogo_enigma', '1') === '1' ? 'checked' : '' ?>> 🕵️ Enigma</label>
          <label style="font-weight:400"><input type="checkbox" name="dm_jogo_velha" <?= pcfg_get($cliSel, 'dm_jogo_velha', '1') === '1' ? 'checked' : '' ?>> ⭕ Velha</label>
          <label style="font-weight:400"><input type="checkbox" name="dm_jogo_anagrama" <?= pcfg_get($cliSel, 'dm_jogo_anagrama', '1') === '1' ? 'checked' : '' ?>> 🔀 Anagrama</label>
          <label style="font-weight:400"><input type="checkbox" name="dm_jogo_adivinha" <?= pcfg_get($cliSel, 'dm_jogo_adivinha', '1') === '1' ? 'checked' : '' ?>> 🔢 Adivinha</label>
        </div>
        <small class="hint">A forca liga/desliga no interruptor geral do jogo (acima).</small>
      </div>
      <div class="field field--full">
        <label>Tema das perguntas do Quiz (só com IA ligada)</label>
        <input type="text" name="dm_jogo_quiz_tema" value="<?= e((string) pcfg_get($cliSel, 'dm_jogo_quiz_tema', '')) ?>" placeholder="conhecimentos gerais do Brasil">
      </div>
      <div class="field field--full">
        <label>Engajamento</label>
        <label style="font-weight:400"><input type="checkbox" name="dm_jogo_palavra_dia" <?= pcfg_get($cliSel, 'dm_jogo_palavra_dia', '1') === '1' ? 'checked' : '' ?>> <b>Palavra do dia</b> — todo mundo joga a mesma palavra por dia (com ranking do dia)</label>
        <label style="font-weight:400"><input type="checkbox" name="dm_jogo_resultado" <?= pcfg_get($cliSel, 'dm_jogo_resultado', '1') === '1' ? 'checked' : '' ?>> Mandar <b>imagem de resultado</b> no fim (feita pra pessoa postar no story)</label>
        <label style="font-weight:400"><input type="checkbox" name="dm_jogo_ranking_semanal" <?= pcfg_get($cliSel, 'dm_jogo_ranking_semanal', '0') === '1' ? 'checked' : '' ?>> Mandar o <b>ranking na segunda de manhã</b> para quem jogou</label>
      </div>
      <div class="field field--full">
        <label style="font-weight:400"><input type="checkbox" name="dm_jogo_noticia" <?= pcfg_get($cliSel, 'dm_jogo_noticia', '0') === '1' ? 'checked' : '' ?>> Tirar a palavra das manchetes da Mesa de Redação (opcional — o jogo é independente do perfil)</label>
      </div>
      <div class="field field--full">
        <label>Lista fixa de palavras (uma por linha: <code>PALAVRA|dica</code>)</label>
        <textarea name="dm_jogo_palavras" rows="4" placeholder="CATRACA|Você passa por ela ao entrar no busão"><?= e((string) pcfg_get($cliSel, 'dm_jogo_palavras', '')) ?></textarea>
        <small class="hint">Vazio = usa a lista padrão de palavras gerais (comida, objetos, lugares…).</small>
      </div>
    </div>
    <div class="form-actions"><button class="btn-inline"><i class="fa-solid fa-floppy-disk"></i> Salvar</button></div>
  </form>

  <div class="section-head"><h2>Regras por palavra-chave</h2></div>
  <form method="post" class="formbox">
    <?= csrf_field() ?>
    <input type="hidden" name="acao" value="regra_add">
    <input type="hidden" name="cli" value="<?= $cliSel ?>">
    <div class="cfg-grid">
      <div class="field"><label>Gatilho (palavras, vírgula)</label><input type="text" name="gatilho" placeholder="preço, valor, tabela"></div>
      <div class="field field--full"><label>Resposta</label><textarea name="resposta" rows="2" placeholder="Nossos valores são…"></textarea></div>
    </div>
    <div class="form-actions"><button class="btn-inline"><i class="fa-solid fa-plus"></i> Adicionar regra</button></div>
  </form>

  <?php if ($regras): ?>
    <div class="news-list" style="margin-top:14px">
      <?php foreach ($regras as $r): ?>
        <div class="news-item">
          <div class="news-item__body">
            <div class="news-item__meta"><span class="tag tag--ok"><?= e((string) $r['gatilho']) ?></span></div>
            <p class="news-item__resumo"><?= e((string) $r['resposta']) ?></p>
            <form method="post" class="news-item__acoes" onsubmit="return confirm('Remover esta regra?');">
              <?= csrf_field() ?><input type="hidden" name="cli" value="<?= $cliSel ?>"><input type="hidden" name="acao" value="regra_del"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn-ghost"><i class="fa-solid fa-trash-can"></i> Remover</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<script>
/* ---- lista de conversas AO VIVO (sempre, mesmo sem conversa aberta) ---- */
(function () {
  var list = document.getElementById('dmList');
  if (!list) return;
  var cli = list.getAttribute('data-cli'), sel = list.getAttribute('data-sel') || '0';
  function setNav(total) {
    var b = document.getElementById('navDmBadge');
    if (!b) return;
    b.textContent = total > 0 ? total : '';
    b.style.display = total > 0 ? '' : 'none';
  }
  function refreshConvs() {
    if (document.hidden) return;
    fetch('direct.php?ajax=convs&cli=' + cli + '&c=' + sel, { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        // só re-renderiza se mudou algo (evita piscar)
        if (list.dataset.sig !== d.html) { list.innerHTML = d.html; list.dataset.sig = d.html; }
        setNav(d.unread_total);
      }).catch(function () {});
  }
  list.dataset.sig = list.innerHTML;
  setInterval(refreshConvs, 5000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshConvs(); });
})();
</script>

<?php if ($sel): ?>
<script>
(function () {
  var box     = document.getElementById('dmMsgs');
  if (!box) return;
  var convId  = box.getAttribute('data-conv');
  var lastId  = 0;
  box.querySelectorAll('.dm__msg').forEach(function (el) {
    var id = parseInt(el.getAttribute('data-id') || '0', 10);
    if (id > lastId) lastId = id;
  });
  function scrollBottom() { box.scrollTop = box.scrollHeight; }
  scrollBottom();

  /* ---- som de nova mensagem (com mute persistente) ---- */
  var soundBtn = document.getElementById('dmSound');
  var muted = false;
  try { muted = localStorage.getItem('dm_mudo') === '1'; } catch (e) {}
  function paintSound() {
    if (!soundBtn) return;
    soundBtn.innerHTML = muted ? '<i class="fa-solid fa-volume-xmark"></i>' : '<i class="fa-solid fa-volume-high"></i>';
    soundBtn.classList.toggle('is-off', muted);
  }
  paintSound();
  if (soundBtn) soundBtn.addEventListener('click', function () {
    muted = !muted;
    try { localStorage.setItem('dm_mudo', muted ? '1' : '0'); } catch (e) {}
    paintSound();
  });
  var actx = null;
  function beep() {
    if (muted) return;
    try {
      actx = actx || new (window.AudioContext || window.webkitAudioContext)();
      var o = actx.createOscillator(), g = actx.createGain();
      o.connect(g); g.connect(actx.destination);
      o.type = 'sine'; o.frequency.value = 880;
      g.gain.setValueAtTime(0.001, actx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.25, actx.currentTime + 0.01);
      g.gain.exponentialRampToValueAtTime(0.001, actx.currentTime + 0.3);
      o.start(); o.stop(actx.currentTime + 0.31);
    } catch (e) {}
  }

  /* ---- polling AJAX de novas mensagens ---- */
  function poll() {
    if (document.hidden) return; // não consome bateria/rede com a aba oculta
    fetch('direct.php?ajax=thread&c=' + convId + '&after=' + lastId, { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        var gotIncoming = false, nearBottom = (box.scrollHeight - box.scrollTop - box.clientHeight) < 80;
        (d.msgs || []).forEach(function (m) {
          if (m.id <= lastId) return;
          box.insertAdjacentHTML('beforeend', m.html); // novas SEMPRE embaixo (igual Instagram)
          lastId = m.id;
          if (m.in) gotIncoming = true;
        });
        if (gotIncoming) { beep(); }
        if (nearBottom || gotIncoming) scrollBottom();
      })
      .catch(function () {});
  }
  setInterval(poll, 3000); // ~tempo real
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

  /* ---- gravação de áudio pelo microfone ---- */
  var recBtn = document.getElementById('dmRec'),
      fileEl = document.getElementById('dmFile'),
      forcar = document.getElementById('dmForcarTipo'),
      form   = document.getElementById('dmReply'),
      nameEl = form ? form.querySelector('.dm__attach-name') : null,
      mediaRec = null, chunks = [], gravando = false;
  if (recBtn && navigator.mediaDevices && window.MediaRecorder) {
    recBtn.addEventListener('click', function () {
      if (gravando) { mediaRec && mediaRec.stop(); return; }
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        chunks = [];
        mediaRec = new MediaRecorder(stream);
        mediaRec.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
        mediaRec.onstop = function () {
          stream.getTracks().forEach(function (t) { t.stop(); });
          var blob = new Blob(chunks, { type: mediaRec.mimeType || 'audio/webm' });
          var ext = (blob.type.indexOf('ogg') > -1) ? 'ogg' : 'webm';
          var file = new File([blob], 'gravacao.' + ext, { type: blob.type });
          var dt = new DataTransfer(); dt.items.add(file);
          fileEl.files = dt.files;
          forcar.value = 'audio';
          if (nameEl) nameEl.textContent = '🎤 áudio gravado';
          recBtn.classList.remove('is-rec');
          recBtn.innerHTML = '<i class="fa-solid fa-microphone"></i>';
          gravando = false;
        };
        mediaRec.start();
        gravando = true;
        recBtn.classList.add('is-rec');
        recBtn.innerHTML = '<i class="fa-solid fa-stop"></i>';
      }).catch(function () { alert('Não foi possível acessar o microfone.'); });
    });
  } else if (recBtn) {
    recBtn.style.display = 'none';
  }

  /* ---- ENVIO sem reload (texto/áudio/mídia) + toggle Auto via AJAX ---- */
  function limparComposer() {
    var t = document.getElementById('dmTexto');
    if (t) t.value = '';
    if (fileEl) fileEl.value = '';
    if (forcar) forcar.value = '';
    if (nameEl) nameEl.textContent = '';
  }
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var acaoEl = f && f.querySelector ? f.querySelector('input[name="acao"]') : null;
    if (!acaoEl) return;
    var acao = acaoEl.value;

    if (acao === 'responder') {
      e.preventDefault();
      var txtEl = document.getElementById('dmTexto');
      var temArquivo = fileEl && fileEl.files && fileEl.files.length > 0;
      if ((!txtEl || txtEl.value.trim() === '') && !temArquivo) return; // nada a enviar
      var sendBtn = f.querySelector('button[type="submit"]');
      if (sendBtn) sendBtn.disabled = true;
      var fd = new FormData(f); fd.append('ajax', '1');
      fetch('direct.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (sendBtn) sendBtn.disabled = false;
          if (d && d.ok) { limparComposer(); poll(); } // a msg enviada aparece embaixo na hora
          else { alert((d && d.flash) || 'Falha ao enviar.'); }
        })
        .catch(function () { if (sendBtn) sendBtn.disabled = false; alert('Falha ao enviar.'); });

    } else if (acao === 'toggle_auto') {
      e.preventDefault();
      var fd2 = new FormData(f); fd2.append('ajax', '1');
      fetch('direct.php', { method: 'POST', body: fd2, headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.ok) {
            var b = f.querySelector('button');
            if (b) b.innerHTML = '<i class="fa-solid fa-robot"></i> Auto: ' + (d.auto ? 'ON' : 'off');
          }
        }).catch(function () {});
    }
  });
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/partials/foot.php'; ?>
