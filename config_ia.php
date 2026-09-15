<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require __DIR__ . '/init.php';
exigir_login();
require __DIR__ . '/lib_arte_codigo.php';
require __DIR__ . '/lib_temas.php';

$flash = '';
$flashTipo = 'ok';

/* ---- Perfil em edição (multi-perfil) ---- */
$perfis = db()->query('SELECT id, nome FROM ' . DB_PREFIX . 'clientes ORDER BY nome')->fetchAll();
$perfilId = (int) ($_POST['perfil'] ?? $_GET['perfil'] ?? 0);
if ($perfilId <= 0) {
    $perfilId = (int) (db()->query('SELECT cliente_id FROM ' . DB_PREFIX . 'config_perfil WHERE chave="ia_ativo" AND valor="1" ORDER BY cliente_id LIMIT 1')->fetchColumn() ?: cfg_get('ia_cliente_id', '0'));
    if ($perfilId <= 0 && $perfis) {
        $perfilId = (int) $perfis[0]['id'];
    }
}

/* lista de clientes p/ o seletor */
$clientes = db()->query('SELECT id, nome, ig_username, access_token, ig_user_id FROM ' . DB_PREFIX . 'clientes ORDER BY nome')->fetchAll();

/* ---- POST ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('CSRF invalido.');
    }
    $acao = (string) ($_POST['acao'] ?? 'salvar');

    if ($acao === 'gerar_agora') {
        require __DIR__ . '/lib_gerador.php';
        $cid = $perfilId;
        if ($cid <= 0) {
            $flash = 'Defina o cliente alvo antes de gerar.';
            $flashTipo = 'erro';
        } else {
            $r = gerar_rascunho_noticia(db(), $cid);
            if ($r['ok']) {
                $flash = 'Rascunho criado (sem custo de imagem). Aprove em Aprovação para gerar a imagem e postar: ' . $r['titulo'];
            } else {
                $flash = 'Não foi possível gerar: ' . $r['erro'];
                $flashTipo = 'erro';
            }
        }
    } else {
        // salvar configuracoes
        pcfg_set($perfilId, 'ia_ativo',            isset($_POST['ia_ativo']) ? '1' : '0');
        pcfg_set($perfilId, 'ia_cliente_id',       (string) (int) ($_POST['ia_cliente_id'] ?? 0));
        pcfg_set($perfilId, 'ia_tipo_post',        in_array($_POST['ia_tipo_post'] ?? '', ['feed', 'story', 'ambos'], true) ? $_POST['ia_tipo_post'] : 'feed');
        pcfg_set($perfilId, 'ia_intervalo_min',    (string) max(5, (int) ($_POST['ia_intervalo_min'] ?? 240)));

        // ---- automação organica / por codigo ----
        pcfg_set($perfilId, 'ia_modo_geracao', in_array($_POST['ia_modo_geracao'] ?? '', ['codigo', 'ia'], true) ? $_POST['ia_modo_geracao'] : 'codigo');
        pcfg_set($perfilId, 'ia_fluxo',        in_array($_POST['ia_fluxo'] ?? '', ['curadoria', 'auto', 'aprovacao'], true) ? $_POST['ia_fluxo'] : 'curadoria');

        // ---- linha editorial (Mesa de Redação) ----
        foreach (array_keys(temas_definicoes()) as $tk) {
            pcfg_set($perfilId, 'ia_tema_' . $tk, isset($_POST['ia_tema_' . $tk]) ? '1' : '0');
        }
        pcfg_set($perfilId, 'ia_filtro_ia',     isset($_POST['ia_filtro_ia']) ? '1' : '0');
        pcfg_set($perfilId, 'ia_filtro_ia_max', (string) max(0, min(60, (int) ($_POST['ia_filtro_ia_max'] ?? 15))));
        pcfg_set($perfilId, 'ia_onibus_auto',   isset($_POST['ia_onibus_auto']) ? '1' : '0');
        pcfg_set($perfilId, 'ia_limite_margem', (string) max(0, (int) ($_POST['ia_limite_margem'] ?? 5)));
        pcfg_set($perfilId, 'ia_template',     (string) max(1, min(4, (int) ($_POST['ia_template'] ?? 1))));
        pcfg_set($perfilId, 'ia_cenario',      (($_POST['ia_cenario'] ?? 'onibus') === 'off') ? 'off' : 'onibus');
        pcfg_set($perfilId, 'ia_handle',       trim((string) ($_POST['ia_handle'] ?? '@tevinobuzao')) ?: '@tevinobuzao');
        pcfg_set($perfilId, 'ia_distribuir_dia', isset($_POST['ia_distribuir_dia']) ? '1' : '0');
        pcfg_set($perfilId, 'ia_max_dia',      (string) max(0, min(1000, (int) ($_POST['ia_max_dia'] ?? 0)))); // 0 = sem limite
        pcfg_set($perfilId, 'ia_min_intervalo', (string) max(2, (int) ($_POST['ia_min_intervalo'] ?? 3)));
        pcfg_set($perfilId, 'ia_buffer',       (string) max(1, min(50, (int) ($_POST['ia_buffer'] ?? 6))));
        pcfg_set($perfilId, 'ia_recencia_h',   (string) max(2, (int) ($_POST['ia_recencia_h'] ?? 48)));
        pcfg_set($perfilId, 'ia_audio_ativo',  isset($_POST['ia_audio_ativo']) ? '1' : '0');
        pcfg_set($perfilId, 'ia_audio_seg',    (string) max(5, min(15, (int) ($_POST['ia_audio_seg'] ?? 8))));
        pcfg_set($perfilId, 'ia_palavras_urgente', trim((string) ($_POST['ia_palavras_urgente'] ?? '')));

        // janela de horário HH:MM (05:00–23:30); deriva hora cheia p/ o fluxo de aprovação
        $ji = preg_match('/^\d{1,2}:\d{2}$/', (string) ($_POST['ia_janela_inicio'] ?? '')) ? $_POST['ia_janela_inicio'] : '05:00';
        $jf = preg_match('/^\d{1,2}:\d{2}$/', (string) ($_POST['ia_janela_fim'] ?? '')) ? $_POST['ia_janela_fim'] : '23:30';
        pcfg_set($perfilId, 'ia_janela_inicio', $ji);
        pcfg_set($perfilId, 'ia_janela_fim',    $jf);
        pcfg_set($perfilId, 'ia_hora_inicio',   (string) (int) substr($ji, 0, 2));
        pcfg_set($perfilId, 'ia_hora_fim',      (string) (int) substr($jf, 0, 2));

        // ---- filtros de conteúdo ----
        pcfg_set($perfilId, 'ia_bloquear_palavras', trim((string) ($_POST['ia_bloquear_palavras'] ?? '')));
        pcfg_set($perfilId, 'ia_exigir_palavras',   trim((string) ($_POST['ia_exigir_palavras'] ?? '')));
        pcfg_set($perfilId, 'ia_exigir_foto',       isset($_POST['ia_exigir_foto']) ? '1' : '0');

        // upload opcional de trilha de áudio (mp3/m4a)
        if (!empty($_FILES['ia_audio']['tmp_name']) && is_uploaded_file($_FILES['ia_audio']['tmp_name'])) {
            $ext = strtolower(pathinfo((string) $_FILES['ia_audio']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['mp3', 'm4a', 'aac', 'wav'], true)) {
                $dirA = __DIR__ . '/uploads/audio';
                @mkdir($dirA, 0775, true);
                $nomeA = 'trilha-' . date('YmdHis') . '.' . $ext;
                if (@move_uploaded_file($_FILES['ia_audio']['tmp_name'], $dirA . '/' . $nomeA)) {
                    pcfg_set($perfilId, 'ia_audio_arquivo', 'uploads/audio/' . $nomeA);
                }
            }
        }
        pcfg_set($perfilId, 'ia_modelo_texto',     trim((string) ($_POST['ia_modelo_texto'] ?? 'gpt-4o-mini')) ?: 'gpt-4o-mini');
        pcfg_set($perfilId, 'ia_modelo_imagem',    trim((string) ($_POST['ia_modelo_imagem'] ?? 'gpt-image-1')) ?: 'gpt-image-1');
        pcfg_set($perfilId, 'ia_tamanho_imagem',   in_array($_POST['ia_tamanho_imagem'] ?? '', ['1024x1024', '1024x1536', '1536x1024'], true) ? $_POST['ia_tamanho_imagem'] : '1024x1536');
        pcfg_set($perfilId, 'ia_qualidade_imagem', in_array($_POST['ia_qualidade_imagem'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['ia_qualidade_imagem'] : 'medium');
        pcfg_set($perfilId, 'ia_preco_imagem_usd', (string) (float) str_replace(',', '.', (string) ($_POST['ia_preco_imagem_usd'] ?? '0.04')));
        pcfg_set($perfilId, 'ia_usd_brl',          (string) (float) str_replace(',', '.', (string) ($_POST['ia_usd_brl'] ?? '5.40')));

        // feeds: "Nome | URL" por linha -> JSON
        $linhas = preg_split('/\r\n|\r|\n/', (string) ($_POST['ia_feeds'] ?? '')) ?: [];
        $feeds = [];
        foreach ($linhas as $ln) {
            $ln = trim($ln);
            if ($ln === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $ln, 2));
            if (count($parts) === 2 && filter_var($parts[1], FILTER_VALIDATE_URL)) {
                $feeds[] = ['nome' => $parts[0], 'url' => $parts[1]];
            } elseif (filter_var($parts[0], FILTER_VALIDATE_URL)) {
                $feeds[] = ['nome' => parse_url($parts[0], PHP_URL_HOST) ?: 'Fonte', 'url' => $parts[0]];
            }
        }
        pcfg_set($perfilId, 'ia_feeds', json_encode($feeds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // visibilidade das seções do painel (checkboxes; ausente = oculto)
        foreach (array_keys(ui_secoes()) as $chave) {
            cfg_set($chave, isset($_POST[$chave]) ? '1' : '0');
        }

        $flash = 'Configurações salvas.';
    }
}

/* ---- valores atuais ---- */
$v = [
    'ativo'      => pcfg_get($perfilId, 'ia_ativo', '0') === '1',
    'cliente'    => (int) pcfg_get($perfilId, 'ia_cliente_id', '0'),
    'tipo'       => (string) pcfg_get($perfilId, 'ia_tipo_post', 'feed'),
    'intervalo'  => (int) pcfg_get($perfilId, 'ia_intervalo_min', '240'),
    'hini'       => (int) pcfg_get($perfilId, 'ia_hora_inicio', '6'),
    'hfim'       => (int) pcfg_get($perfilId, 'ia_hora_fim', '22'),
    'mtexto'     => (string) pcfg_get($perfilId, 'ia_modelo_texto', 'gpt-4o-mini'),
    'mimg'       => (string) pcfg_get($perfilId, 'ia_modelo_imagem', 'gpt-image-1'),
    'tam'        => (string) pcfg_get($perfilId, 'ia_tamanho_imagem', '1024x1536'),
    'qual'       => (string) pcfg_get($perfilId, 'ia_qualidade_imagem', 'medium'),
    'preco'      => (string) pcfg_get($perfilId, 'ia_preco_imagem_usd', '0.04'),
    'usdbrl'     => (string) pcfg_get($perfilId, 'ia_usd_brl', '5.40'),
    'modo'       => (string) pcfg_get($perfilId, 'ia_modo_geracao', 'codigo'),
    'fluxo'      => (string) pcfg_get($perfilId, 'ia_fluxo', 'curadoria'),
    'filtroIa'   => pcfg_get($perfilId, 'ia_filtro_ia', '1') === '1',
    'filtroIaMax'=> (int) pcfg_get($perfilId, 'ia_filtro_ia_max', '15'),
    'onibusAuto' => pcfg_get($perfilId, 'ia_onibus_auto', '1') === '1',
    'limMargem'  => (int) pcfg_get($perfilId, 'ia_limite_margem', '5'),
    'template'   => (int) pcfg_get($perfilId, 'ia_template', '1'),
    'cenario'    => (string) pcfg_get($perfilId, 'ia_cenario', 'onibus'),
    'handle'     => (string) pcfg_get($perfilId, 'ia_handle', '@tevinobuzao'),
    'distribuir' => pcfg_get($perfilId, 'ia_distribuir_dia', '1') === '1',
    'maxdia'     => (int) pcfg_get($perfilId, 'ia_max_dia', '0'),
    'minint'     => (int) pcfg_get($perfilId, 'ia_min_intervalo', '3'),
    'buffer'     => (int) pcfg_get($perfilId, 'ia_buffer', '6'),
    'recencia'   => (int) pcfg_get($perfilId, 'ia_recencia_h', '48'),
    'jini'       => (string) pcfg_get($perfilId, 'ia_janela_inicio', '05:00'),
    'jfim'       => (string) pcfg_get($perfilId, 'ia_janela_fim', '23:30'),
    'urgente'    => (string) pcfg_get($perfilId, 'ia_palavras_urgente', ''),
    'audio'      => pcfg_get($perfilId, 'ia_audio_ativo', '0') === '1',
    'audioArq'   => (string) pcfg_get($perfilId, 'ia_audio_arquivo', ''),
    'audioSeg'   => (int) pcfg_get($perfilId, 'ia_audio_seg', '8'),
    'bloquear'   => (string) pcfg_get($perfilId, 'ia_bloquear_palavras', ''),
    'exigirpal'  => (string) pcfg_get($perfilId, 'ia_exigir_palavras', ''),
    'exigirfoto' => pcfg_get($perfilId, 'ia_exigir_foto', '0') === '1',
];
$templatesArte = artec_templates();
$feedsTxt = '';
foreach (pcfg_feeds($perfilId) as $f) {
    $feedsTxt .= ($f['nome'] ?? '') . ' | ' . ($f['url'] ?? '') . "\n";
}
$temChave = OPENAI_API_KEY !== '';

$page_title = 'Configuração da IA';
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <div class="page-head">
    <div>
      <h1>Configuração — Notícias por IA</h1>
      <p>Configurações e fontes <b>por perfil</b> (cada conta tem o seu nicho).</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <?php if (count($perfis) > 1): ?>
        <select onchange="location.href='config_ia.php?perfil='+this.value" class="btn-ghost" style="padding:10px">
          <?php foreach ($perfis as $pf): ?>
            <option value="<?= (int) $pf['id'] ?>" <?= $perfilId === (int) $pf['id'] ? 'selected' : '' ?>><?= e($pf['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <a class="btn-ghost" href="aprovacao.php"><i class="fa-solid fa-clipboard-check"></i> Aprovação</a>
    </div>
  </div>
  <div class="aviso">Editando o perfil: <b><?= e((function () use ($perfis, $perfilId) { foreach ($perfis as $p) { if ((int) $p['id'] === $perfilId) { return $p['nome']; } } return '—'; })()) ?></b></div>

  <?php if ($flash): ?>
    <div class="aviso aviso--<?= e($flashTipo) ?>"><?= e($flash) ?></div>
  <?php endif; ?>

  <?php if (!$temChave): ?>
    <div class="aviso aviso--erro">
      <b>Chave da OpenAI ausente.</b> Adicione <code>OPENAI_API_KEY=sk-...</code> no arquivo
      <code>publishdev.com.br.env</code> (fora do webroot) para a geração funcionar.
    </div>
  <?php endif; ?>

  <form method="post" class="formbox" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="perfil" value="<?= $perfilId ?>">

    <div class="cfg-grid">
      <label class="switch-row">
        <input type="checkbox" name="ia_ativo" <?= $v['ativo'] ? 'checked' : '' ?>>
        <span><b>Automação ligada</b><small>Quando ligada, gera posts sozinha conforme as regras abaixo.</small></span>
      </label>

      <div class="field">
        <label>Conta (cliente) que vai receber os posts</label>
        <select name="ia_cliente_id">
          <option value="0">— selecione —</option>
          <?php foreach ($clientes as $c):
            $conectado = !empty($c['access_token']) && !empty($c['ig_user_id']); ?>
            <option value="<?= (int) $c['id'] ?>" <?= $v['cliente'] === (int) $c['id'] ? 'selected' : '' ?>>
              <?= e($c['nome']) ?><?= $c['ig_username'] ? ' (@' . e($c['ig_username']) . ')' : '' ?><?= $conectado ? '' : ' — não conectado' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label>Tipo de post</label>
        <select name="ia_tipo_post">
          <option value="feed"  <?= $v['tipo'] === 'feed'  ? 'selected' : '' ?>>Feed</option>
          <option value="story" <?= $v['tipo'] === 'story' ? 'selected' : '' ?>>Story</option>
          <option value="ambos" <?= $v['tipo'] === 'ambos' ? 'selected' : '' ?>>Feed e Story (os dois)</option>
        </select>
        <small class="hint">Story só publica com conta <b>Business</b> (token EAA…). "Os dois" cria um post de cada.</small>
      </div>

      <div class="field">
        <label>Gerar a cada (minutos) <small>— fluxo "aprovação" / sem distribuição</small></label>
        <input type="number" name="ia_intervalo_min" min="5" step="5" value="<?= e((string) $v['intervalo']) ?>">
        <small class="hint">240 = a cada 4 horas. No fluxo automático com distribuição, o intervalo é calculado sozinho.</small>
      </div>

      <div class="field field--row">
        <div>
          <label>Janela — início</label>
          <input type="time" name="ia_janela_inicio" value="<?= e($v['jini']) ?>">
        </div>
        <div>
          <label>Janela — fim</label>
          <input type="time" name="ia_janela_fim" value="<?= e($v['jfim']) ?>">
        </div>
      </div>
      <div class="field field--full">
        <small class="hint">Fora da janela só saem matérias <b>urgentes</b> (palavras-chave abaixo). Padrão 05:00–23:30.</small>
      </div>

      <div class="field">
        <label>Tamanho da imagem</label>
        <select name="ia_tamanho_imagem">
          <option value="1024x1536" <?= $v['tam'] === '1024x1536' ? 'selected' : '' ?>>Vertical 1024×1536 (stories/feed retrato)</option>
          <option value="1024x1024" <?= $v['tam'] === '1024x1024' ? 'selected' : '' ?>>Quadrado 1024×1024</option>
          <option value="1536x1024" <?= $v['tam'] === '1536x1024' ? 'selected' : '' ?>>Horizontal 1536×1024</option>
        </select>
      </div>

      <div class="field">
        <label>Qualidade da imagem</label>
        <select name="ia_qualidade_imagem">
          <option value="low"    <?= $v['qual'] === 'low'    ? 'selected' : '' ?>>Baixa (mais barato)</option>
          <option value="medium" <?= $v['qual'] === 'medium' ? 'selected' : '' ?>>Média</option>
          <option value="high"   <?= $v['qual'] === 'high'   ? 'selected' : '' ?>>Alta (mais caro)</option>
        </select>
      </div>

      <div class="field field--row">
        <div>
          <label>Modelo de imagem</label>
          <input type="text" name="ia_modelo_imagem" value="<?= e($v['mimg']) ?>">
        </div>
        <div>
          <label>Modelo de texto (legenda)</label>
          <input type="text" name="ia_modelo_texto" value="<?= e($v['mtexto']) ?>">
        </div>
      </div>

      <div class="field field--row">
        <div>
          <label>Custo por imagem (US$)</label>
          <input type="text" name="ia_preco_imagem_usd" value="<?= e($v['preco']) ?>">
          <small class="hint">Usado p/ estimar o custo. Ajuste conforme sua tabela OpenAI.</small>
        </div>
        <div>
          <label>Cotação US$ → R$</label>
          <input type="text" name="ia_usd_brl" value="<?= e($v['usdbrl']) ?>">
        </div>
      </div>

      <div class="field field--full">
        <label><i class="fa-solid fa-wand-magic-sparkles"></i> Automação orgânica (sem custo de IA)</label>
        <small class="hint">Gera a arte por código com a foto real da matéria. A OpenAI fica opcional.</small>
      </div>

      <div class="field">
        <label>Modo de geração da imagem</label>
        <select name="ia_modo_geracao">
          <option value="codigo" <?= $v['modo'] === 'codigo' ? 'selected' : '' ?>>Arte por código (orgânico, custo zero)</option>
          <option value="ia"     <?= $v['modo'] === 'ia'     ? 'selected' : '' ?>>IA / OpenAI (gera a cena, tem custo)</option>
        </select>
      </div>

      <div class="field">
        <label>Fluxo</label>
        <select name="ia_fluxo">
          <option value="curadoria" <?= $v['fluxo'] === 'curadoria' ? 'selected' : '' ?>>Curadoria — redator-chefe (você aprova na Mesa de Redação)</option>
          <option value="auto"      <?= $v['fluxo'] === 'auto'      ? 'selected' : '' ?>>Automático (agenda tudo sozinho, revisável em Agendados)</option>
          <option value="aprovacao" <?= $v['fluxo'] === 'aprovacao' ? 'selected' : '' ?>>Aprovação manual (1 rascunho por intervalo)</option>
        </select>
        <small class="hint"><b>Curadoria</b>: nada vai ao ar sem você aprovar — exceto <b>ônibus/transporte</b>, que posta sozinho.</small>
      </div>

      <div class="field">
        <label>Modelo de arte (template)</label>
        <select name="ia_template">
          <?php foreach ($templatesArte as $tid => $rot): ?>
            <option value="<?= (int) $tid ?>" <?= $v['template'] === (int) $tid ? 'selected' : '' ?>><?= e($tid . ' — ' . $rot) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="hint">Pré-visualizações salvas na pasta <code>modelos/</code>.</small>
      </div>

      <div class="field">
        <label>Marca (@) exibida na arte</label>
        <input type="text" name="ia_handle" value="<?= e($v['handle']) ?>">
      </div>

      <label class="switch-row">
        <input type="checkbox" name="ia_cenario" value="onibus" <?= $v['cenario'] !== 'off' ? 'checked' : '' ?>>
        <span><b>Cenário dentro do ônibus</b><small>Usa as fotos de <code>modelosdeimagensdentrodeonibus/</code> como fundo.</small></span>
      </label>

      <label class="switch-row">
        <input type="checkbox" name="ia_distribuir_dia" <?= $v['distribuir'] ? 'checked' : '' ?>>
        <span><b>Distribuir no dia</b><small>Espalha as matérias ao longo da janela de horário para postar tudo até o fim do dia.</small></span>
      </label>

      <div class="field field--row">
        <div>
          <label>Máximo de posts por dia (0 = sem limite)</label>
          <input type="number" name="ia_max_dia" min="0" max="1000" value="<?= e((string) $v['maxdia']) ?>">
        </div>
        <div>
          <label>Intervalo mínimo (min)</label>
          <input type="number" name="ia_min_intervalo" min="2" value="<?= e((string) $v['minint']) ?>">
        </div>
      </div>

      <div class="field">
        <label>Considerar notícias das últimas (horas)</label>
        <input type="number" name="ia_recencia_h" min="2" value="<?= e((string) $v['recencia']) ?>">
        <small class="hint">Só entram matérias recentes ("notícias do dia"). Padrão 48h.</small>
      </div>

      <div class="field field--full">
        <label>Palavras de URGÊNCIA (postam na hora, mesmo fora da janela)</label>
        <textarea name="ia_palavras_urgente" rows="2" placeholder="urgente, morre, acidente, tiroteio, preso"><?= e($v['urgente']) ?></textarea>
      </div>

      <div class="field">
        <label>Fila à frente (buffer)</label>
        <input type="number" name="ia_buffer" min="1" max="50" value="<?= e((string) $v['buffer']) ?>">
        <small class="hint">Quantos posts ficam agendados à frente, prontos para você revisar.</small>
      </div>

      <label class="switch-row">
        <input type="checkbox" name="ia_audio_ativo" <?= $v['audio'] ? 'checked' : '' ?>>
        <span><b>Áudio (vira vídeo)</b><small>Se ligado e houver trilha, a arte vira um MP4 com som (story/reels).</small></span>
      </label>

      <div class="field">
        <label>Duração do vídeo/story (segundos)</label>
        <input type="number" name="ia_audio_seg" min="5" max="15" value="<?= e((string) $v['audioSeg']) ?>">
        <small class="hint">Curto (ex.: 8s) evita que o seguidor fique pulando os stories.</small>
      </div>

      <div class="field field--full">
        <label>Trilha de áudio (mp3/m4a) — opcional</label>
        <input type="file" name="ia_audio" accept="audio/*">
        <?php if ($v['audioArq'] !== ''): ?>
          <small class="hint">Atual: <code><?= e($v['audioArq']) ?></code></small>
        <?php endif; ?>
      </div>

      <div class="field field--full">
        <label><i class="fa-solid fa-pen-nib"></i> Linha editorial (Mesa de Redação)</label>
        <small class="hint">Temas que entram na linha. O que não for de nenhum tema (atriz/ator global, novela, reality, futebol nacional, loteria, aleatório) é marcado como <b>fora da linha</b>.</small>
        <div class="ui-toggles">
          <?php foreach (temas_definicoes() as $tk => $td):
            $on = pcfg_get($perfilId, 'ia_tema_' . $tk, '1') === '1'; ?>
            <label class="switch-row switch-row--mini">
              <input type="checkbox" name="ia_tema_<?= e($tk) ?>" <?= $on ? 'checked' : '' ?>>
              <span><i class="fa-solid <?= e($td['icone']) ?>" style="color:<?= e($td['cor']) ?>"></i> <?= e($td['rotulo']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <label class="switch-row">
        <input type="checkbox" name="ia_onibus_auto" <?= $v['onibusAuto'] ? 'checked' : '' ?>>
        <span><b>Ônibus posta sozinho</b><small>Matérias de transporte público de Maceió vão ao ar sem aprovação (respeitando o teto de 40/dia).</small></span>
      </label>

      <label class="switch-row">
        <input type="checkbox" name="ia_filtro_ia" <?= $v['filtroIa'] ? 'checked' : '' ?>>
        <span><b>Filtro do filtro (IA nas dúvidas)</b><small>Só as matérias "em dúvida" passam por uma checagem da IA que confirma o tema. Custo baixo.</small></span>
      </label>

      <div class="field field--row">
        <div>
          <label>Máx. de checagens IA por ciclo</label>
          <input type="number" name="ia_filtro_ia_max" min="0" max="60" value="<?= e((string) $v['filtroIaMax']) ?>">
          <small class="hint">Limita o custo. 0 = nunca usa IA (só palavras).</small>
        </div>
        <div>
          <label>Margem do limite da Meta</label>
          <input type="number" name="ia_limite_margem" min="0" value="<?= e((string) $v['limMargem']) ?>">
          <small class="hint">Para de agendar quando faltam ≤ esta quantidade no limite de 24h (anti-bloqueio).</small>
        </div>
      </div>

      <div class="field field--full">
        <label><i class="fa-solid fa-filter"></i> Filtros de conteúdo (extra)</label>
        <small class="hint">Reforço opcional por palavra. A classificação por tema acima já é o filtro principal.</small>
      </div>

      <div class="field field--full">
        <label>Bloquear matérias que contenham (palavras separadas por vírgula)</label>
        <textarea name="ia_bloquear_palavras" rows="2" placeholder="morte, acidente, assassinato, política"><?= e($v['bloquear']) ?></textarea>
        <small class="hint">Se o título/resumo contiver qualquer uma dessas palavras, a matéria é ignorada.</small>
      </div>

      <div class="field field--full">
        <label>Só aceitar matérias que contenham (opcional — deixe vazio para aceitar todas)</label>
        <textarea name="ia_exigir_palavras" rows="2" placeholder="maceió, alagoas, arapiraca"><?= e($v['exigirpal']) ?></textarea>
        <small class="hint">Se preenchido, só passam matérias com pelo menos uma dessas palavras.</small>
      </div>

      <label class="switch-row">
        <input type="checkbox" name="ia_exigir_foto" <?= $v['exigirfoto'] ? 'checked' : '' ?>>
        <span><b>Só matérias com foto</b><small>Descarta notícias sem imagem real (verifica o RSS e o link da matéria).</small></span>
      </label>

      <div class="field field--full">
        <label>Fontes de notícia (RSS) — uma por linha, no formato <code>Nome | URL</code></label>
        <textarea name="ia_feeds" rows="5" placeholder="G1 Alagoas | https://g1.globo.com/rss/g1/al/alagoas/"><?= e(trim($feedsTxt)) ?></textarea>
      </div>

      <div class="field field--full">
        <label><i class="fa-solid fa-eye"></i> Seções visíveis no painel (Clientes)</label>
        <small class="hint">Desmarque para ocultar a seção em todo o painel. Marque para exibir.</small>
        <div class="ui-toggles">
          <?php foreach (ui_secoes() as $chave => [$rotulo, $padrao]): ?>
            <label class="switch-row switch-row--mini">
              <input type="checkbox" name="<?= e($chave) ?>" <?= ui_ver($chave) ? 'checked' : '' ?>>
              <span><?= e($rotulo) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" name="acao" value="salvar" class="btn-inline"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
      <button type="submit" name="acao" value="gerar_agora" class="btn-ghost"
              onclick="return confirm('Gerar 1 post agora (ignora o intervalo e consome a API)?');">
        <i class="fa-solid fa-bolt"></i> Gerar 1 agora (teste)
      </button>
    </div>
  </form>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
