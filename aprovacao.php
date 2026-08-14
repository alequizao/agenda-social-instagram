<?php
require __DIR__ . '/init.php';
exigir_login();

$flash = '';
$flashTipo = 'ok';

/* Converte datetime-local -> 'Y-m-d H:i:s' (fallback = agora). */
function aprov_quando(string $raw): string
{
    $dt = $raw !== '' ? str_replace('T', ' ', $raw) . ':00' : date('Y-m-d H:i:s');
    return strtotime($dt) === false ? date('Y-m-d H:i:s') : $dt;
}

/* Reprovou/excluiu um rascunho de IA -> devolve a noticia de origem para "nova"
   no painel de Noticias (some o link "Ver em Aprovacao"; volta o botao "Gerar post").
   O hash da noticia fica no JSON de ia_dados gravado na geracao do rascunho. */
function aprov_devolver_noticia(PDO $db, int $pubId): void
{
    $st = $db->prepare('SELECT ia_dados, cliente_id FROM ' . DB_PREFIX . 'publicacoes WHERE id=? LIMIT 1');
    $st->execute([$pubId]);
    $row = $st->fetch();
    if (!$row) {
        return;
    }
    $cid = (int) $row['cliente_id'];
    $dados = json_decode((string) $row['ia_dados'], true);
    $hash = is_array($dados) ? (string) ($dados['hash'] ?? '') : '';
    if ($hash === '') {
        return;
    }
    // volta a "nova" na caixa de descobertas (deste perfil) e libera p/ re-sugestao
    $db->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="nova" WHERE cliente_id=? AND hash=? AND status="gerada"')->execute([$cid, $hash]);
    $db->prepare('DELETE FROM ' . DB_PREFIX . 'noticias_usadas WHERE cliente_id=? AND hash=?')->execute([$cid, $hash]);
}

/* Apaga os arquivos de midia (disco) de uma publicacao. */
function aprov_apagar_arquivos(PDO $db, int $pubId): void
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

/* ---- POST: aprovar / confirmar / refazer / reprovar / excluir ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('CSRF invalido.');
    }
    $acao = (string) ($_POST['acao'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);

    // status atual deste rascunho de IA
    $st = db()->prepare('SELECT status FROM ' . DB_PREFIX . 'publicacoes WHERE id=? AND origem="ia" LIMIT 1');
    $st->execute([$id]);
    $statusAtual = (string) ($st->fetchColumn() ?: '');

    // ETAPA 1: aprova a manchete -> gera a imagem -> vai para REVISAO DA ARTE
    if ($acao === 'aprovar' && $statusAtual === 'aguardando_aprovacao') {
        $legenda = trim((string) ($_POST['legenda'] ?? ''));
        $dt = aprov_quando((string) ($_POST['agendado_para'] ?? ''));
        require_once __DIR__ . '/lib_gerador.php';
        $mid = gerar_midia_publicacao(db(), $id);
        if (!$mid['ok']) {
            db()->prepare('UPDATE ' . DB_PREFIX . 'publicacoes SET legenda=? WHERE id=?')->execute([$legenda, $id]);
            $flash = 'Não foi possível gerar a imagem: ' . $mid['erro'] . ' — ajuste e tente de novo.';
            $flashTipo = 'erro';
        } else {
            db()->prepare('UPDATE ' . DB_PREFIX . 'publicacoes
                SET status="revisao_arte", legenda=?, agendado_para=? WHERE id=?')->execute([$legenda, $dt, $id]);
            $flash = 'Imagem gerada! Revise a arte abaixo e confirme para agendar.';
        }

    // ETAPA 2a: confirma a arte -> AGENDA (vai ao ar no proximo ciclo)
    } elseif ($acao === 'confirmar' && $statusAtual === 'revisao_arte') {
        $legenda = trim((string) ($_POST['legenda'] ?? ''));
        $dt = aprov_quando((string) ($_POST['agendado_para'] ?? ''));
        db()->prepare('UPDATE ' . DB_PREFIX . 'publicacoes
            SET status="agendado", legenda=?, agendado_para=?, ig_container_id=NULL, erro_msg=NULL, tentativas=0
            WHERE id=?')->execute([$legenda, $dt, $id]);
        $flash = 'Confirmado e agendado para ' . date('d/m/Y H:i', strtotime($dt)) . '.';

    // ETAPA 2b: refaz a imagem (gera outra arte; consome a API de novo)
    } elseif ($acao === 'refazer' && $statusAtual === 'revisao_arte') {
        require_once __DIR__ . '/lib_gerador.php';
        aprov_apagar_arquivos(db(), $id);
        db()->prepare('DELETE FROM ' . DB_PREFIX . 'publicacao_midia WHERE publicacao_id=?')->execute([$id]);
        $mid = gerar_midia_publicacao(db(), $id);
        if (!$mid['ok']) {
            $flash = 'Não foi possível refazer a imagem: ' . $mid['erro'];
            $flashTipo = 'erro';
        } else {
            $flash = 'Nova imagem gerada. Revise a arte abaixo.';
        }

    } elseif ($acao === 'reprovar' && in_array($statusAtual, ['aguardando_aprovacao', 'revisao_arte'], true)) {
        aprov_devolver_noticia(db(), $id); // notícia volta a "nova" no painel de Notícias
        db()->prepare('UPDATE ' . DB_PREFIX . 'publicacoes SET status="reprovado" WHERE id=?')->execute([$id]);
        $flash = 'Rascunho reprovado.';
        $flashTipo = 'erro';

    } elseif ($acao === 'excluir' && in_array($statusAtual, ['aguardando_aprovacao', 'revisao_arte'], true)) {
        aprov_devolver_noticia(db(), $id); // notícia volta a "nova" no painel de Notícias
        aprov_apagar_arquivos(db(), $id);
        db()->prepare('DELETE FROM ' . DB_PREFIX . 'publicacoes WHERE id=?')->execute([$id]); // midia (linhas) cai por FK
        $flash = 'Rascunho excluído.';
        $flashTipo = 'erro';
    }

    // PRG: evita reenvio
    $_SESSION['flash'] = [$flash, $flashTipo];
    header('Location: ' . BASE_URL . '/aprovacao.php');
    exit;
}

if (!empty($_SESSION['flash'])) {
    [$flash, $flashTipo] = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ---- ETAPA 1: rascunhos aguardando aprovacao (sem imagem) ---- */
$rascunhos = db()->query(
    'SELECT p.id, p.cliente_id, p.tipo, p.legenda, p.fonte_nome, p.fonte_url, p.criado_em, p.ia_dados,
            c.nome AS cliente_nome
       FROM ' . DB_PREFIX . 'publicacoes p
       JOIN ' . DB_PREFIX . 'clientes c ON c.id = p.cliente_id
      WHERE p.status = "aguardando_aprovacao"
      ORDER BY p.criado_em DESC'
)->fetchAll();

/* ---- ETAPA 2: arte gerada aguardando confirmacao ---- */
$artes = db()->query(
    'SELECT p.id, p.cliente_id, p.tipo, p.legenda, p.fonte_nome, p.fonte_url, p.agendado_para, p.ia_dados,
            c.nome AS cliente_nome,
            (SELECT m.arquivo FROM ' . DB_PREFIX . 'publicacao_midia m WHERE m.publicacao_id = p.id ORDER BY m.posicao, m.id LIMIT 1) AS capa
       FROM ' . DB_PREFIX . 'publicacoes p
       JOIN ' . DB_PREFIX . 'clientes c ON c.id = p.cliente_id
      WHERE p.status = "revisao_arte"
      ORDER BY p.atualizado_em DESC'
)->fetchAll();

$agora = date('Y-m-d\TH:i');

$page_title = 'Aprovação de notícias';
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <div class="page-head">
    <div>
      <h1>Aprovação — Notícias por IA</h1>
      <p>Duas etapas: <b>1)</b> aprove a manchete (gera a imagem) → <b>2)</b> revise a arte gerada e confirme para postar. Nada vai ao ar sem você confirmar.</p>
    </div>
    <a class="btn-ghost" href="config_ia.php"><i class="fa-solid fa-robot"></i> Configuração</a>
  </div>

  <?php if ($flash): ?>
    <div class="aviso aviso--<?= e($flashTipo) ?>"><?= e($flash) ?></div>
  <?php endif; ?>

  <?php if (!$rascunhos && !$artes): ?>
    <div class="empty">
      <div class="empty__mark"><i class="fa-solid fa-mug-hot"></i></div>
      <h2>Nenhum rascunho aguardando</h2>
      <p>Quando a IA gerar um post, ele aparece aqui para aprovação. Você pode forçar uma geração em
        <a href="config_ia.php">Configuração → Gerar 1 agora</a>.</p>
    </div>
  <?php endif; ?>

  <?php if ($artes): ?>
    <h2 class="sec-titulo"><i class="fa-solid fa-wand-magic-sparkles"></i> Revisar a arte gerada <span class="sec-cont"><?= count($artes) ?></span></h2>
    <div class="aprov-list">
      <?php foreach ($artes as $p):
        $dados = json_decode((string) ($p['ia_dados'] ?? ''), true);
        $manchete = is_array($dados) ? (string) ($dados['titulo'] ?? '') : '';
        $whenVal = !empty($p['agendado_para']) ? date('Y-m-d\TH:i', strtotime((string) $p['agendado_para'])) : $agora;
      ?>
        <div class="aprov-card aprov-card--arte">
          <div class="aprov-card__art">
            <?php if ($p['capa'] && is_file(__DIR__ . '/' . $p['capa'])): ?>
              <img src="<?= e(asset_v($p['capa'])) ?>" alt="Arte gerada por IA">
              <span class="aprov-card__badge aprov-card__badge--ok"><i class="fa-solid fa-robot"></i> arte gerada por IA</span>
            <?php else: ?>
              <div class="aprov-card__noimg"><i class="fa-solid fa-triangle-exclamation"></i><small>arte não encontrada — refaça</small></div>
            <?php endif; ?>
          </div>

          <form method="post" class="aprov-card__body">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">

            <div class="aprov-card__meta">
              <span class="tag tag--ia"><i class="fa-solid fa-robot"></i> IA</span>
              <span class="tag"><?= e(rotulo_tipo($p['tipo'])) ?></span>
              <span class="aprov-card__cli"><i class="fa-solid fa-building"></i> <?= e($p['cliente_nome']) ?></span>
            </div>

            <?php if ($manchete !== ''): ?>
              <h3 class="aprov-card__titulo"><?= e($manchete) ?></h3>
            <?php endif; ?>

            <?php if ($p['tipo'] === 'story'): ?>
              <p class="hint"><i class="fa-solid fa-circle-info"></i> Story não usa legenda.</p>
            <?php else: ?>
              <label class="mini">Legenda (edite se quiser)</label>
              <textarea name="legenda" rows="6"><?= e($p['legenda']) ?></textarea>
            <?php endif; ?>

            <div class="aprov-card__fonte">
              <i class="fa-solid fa-link"></i>
              <?php if (!empty($p['fonte_url'])): ?>
                Fonte: <a href="<?= e($p['fonte_url']) ?>" target="_blank" rel="noopener"><?= e($p['fonte_nome'] ?: 'abrir') ?></a>
              <?php else: ?>
                Fonte: <?= e($p['fonte_nome'] ?: '—') ?>
              <?php endif; ?>
            </div>

            <div class="aprov-card__when">
              <label class="mini">Publicar em</label>
              <input type="datetime-local" name="agendado_para" value="<?= e($whenVal) ?>">
              <small class="hint">Deixe o horário atual para publicar no próximo ciclo.</small>
            </div>

            <div class="aprov-card__acoes">
              <button type="submit" name="acao" value="confirmar" class="btn-inline"><i class="fa-solid fa-paper-plane"></i> Confirmar e agendar</button>
              <button type="submit" name="acao" value="refazer" class="btn-ghost"
                      onclick="return confirm('Refazer a imagem? Gera uma nova arte e consome a API de novo.');"><i class="fa-solid fa-rotate"></i> Refazer imagem</button>
              <?php if ($p['capa'] && is_file(__DIR__ . '/' . $p['capa'])): ?>
                <a href="<?= e(asset_v($p['capa'])) ?>" download="arte-<?= (int) $p['id'] ?>" class="btn-ghost" style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center;"><i class="fa-solid fa-download"></i> Baixar</a>
              <?php endif; ?>
              <button type="submit" name="acao" value="reprovar" class="btn-ghost"><i class="fa-solid fa-xmark"></i> Reprovar</button>
              <button type="submit" name="acao" value="excluir" class="btn-ghost btn-ghost--del"
                      onclick="return confirm('Excluir este rascunho de vez?');"><i class="fa-solid fa-trash"></i></button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($rascunhos): ?>
    <h2 class="sec-titulo"><i class="fa-solid fa-clipboard-check"></i> Aprovar a manchete (gera a imagem) <span class="sec-cont"><?= count($rascunhos) ?></span></h2>
    <div class="aprov-list">
      <?php foreach ($rascunhos as $p):
        $dados = json_decode((string) ($p['ia_dados'] ?? ''), true);
        $manchete = is_array($dados) ? (string) ($dados['titulo'] ?? '') : '';
        $preview  = is_array($dados) ? (string) ($dados['imagem'] ?? '') : '';
      ?>
        <div class="aprov-card">
          <div class="aprov-card__art">
            <?php if ($preview !== ''): ?>
              <img src="<?= e($preview) ?>" alt="Prévia da foto da fonte" loading="lazy">
              <span class="aprov-card__badge"><i class="fa-solid fa-wand-magic-sparkles"></i> arte gerada ao aprovar</span>
            <?php else: ?>
              <div class="aprov-card__noimg"><i class="fa-solid fa-image"></i><small>arte gerada ao aprovar</small></div>
            <?php endif; ?>
          </div>

          <form method="post" class="aprov-card__body">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">

            <div class="aprov-card__meta">
              <span class="tag tag--ia"><i class="fa-solid fa-robot"></i> IA</span>
              <span class="tag"><?= e(rotulo_tipo($p['tipo'])) ?></span>
              <span class="aprov-card__cli"><i class="fa-solid fa-building"></i> <?= e($p['cliente_nome']) ?></span>
            </div>

            <?php if ($manchete !== ''): ?>
              <h3 class="aprov-card__titulo"><?= e($manchete) ?></h3>
            <?php endif; ?>

            <?php if ($p['tipo'] === 'story'): ?>
              <p class="hint"><i class="fa-solid fa-circle-info"></i> Story não usa legenda.</p>
            <?php else: ?>
              <label class="mini">Legenda (edite se quiser)</label>
              <textarea name="legenda" rows="6"><?= e($p['legenda']) ?></textarea>
            <?php endif; ?>

            <div class="aprov-card__fonte">
              <i class="fa-solid fa-link"></i>
              <?php if (!empty($p['fonte_url'])): ?>
                Fonte: <a href="<?= e($p['fonte_url']) ?>" target="_blank" rel="noopener"><?= e($p['fonte_nome'] ?: 'abrir') ?></a>
              <?php else: ?>
                Fonte: <?= e($p['fonte_nome'] ?: '—') ?>
              <?php endif; ?>
            </div>

            <div class="aprov-card__when">
              <label class="mini">Publicar em</label>
              <input type="datetime-local" name="agendado_para" value="<?= e($agora) ?>">
              <small class="hint">Ao aprovar, a imagem é gerada na hora (consome a API). Você ainda confirma a arte antes de postar.</small>
            </div>

            <div class="aprov-card__acoes">
              <button type="submit" name="acao" value="aprovar" class="btn-inline"
                      onclick="return confirm('Aprovar a manchete? Isso gera a imagem agora (consome a API). Você ainda revisa a arte antes de postar.');"><i class="fa-solid fa-check"></i> Aprovar (gera imagem)</button>
              <button type="submit" name="acao" value="reprovar" class="btn-ghost"><i class="fa-solid fa-xmark"></i> Reprovar</button>
              <button type="submit" name="acao" value="excluir" class="btn-ghost btn-ghost--del"
                      onclick="return confirm('Excluir este rascunho de vez?');"><i class="fa-solid fa-trash"></i></button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
