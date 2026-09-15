<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require __DIR__ . '/init.php';
exigir_login();
require __DIR__ . '/lib_noticias.php';
require __DIR__ . '/lib_temas.php';

$flash = '';
$flashTipo = 'ok';

/* perfil selecionado (multi-perfil) */
$perfis = db()->query('SELECT id, nome FROM ' . DB_PREFIX . 'clientes ORDER BY nome')->fetchAll();
$cliente = (int) ($_GET['perfil'] ?? $_POST['perfil'] ?? 0);
if ($cliente <= 0) {
    $cliente = (int) (db()->query('SELECT cliente_id FROM ' . DB_PREFIX . 'config_perfil WHERE chave="ia_ativo" AND valor="1" ORDER BY cliente_id LIMIT 1')->fetchColumn() ?: cfg_get('ia_cliente_id', '0'));
    if ($cliente <= 0 && $perfis) {
        $cliente = (int) $perfis[0]['id'];
    }
}

/* Resumo "sujo" de feed (None/null) vira vazio. */
function md_resumo(?string $s): string
{
    $s = trim((string) $s);
    return in_array(mb_strtolower($s), ['none', 'null', 'undefined', 'false'], true) ? '' : $s;
}

/* ---- POST: ações do redator-chefe ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('CSRF invalido.');
    }
    $acao = (string) ($_POST['acao'] ?? '');
    $hash = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['hash'] ?? ''));

    if ($acao === 'atualizar') {
        $n = noticias_sincronizar(db(), $cliente);
        pcfg_set($cliente, 'noticias_sync_em', date('Y-m-d H:i:s'));
        $cl = temas_classificar_pendentes(db(), $cliente, 300);
        $flash = ($n > 0 ? "{$n} nova(s). " : 'Sem novidades. ') . "Classificadas {$cl['classificadas']} (IA {$cl['ia']}).";

    } elseif ($acao === 'reclassificar') {
        db()->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET veredito=NULL WHERE cliente_id=? AND status="nova"')->execute([$cliente]);
        $usaIA = (string) pcfg_get($cliente, 'ia_filtro_ia', '1');
        pcfg_set($cliente, 'ia_filtro_ia', '0'); // reclass em massa = só palavras (sem custo)
        $cl = temas_classificar_pendentes(db(), $cliente, 5000);
        pcfg_set($cliente, 'ia_filtro_ia', $usaIA);
        $flash = "Reclassificadas {$cl['classificadas']} matérias pela linha editorial.";

    } elseif ($acao === 'limpar') {
        $st = db()->prepare('DELETE FROM ' . DB_PREFIX . 'noticias_descobertas WHERE cliente_id=? AND status NOT IN ("gerada","aprovada")');
        $st->execute([$cliente]);
        $flash = 'Lista limpa: ' . (int) $st->rowCount() . ' removida(s). Aprovadas e geradas foram mantidas.';

    } elseif ($acao === 'aprovar' && $hash !== '') {
        $row = db()->prepare('SELECT titulo, fonte, link, imagem, resumo, data_pub, hash FROM ' . DB_PREFIX . 'noticias_descobertas WHERE cliente_id=? AND hash=? AND status="nova" LIMIT 1');
        $row->execute([$cliente, $hash]);
        $nr = $row->fetch();
        if (!$nr) {
            $flash = 'Matéria não encontrada (ou já tratada).';
            $flashTipo = 'erro';
        } else {
            require_once __DIR__ . '/lib_agendador.php';
            $noticia = [
                'titulo' => (string) $nr['titulo'], 'fonte' => (string) $nr['fonte'],
                'link' => (string) $nr['link'], 'imagem' => (string) $nr['imagem'],
                'resumo' => (string) $nr['resumo'], 'data' => (string) $nr['data_pub'], 'hash' => (string) $nr['hash'],
            ];
            $r = curadoria_agendar_uma(db(), $cliente, $noticia);
            if ($r['ok']) {
                $flash = 'Aprovada e já criada em Agendados! A arte vai com áudio (finalizado em até 1 min).';
            } else {
                // sem vaga agora (teto/limite) ou filtro: deixa aprovada p/ o cron criar quando liberar
                db()->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="aprovada" WHERE cliente_id=? AND hash=? AND status="nova"')->execute([$cliente, $hash]);
                $flash = $r['motivo'] === 'sem_vaga'
                    ? 'Aprovada. Sem vaga agora (teto 40/dia ou limite da Meta) — entra em Agendados assim que liberar.'
                    : 'Aprovada. Será criada no próximo ciclo (' . e((string) ($r['motivo'] ?? '')) . ').';
            }
        }

    } elseif ($acao === 'recusar' && $hash !== '') {
        db()->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="ignorada" WHERE cliente_id=? AND hash=? AND status IN ("nova","aprovada")')->execute([$cliente, $hash]);
        $flash = 'Matéria recusada.';

    } elseif ($acao === 'desfazer' && $hash !== '') {
        db()->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="nova" WHERE cliente_id=? AND hash=? AND status="aprovada"')->execute([$cliente, $hash]);
        $flash = 'Aprovação desfeita.';

    } elseif ($acao === 'restaurar' && $hash !== '') {
        db()->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="nova" WHERE cliente_id=? AND hash=? AND status="ignorada"')->execute([$cliente, $hash]);
        $flash = 'Matéria restaurada.';

    } elseif ($acao === 'aprovar_todas') {
        $st = db()->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="aprovada"
            WHERE cliente_id=? AND status="nova" AND veredito="na_linha" AND auto=0');
        $st->execute([$cliente]);
        $flash = (int) $st->rowCount() . ' matéria(s) da linha aprovada(s) para hoje.';
    }
    $_SESSION['flash'] = [$flash, $flashTipo];
    header('Location: ' . BASE_URL . '/noticias.php?perfil=' . $cliente . '&aba=' . urlencode((string) ($_GET['aba'] ?? $_POST['aba_atual'] ?? 'linha')));
    exit;
}

if (!empty($_SESSION['flash'])) {
    [$flash, $flashTipo] = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ---- auto-sync + classificação (a cada 5 min) ----
   NÃO roda mais no caminho crítico do load (RSS em série + IA travavam a página:
   causa de 500/504 — auditoria 22/06). Marca aqui e executa APÓS enviar a resposta
   (fastcgi_finish_request no fim do arquivo). Os itens novos aparecem no próximo load.
   Perfis com automação ligada nem precisam: o cron_noticias sincroniza a cada minuto. */
$ultimaSync = (string) pcfg_get($cliente, 'noticias_sync_em', '');
$syncDepois = $cliente > 0 && ($ultimaSync === '' || (time() - strtotime($ultimaSync)) > 300);

/* ---- abas ---- */
$defs = temas_definicoes();
$aba = (string) ($_GET['aba'] ?? 'linha');
$recente = 'COALESCE(data_pub_dt, descoberta_em) >= ' . db()->quote(date('Y-m-d H:i:s', time() - 96 * 3600));

if ($aba === 'aprovadas') {
    $where = 'cliente_id=? AND status="aprovada"';
} elseif ($aba === 'fora') {
    $where = 'cliente_id=? AND status="nova" AND veredito="fora" AND ' . $recente;
} elseif ($aba === 'revisar') {
    $where = 'cliente_id=? AND status="nova" AND veredito="duvida" AND ' . $recente;
} elseif ($aba === 'todas') {
    $where = 'cliente_id=? AND ' . $recente;
} elseif (isset($defs[$aba])) {
    $where = 'cliente_id=? AND status IN ("nova","aprovada") AND tema=' . db()->quote($aba) . ' AND ' . $recente;
} else {
    $aba = 'linha';
    $where = 'cliente_id=? AND status="nova" AND veredito="na_linha" AND ' . $recente;
}

$st = db()->prepare('SELECT hash, titulo, link, fonte, imagem, resumo, data_pub, data_pub_dt, status, descoberta_em,
        tema, veredito, motivo, auto, fonte_filtro
    FROM ' . DB_PREFIX . 'noticias_descobertas
    WHERE ' . $where . '
    ORDER BY (status="aprovada") DESC, COALESCE(data_pub_dt, descoberta_em) DESC, id DESC
    LIMIT 400');
$st->execute([$cliente]);
$itens = $st->fetchAll();

/* contadores por aba */
$cnt = ['linha' => 0, 'revisar' => 0, 'fora' => 0, 'aprovadas' => 0];
foreach ($defs as $t => $_) { $cnt[$t] = 0; }
$g = db()->prepare('SELECT status, veredito, tema, COUNT(*) c FROM ' . DB_PREFIX . 'noticias_descobertas
    WHERE cliente_id=? AND (status IN ("nova","aprovada")) AND ' . $recente . ' GROUP BY status, veredito, tema');
$g->execute([$cliente]);
foreach ($g->fetchAll() as $r) {
    if ($r['status'] === 'aprovada') { $cnt['aprovadas'] += (int) $r['c']; continue; }
    if ($r['veredito'] === 'na_linha') { $cnt['linha'] += (int) $r['c']; }
    if ($r['veredito'] === 'duvida') { $cnt['revisar'] += (int) $r['c']; }
    if ($r['veredito'] === 'fora') { $cnt['fora'] += (int) $r['c']; }
    if (!empty($r['tema']) && isset($cnt[$r['tema']])) { $cnt[$r['tema']] += (int) $r['c']; }
}

/* ---- painel de limites (anti-bloqueio) ---- */
require_once __DIR__ . '/lib_agendador.php';
$cap = (int) cfg_get('pub_cap_dia', '40');
$pubHoje = agendador_pub_hoje(db(), $cliente);
$lim = pub_limite_status($cliente);

$page_title = 'Mesa de Redação';
require __DIR__ . '/partials/head.php';
?>
<style>
.mesa-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:center;background:var(--cor-card,#fff);
  border:1px solid rgba(0,0,0,.07);border-radius:14px;padding:12px 16px;margin-bottom:14px}
.mesa-bar .kpi{display:flex;flex-direction:column;line-height:1.15}
.mesa-bar .kpi b{font-size:18px;font-variant-numeric:tabular-nums}
.mesa-bar .kpi small{color:var(--cor-texto-sec,#64748b);font-size:11px;text-transform:uppercase;letter-spacing:.4px}
.mesa-bar .sep{width:1px;align-self:stretch;background:rgba(0,0,0,.08)}
.mesa-bar .meta-ok{color:#16a34a}.mesa-bar .meta-warn{color:#d97706}.mesa-bar .meta-bad{color:#dc2626}
.tema-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
  padding:2px 9px;border-radius:999px;color:#fff}
.vd{font-size:11px;font-weight:600;color:var(--cor-texto-sec,#64748b)}
.vd--duvida{color:#d97706}.vd--fora{color:#94a3b8}
.news-item--aprovada{outline:2px solid #16a34a33}
.btn-aprovar{background:#16a34a;color:#fff;border:none;cursor:pointer;padding:8px 14px;border-radius:9px;font-weight:700;font-size:13px;font-family:inherit}
.btn-aprovar:hover{background:#15803d}
.chip-auto{background:#0ea5e9;color:#fff;font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px}
</style>
<main class="wrap">
  <div class="page-head">
    <div>
      <h1><i class="fa-solid fa-pen-nib"></i> Mesa de Redação</h1>
      <p>Você é o <b>redator-chefe</b>. As matérias chegam filtradas por tema; aprove as que vão ao ar hoje. <b>Ônibus/transporte de Maceió posta sozinho.</b></p>
    </div>
    <div style="display:flex;gap:8px;margin:0;align-items:center;flex-wrap:wrap">
      <?php if (count($perfis) > 1): ?>
        <select onchange="location.href='noticias.php?perfil='+this.value" class="btn-ghost" style="padding:10px">
          <?php foreach ($perfis as $pf): ?>
            <option value="<?= (int) $pf['id'] ?>" <?= $cliente === (int) $pf['id'] ? 'selected' : '' ?>><?= e($pf['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="perfil" value="<?= $cliente ?>">
        <button type="submit" name="acao" value="atualizar" class="btn-ghost"><i class="fa-solid fa-rotate"></i> Buscar agora</button>
      </form>
      <form method="post" style="margin:0" onsubmit="return confirm('Reclassificar todas as matérias pela linha editorial atual?');"><?= csrf_field() ?><input type="hidden" name="perfil" value="<?= $cliente ?>">
        <button type="submit" name="acao" value="reclassificar" class="btn-ghost"><i class="fa-solid fa-filter"></i> Reclassificar</button>
      </form>
    </div>
  </div>

  <div class="mesa-bar">
    <div class="kpi"><b><?= (int) $pubHoje ?> / <?= (int) $cap ?></b><small>Posts hoje (teto)</small></div>
    <div class="sep"></div>
    <?php if (!empty($lim['ok'])):
        $rest = (int) $lim['restante']; $tot = (int) $lim['total'];
        $cls = $rest <= 10 ? 'meta-bad' : ($rest <= 25 ? 'meta-warn' : 'meta-ok'); ?>
      <div class="kpi"><b class="<?= $cls ?>"><?= (int) $lim['usado'] ?> / <?= $tot ?></b><small>Limite Meta (24h)</small></div>
      <div class="kpi"><b class="<?= $cls ?>"><?= $rest ?></b><small>Restam p/ não bloquear</small></div>
    <?php else: ?>
      <div class="kpi"><b style="color:#94a3b8">—</b><small>Limite Meta (sem token)</small></div>
    <?php endif; ?>
    <div class="sep"></div>
    <div class="kpi"><b style="color:#16a34a"><?= (int) $cnt['aprovadas'] ?></b><small>Aprovadas p/ hoje</small></div>
    <div class="kpi"><b style="color:#0ea5e9"><?= (int) $cnt['linha'] ?></b><small>Na linha (revisar)</small></div>
  </div>

  <?php if ($flash): ?><div class="aviso aviso--<?= e($flashTipo) ?>"><?= e($flash) ?></div><?php endif; ?>

  <div class="filtros" style="flex-wrap:wrap;gap:6px">
    <a class="chip-f<?= $aba === 'linha' ? ' is-on' : '' ?>" href="?perfil=<?= $cliente ?>&aba=linha">⭐ Da linha<?= $cnt['linha'] ? ' (' . $cnt['linha'] . ')' : '' ?></a>
    <?php foreach ($defs as $t => $d): if (!in_array($t, temas_ativos($cliente), true)) continue; ?>
      <a class="chip-f<?= $aba === $t ? ' is-on' : '' ?>" href="?perfil=<?= $cliente ?>&aba=<?= e($t) ?>"><?= e($d['rotulo']) ?><?= !empty($cnt[$t]) ? ' (' . $cnt[$t] . ')' : '' ?></a>
    <?php endforeach; ?>
    <a class="chip-f<?= $aba === 'revisar' ? ' is-on' : '' ?>" href="?perfil=<?= $cliente ?>&aba=revisar">🔎 Revisar<?= $cnt['revisar'] ? ' (' . $cnt['revisar'] . ')' : '' ?></a>
    <a class="chip-f<?= $aba === 'aprovadas' ? ' is-on' : '' ?>" href="?perfil=<?= $cliente ?>&aba=aprovadas">✅ Aprovadas<?= $cnt['aprovadas'] ? ' (' . $cnt['aprovadas'] . ')' : '' ?></a>
    <a class="chip-f<?= $aba === 'fora' ? ' is-on' : '' ?>" href="?perfil=<?= $cliente ?>&aba=fora">🚫 Fora da linha<?= $cnt['fora'] ? ' (' . $cnt['fora'] . ')' : '' ?></a>
    <a class="chip-f<?= $aba === 'todas' ? ' is-on' : '' ?>" href="?perfil=<?= $cliente ?>&aba=todas">Todas</a>
    <span class="filtros__info"><?= count($itens) ?> matéria(s) · att <?= e(date('H:i', strtotime($ultimaSync ?: 'now'))) ?></span>
  </div>

  <?php if ($aba === 'linha' && $cnt['linha'] > 0): ?>
    <form method="post" style="margin:0 0 12px" onsubmit="return confirm('Aprovar TODAS as <?= $cnt['linha'] ?> matérias da linha para hoje?');">
      <?= csrf_field() ?><input type="hidden" name="perfil" value="<?= $cliente ?>"><input type="hidden" name="aba_atual" value="linha">
      <button type="submit" name="acao" value="aprovar_todas" class="btn-aprovar"><i class="fa-solid fa-check-double"></i> Aprovar todas da linha (<?= $cnt['linha'] ?>)</button>
    </form>
  <?php endif; ?>

  <?php if (!$itens): ?>
    <div class="empty"><div class="empty__mark"><i class="fa-solid fa-newspaper"></i></div>
      <h2>Nada nesta aba</h2><p>Clique em <b>Buscar agora</b> ou veja outra aba.</p></div>
  <?php else: ?>
    <div class="news-list">
      <?php foreach ($itens as $n):
        $tema = (string) $n['tema'];
        $d = $defs[$tema] ?? null;
        $resumo = md_resumo($n['resumo']);
        $aprovada = $n['status'] === 'aprovada';
        $ehAuto = (int) $n['auto'] === 1; ?>
        <div class="news-item<?= $aprovada ? ' news-item--aprovada' : '' ?>">
          <div class="news-item__thumb">
            <?php if (!empty($n['imagem'])): ?>
              <img src="<?= e($n['imagem']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
            <?php else: ?><span class="news-item__noimg"><i class="fa-solid fa-image"></i></span><?php endif; ?>
          </div>
          <div class="news-item__body">
            <div class="news-item__meta" style="gap:8px;align-items:center">
              <?php if ($d): ?><span class="tema-badge" style="background:<?= e($d['cor']) ?>"><i class="fa-solid <?= e($d['icone']) ?>"></i> <?= e($d['rotulo']) ?></span><?php endif; ?>
              <?php if ($ehAuto): ?><span class="chip-auto"><i class="fa-solid fa-bolt"></i> posta sozinho</span><?php endif; ?>
              <?php if ($aprovada): ?><span class="tag tag--ok"><i class="fa-solid fa-check"></i> aprovada</span><?php endif; ?>
              <span class="vd vd--<?= e((string) $n['veredito']) ?>"><?= e((string) $n['motivo']) ?><?= $n['fonte_filtro'] === 'ia' ? ' · IA' : '' ?></span>
            </div>
            <h3 class="news-item__titulo">
              <?php if (!empty($n['link'])): ?><a href="<?= e($n['link']) ?>" target="_blank" rel="noopener"><?= e($n['titulo']) ?></a>
              <?php else: ?><?= e($n['titulo']) ?><?php endif; ?>
            </h3>
            <div class="news-item__meta">
              <span class="news-item__fonte"><i class="fa-solid fa-building-columns"></i> <?= e($n['fonte'] ?: '—') ?></span>
              <?php $dm = noticias_data_fmt((string) $n['data_pub']); ?>
              <span class="news-item__time"><i class="fa-regular fa-clock"></i> <?= $dm !== '' ? 'Publicada ' . e($dm) : 'Encontrada ' . e(noticias_data_fmt((string) $n['descoberta_em'], 'd/m/Y H:i')) ?></span>
            </div>
            <?php if ($resumo !== ''): ?><p class="news-item__resumo"><?= e($resumo) ?></p><?php endif; ?>

            <form method="post" class="news-item__acoes" style="gap:8px">
              <?= csrf_field() ?>
              <input type="hidden" name="perfil" value="<?= $cliente ?>">
              <input type="hidden" name="aba_atual" value="<?= e($aba) ?>">
              <input type="hidden" name="hash" value="<?= e($n['hash']) ?>">
              <?php if ($aprovada): ?>
                <button type="submit" name="acao" value="desfazer" class="btn-ghost"><i class="fa-solid fa-rotate-left"></i> Desfazer</button>
                <button type="submit" name="acao" value="recusar" class="btn-ghost"><i class="fa-solid fa-xmark"></i> Recusar</button>
              <?php elseif ($ehAuto): ?>
                <span class="btn-ghost" style="cursor:default"><i class="fa-solid fa-circle-check"></i> Vai ao ar automático</span>
                <button type="submit" name="acao" value="recusar" class="btn-ghost"><i class="fa-solid fa-ban"></i> Barrar esta</button>
              <?php else: ?>
                <button type="submit" name="acao" value="aprovar" class="btn-aprovar"><i class="fa-solid fa-check"></i> Aprovar p/ hoje</button>
                <button type="submit" name="acao" value="recusar" class="btn-ghost"><i class="fa-solid fa-xmark"></i> Recusar</button>
              <?php endif; ?>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
<?php
/* ---- Trabalho pesado DEPOIS da resposta (usuário não espera o RSS/IA) ---- */
if (!empty($syncDepois)) {
    ignore_user_abort(true);
    session_write_close();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request(); // navegador já recebeu a página
    }
    set_time_limit(120);
    try {
        noticias_sincronizar(db(), $cliente);
        pcfg_set($cliente, 'noticias_sync_em', date('Y-m-d H:i:s'));
        temas_classificar_pendentes(db(), $cliente, 200);
    } catch (Throwable $e) {
        error_log('noticias auto-sync pos-resposta: ' . $e->getMessage());
    }
}
?>
