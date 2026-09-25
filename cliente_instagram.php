<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require __DIR__ . '/init.php';
exigir_login();

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM ' . DB_PREFIX . 'clientes WHERE id = ? LIMIT 1');
$st->execute([$id]);
$c = $st->fetch();
if (!$c) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$erro = '';
$ok = '';
$contas = [];          // contas IG encontradas para escolher
$tokenDigitado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $erro = 'Sessao expirada. Recarregue a pagina.';
    } else {
        $acao = (string) ($_POST['acao'] ?? '');

        if ($acao === 'desconectar') {
            $up = db()->prepare('UPDATE ' . DB_PREFIX . 'clientes
                SET access_token=NULL, ig_user_id=NULL, fb_page_id=NULL, token_expira_em=NULL, conectado_em=NULL WHERE id=?');
            $up->execute([$id]);
            header('Location: ' . BASE_URL . '/cliente_instagram.php?id=' . $id);
            exit;
        }

        if ($acao === 'buscar') {
            $tokenDigitado = trim((string) ($_POST['token'] ?? ''));
            if ($tokenDigitado === '') {
                $erro = 'Cole o token de acesso da Meta.';
            } elseif (stripos($tokenDigitado, 'IG') === 0) {
                // Token "Instagram API com Login do Instagram" (graph.instagram.com)
                $r = graph_get('me', [
                    'fields'       => 'user_id,username,account_type,profile_picture_url',
                    'access_token' => $tokenDigitado,
                ]);
                if (!$r['ok']) {
                    $erro = $r['erro'];
                } else {
                    $d = $r['dados'];
                    $tipo = (string) ($d['account_type'] ?? '');
                    if ($tipo !== '' && !in_array($tipo, ['BUSINESS', 'MEDIA_CREATOR', 'CREATOR'], true)) {
                        $erro = 'A conta precisa ser Comercial ou Criador para publicar (tipo atual: ' . e($tipo) . ').';
                    } else {
                        $contas[] = [
                            'page_id'    => '',
                            'page_name'  => 'Instagram Login' . ($tipo ? ' (' . $tipo . ')' : ''),
                            'page_token' => $tokenDigitado,
                            'ig_id'      => (string) ($d['user_id'] ?? ''),
                            'ig_user'    => (string) ($d['username'] ?? ''),
                            'ig_foto'    => (string) ($d['profile_picture_url'] ?? ''),
                        ];
                        if ($contas[0]['ig_id'] === '') {
                            $erro = 'Nao foi possivel ler o ID da conta. Gere o token novamente.';
                            $contas = [];
                        }
                    }
                }
            } else {
                // Token "Facebook Login" (graph.facebook.com): descobre Paginas + conta IG
                $r = graph_get('me/accounts', [
                    'fields'       => 'name,access_token,instagram_business_account{id,username,profile_picture_url}',
                    'access_token' => $tokenDigitado,
                    'limit'        => 100,
                ]);
                if (!$r['ok']) {
                    $erro = $r['erro'];
                } else {
                    foreach ($r['dados']['data'] ?? [] as $pg) {
                        if (!empty($pg['instagram_business_account']['id'])) {
                            $ig = $pg['instagram_business_account'];
                            $contas[] = [
                                'page_id'   => $pg['id'],
                                'page_name' => $pg['name'] ?? '',
                                'page_token' => $pg['access_token'] ?? $tokenDigitado,
                                'ig_id'     => $ig['id'],
                                'ig_user'   => $ig['username'] ?? '',
                                'ig_foto'   => $ig['profile_picture_url'] ?? '',
                            ];
                        }
                    }
                    if (!$contas) {
                        $erro = 'Nenhuma conta Instagram Profissional vinculada a uma Pagina foi encontrada neste token. '
                              . 'Verifique se a conta IG e Comercial/Criador e esta ligada a uma Pagina do Facebook.';
                    }
                }
            }
        }

        if ($acao === 'conectar') {
            $pageToken = trim((string) ($_POST['page_token'] ?? ''));
            $igId      = trim((string) ($_POST['ig_id'] ?? ''));
            $igUser    = ltrim(trim((string) ($_POST['ig_user'] ?? '')), '@');
            $pageId    = trim((string) ($_POST['page_id'] ?? ''));
            if ($pageToken === '' || $igId === '') {
                $erro = 'Dados da conta incompletos. Busque novamente.';
            } else {
                // Token IG curto -> tenta trocar por um de longa duracao (60 dias)
                $expira = null;
                if (stripos($pageToken, 'IG') === 0 && defined('IG_APP_SECRET') && IG_APP_SECRET !== '') {
                    $lt = ig_trocar_token_longa($pageToken);
                    if ($lt['ok'] && !empty($lt['dados']['access_token'])) {
                        $pageToken = (string) $lt['dados']['access_token'];
                        $seg = (int) ($lt['dados']['expires_in'] ?? 0);
                        if ($seg > 0) {
                            $expira = date('Y-m-d H:i:s', time() + $seg);
                        }
                    }
                }
                if ($expira === null) { // EAA, ou IG sem expires_in: pergunta a Meta
                    $expira = token_data_expiracao($pageToken);
                }
                $up = db()->prepare('UPDATE ' . DB_PREFIX . 'clientes
                    SET access_token=?, ig_user_id=?, fb_page_id=?, token_expira_em=?, ig_username=COALESCE(NULLIF(?,""), ig_username), conectado_em=NOW() WHERE id=?');
                $up->execute([$pageToken, $igId, $pageId ?: null, $expira, $igUser, $id]);
                header('Location: ' . BASE_URL . '/cliente_instagram.php?id=' . $id . '&conectado=1');
                exit;
            }
        }
    }
}

if (isset($_GET['conectado'])) {
    $ok = 'Instagram conectado com sucesso.';
}

// recarrega estado atual
$st->execute([$id]);
$c = $st->fetch();
$conectado = !empty($c['access_token']) && !empty($c['ig_user_id']);

// dados ao vivo da conta (quando conectado)
$perfil = null;
$perfilErro = '';
$insights = null;        // resumo de desempenho da conta (7 dias)
$insightsErro = '';
$postsDesempenho = [];   // ultimas publicacoes com metricas
if ($conectado) {
    $tk = (string) $c['access_token'];
    $ig = (string) $c['ig_user_id'];

    $pf = ig_perfil($tk, $ig);
    if ($pf['ok']) {
        $perfil = $pf['dados'];
    } else {
        $perfilErro = $pf['erro'];
    }

    $ins = ig_insights_conta($tk, $ig, 7);
    if ($ins['ok']) {
        $insights = $ins['dados'];
    } else {
        $insightsErro = $ins['erro'];
    }

    // desempenho das ultimas publicacoes ja postadas por este painel
    $pp = db()->prepare('SELECT id, tipo, legenda, publicado_em, ig_media_id
        FROM ' . DB_PREFIX . 'publicacoes
        WHERE cliente_id = ? AND status = "publicado" AND ig_media_id IS NOT NULL
        ORDER BY publicado_em DESC LIMIT 6');
    $pp->execute([$id]);
    foreach ($pp->fetchAll() as $row) {
        $mi = ig_insights_midia($tk, (string) $row['ig_media_id']);
        $row['insights'] = $mi['ok'] ? $mi['dados'] : [];
        $postsDesempenho[] = $row;
    }
}

$page_title = 'Conectar Instagram';
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <a class="back" href="cliente.php?id=<?= (int) $id ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
    Voltar para <?= e($c['nome']) ?>
  </a>

  <div class="page-head">
    <div>
      <h1>Conectar Instagram</h1>
      <p>Cole o token da Meta deste cliente para o sistema publicar por ele.</p>
    </div>
  </div>

  <?php if ($erro !== ''): ?><div class="alert" role="alert" style="max-width:640px;"><?= e($erro) ?></div><?php endif; ?>
  <?php if ($ok !== ''): ?>
    <div class="alert alert--ok" role="status" style="max-width:640px;"><?= e($ok) ?></div>
  <?php endif; ?>

  <div class="form-card">
    <div class="card__foot" style="border:0;padding:0 0 16px;">
      <span class="dot <?= $conectado ? 'on' : 'off' ?>"></span>
      <strong style="color:var(--ink)"><?= $conectado ? 'Conectado' : 'Nao conectado' ?></strong>
      <?php if ($conectado): ?>
        <span style="color:var(--ink-mut)">&middot; @<?= e($c['ig_username'] ?: '—') ?> &middot; IG ID <?= e($c['ig_user_id']) ?></span>
      <?php endif; ?>
    </div>

    <?php if ($conectado): ?>
      <?php if ($perfil): ?>
        <div style="display:flex;gap:16px;align-items:center;padding:16px;margin-bottom:16px;background:var(--panel-2);border:1px solid var(--line);border-radius:var(--r-sm);">
          <?php if (!empty($perfil['profile_picture_url'])): ?>
            <img src="<?= e($perfil['profile_picture_url']) ?>" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;flex:none;">
          <?php else: ?>
            <span class="user__avatar" style="width:64px;height:64px;flex:none;font-size:20px;"><?= e(iniciais($perfil['username'] ?? '?')) ?></span>
          <?php endif; ?>
          <div style="min-width:0;flex:1;">
            <div style="font-weight:700;font-size:16px;"><?= e($perfil['name'] ?? '') ?></div>
            <div style="font-size:13px;color:var(--ink-mut);">@<?= e($perfil['username'] ?? '') ?> &middot; <?= e($perfil['account_type'] ?? '') ?></div>
            <?php if (!empty($perfil['biography'])): ?>
              <div style="font-size:12.5px;color:var(--ink-dim);margin-top:6px;white-space:pre-line;line-height:1.4;"><?= e($perfil['biography']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px;text-align:center;">
          <?php
          $stats = [
              'Seguidores'   => (int) ($perfil['followers_count'] ?? 0),
              'Seguindo'     => (int) ($perfil['follows_count'] ?? 0),
              'Publicacoes'  => (int) ($perfil['media_count'] ?? 0),
          ];
          foreach ($stats as $rotulo => $valor): ?>
            <div style="padding:12px;background:var(--panel-2);border:1px solid var(--line);border-radius:var(--r-sm);">
              <div style="font-family:var(--font-display);font-weight:700;font-size:20px;"><?= number_format($valor, 0, ',', '.') ?></div>
              <div style="font-size:11.5px;color:var(--ink-mut);"><?= $rotulo ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php elseif ($perfilErro): ?>
        <div class="alert" role="alert" style="margin-bottom:16px;">Nao foi possivel ler os dados da conta: <?= e($perfilErro) ?></div>
      <?php endif; ?>

      <?php /* ---- Desempenho da conta (ultimos 7 dias) ---- */ ?>
      <?php if ($insights): ?>
        <div style="font-weight:700;font-size:14px;margin:4px 0 10px;">Desempenho &middot; ultimos 7 dias</div>
        <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:18px;text-align:center;">
          <?php
          $ins_cards = [
              'Alcance'        => $insights['reach'] ?? null,
              'Visitas perfil' => $insights['profile_views'] ?? null,
              'Contas engajadas' => $insights['accounts_engaged'] ?? null,
              'Interacoes'     => $insights['total_interactions'] ?? null,
          ];
          foreach ($ins_cards as $rotulo => $valor):
              if ($valor === null) { continue; } ?>
            <div style="padding:12px;background:var(--panel-2);border:1px solid var(--line);border-radius:var(--r-sm);">
              <div style="font-family:var(--font-display);font-weight:700;font-size:20px;"><?= number_format((int) $valor, 0, ',', '.') ?></div>
              <div style="font-size:11.5px;color:var(--ink-mut);"><?= $rotulo ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php elseif ($insightsErro): ?>
        <div class="alert" role="alert" style="margin-bottom:16px;font-size:12.5px;">
          Estatisticas indisponiveis: <?= e($insightsErro) ?>
          <br>Verifique se o token tem a permissao <code>instagram_manage_insights</code>.
        </div>
      <?php endif; ?>

      <?php /* ---- Desempenho por publicacao ---- */ ?>
      <?php if ($postsDesempenho): ?>
        <div style="font-weight:700;font-size:14px;margin:4px 0 10px;">Ultimas publicacoes</div>
        <div style="display:grid;gap:8px;margin-bottom:18px;">
          <?php foreach ($postsDesempenho as $pd):
              $mi = $pd['insights'];
              $legenda = trim((string) ($pd['legenda'] ?? ''));
              $resumo  = $legenda !== '' ? mb_substr($legenda, 0, 60) : rotulo_tipo($pd['tipo']);
          ?>
            <div style="padding:10px 12px;background:var(--panel-2);border:1px solid var(--line);border-radius:var(--r-sm);">
              <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;margin-bottom:6px;">
                <span style="font-size:12.5px;color:var(--ink);font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($resumo) ?></span>
                <span style="font-size:11px;color:var(--ink-mut);flex:none;"><?= e(substr((string) $pd['publicado_em'], 0, 10)) ?></span>
              </div>
              <?php if ($mi): ?>
                <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:12px;color:var(--ink-dim);">
                  <?php
                  $m_rotulos = [
                      'reach'              => 'Alcance',
                      'likes'              => 'Curtidas',
                      'comments'           => 'Coment.',
                      'saved'              => 'Salvos',
                      'shares'             => 'Compart.',
                      'total_interactions' => 'Interacoes',
                  ];
                  foreach ($m_rotulos as $k => $rot):
                      if (!isset($mi[$k])) { continue; } ?>
                    <span><b style="color:var(--ink);"><?= number_format((int) $mi[$k], 0, ',', '.') ?></b> <?= $rot ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div style="font-size:11.5px;color:var(--ink-mut);">Metricas ainda indisponiveis para esta publicacao.</div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <p style="font-size:13.5px;color:var(--ink-dim);line-height:1.6;margin:0 0 18px;">
        Este cliente ja esta conectado. As publicacoes agendadas serao enviadas automaticamente.
        Para trocar a conta, desconecte e conecte novamente.
      </p>
      <form method="post" action="cliente_instagram.php?id=<?= (int) $id ?>"
            onsubmit="return confirm('Desconectar o Instagram deste cliente?');">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="desconectar">
        <button type="submit" class="btn-ghost btn-ghost--del">Desconectar</button>
      </form>

    <?php elseif ($contas): ?>
      <p style="font-size:13.5px;color:var(--ink-dim);margin:0 0 14px;">Escolha qual conta usar:</p>
      <div style="display:grid;gap:10px;">
        <?php foreach ($contas as $ct): ?>
          <form method="post" action="cliente_instagram.php?id=<?= (int) $id ?>"
                style="display:flex;align-items:center;gap:14px;padding:12px 14px;background:var(--panel-2);border:1px solid var(--line);border-radius:var(--r-sm);">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="conectar">
            <input type="hidden" name="page_token" value="<?= e($ct['page_token']) ?>">
            <input type="hidden" name="page_id" value="<?= e($ct['page_id']) ?>">
            <input type="hidden" name="ig_id" value="<?= e($ct['ig_id']) ?>">
            <input type="hidden" name="ig_user" value="<?= e($ct['ig_user']) ?>">
            <?php if ($ct['ig_foto']): ?>
              <img src="<?= e($ct['ig_foto']) ?>" alt="" style="width:44px;height:44px;border-radius:50%;object-fit:cover;flex:none;">
            <?php else: ?>
              <span class="user__avatar" style="width:44px;height:44px;flex:none;"><?= e(iniciais($ct['ig_user'] ?: $ct['page_name'])) ?></span>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
              <div style="font-weight:600;">@<?= e($ct['ig_user'] ?: 'sem_usuario') ?></div>
              <div style="font-size:12px;color:var(--ink-mut);">Pagina: <?= e($ct['page_name']) ?></div>
            </div>
            <button type="submit" class="btn-inline">Conectar</button>
          </form>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <form method="post" action="cliente_instagram.php?id=<?= (int) $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="buscar">
        <div class="field">
          <label for="token">Token de acesso da Meta</label>
          <textarea id="token" name="token" rows="4"
                    placeholder="Cole aqui o token de longa duracao (EAAB...)"><?= e($tokenDigitado) ?></textarea>
        </div>
        <button type="submit" class="btn btn--auto">Buscar contas</button>
      </form>

      <details style="margin-top:20px;font-size:13px;color:var(--ink-dim);line-height:1.6;">
        <summary style="cursor:pointer;font-weight:600;color:var(--ink);">Como obter o token?</summary>
        <ol style="padding-left:18px;margin:10px 0 0;">
          <li>A conta do Instagram precisa ser <b>Comercial ou Criador</b> e estar vinculada a uma <b>Pagina do Facebook</b>.</li>
          <li>No <b>Facebook Developers</b>, use um App com os produtos <i>Instagram Graph API</i> e <i>Facebook Login</i>.</li>
          <li>Gere um token de usuario com as permissoes: <code>instagram_basic</code>, <code>instagram_content_publish</code>, <code>instagram_manage_insights</code>, <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>business_management</code>. A permissao <code>instagram_manage_insights</code> e o que libera as estatisticas de desempenho.</li>
          <li>Troque por um <b>token de longa duracao</b> (cerca de 60 dias) e cole acima. O sistema usa o token de Pagina derivado, que nao expira.</li>
        </ol>
      </details>
    <?php endif; ?>
  </div>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
