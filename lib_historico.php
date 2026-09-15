<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * lib_historico.php
 * Histórico das publicações JÁ feitas no Instagram + estatísticas.
 *
 * - Feed/Reels/Carrossel: a Graph API devolve o histórico completo (GET /{ig}/media).
 * - Stories: a API só devolve os ATIVOS (24h). Por isso há um cron que captura
 *   os stories ativos algumas vezes ao dia e guarda antes de expirarem.
 *
 * Depende de init.php (graph_get, ig_insights_*).
 */

/* Alvo do path conforme o tipo de token (IG login usa "me"). */
function hist_alvo(string $token, string $igId): string
{
    return (stripos($token, 'IG') === 0 || $igId === '') ? 'me' : $igId;
}

/* ---- Lista as mídias publicadas (feed/reels/carrossel) ---- */
function ig_listar_midias(string $token, string $igId, int $limite = 50): array
{
    $alvo = hist_alvo($token, $igId);
    $r = graph_get($alvo . '/media', [
        'fields'       => 'id,caption,media_type,media_product_type,permalink,thumbnail_url,media_url,timestamp,like_count,comments_count',
        'limit'        => max(1, min(100, $limite)),
        'access_token' => $token,
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'erro' => $r['erro']];
    }
    return ['ok' => true, 'itens' => $r['dados']['data'] ?? []];
}

/* ---- Lista os stories ATIVOS (somente últimas 24h existem na API) ---- */
function ig_listar_stories(string $token, string $igId): array
{
    $alvo = hist_alvo($token, $igId);
    $r = graph_get($alvo . '/stories', [
        'fields'       => 'id,media_type,permalink,thumbnail_url,media_url,timestamp',
        'access_token' => $token,
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'erro' => $r['erro']];
    }
    return ['ok' => true, 'itens' => $r['dados']['data'] ?? []];
}

/* ---- Insights de UM story (métricas diferentes das de feed) ---- */
function ig_insights_story(string $token, string $storyId): array
{
    $r = graph_get($storyId . '/insights', [
        'metric'       => 'reach,replies,exits,taps_forward,taps_back',
        'access_token' => $token,
    ]);
    if (!$r['ok']) {
        $r = graph_get($storyId . '/insights', ['metric' => 'reach', 'access_token' => $token]);
        if (!$r['ok']) {
            return ['ok' => false, 'erro' => $r['erro']];
        }
    }
    return ['ok' => true, 'dados' => ig_insights_extrair($r['dados'])];
}

/* Escolhe a melhor miniatura disponível do item. */
function hist_thumb(array $it): string
{
    return (string) ($it['thumbnail_url'] ?? $it['media_url'] ?? '');
}

/* ---- Sincroniza feed/reels do cliente: grava a lista e atualiza insights ----
   Retorna ['ok'=>bool,'novas'=>int,'insights'=>int,'erro'=>string]. */
function historico_sync_midias(PDO $db, array $cliente, int $limite = 50, int $insightsStaleH = 6): array
{
    $token = (string) ($cliente['access_token'] ?? '');
    $igId  = (string) ($cliente['ig_user_id'] ?? '');
    if ($token === '') {
        return ['ok' => false, 'erro' => 'Cliente sem token do Instagram.'];
    }

    $lista = ig_listar_midias($token, $igId, $limite);
    if (!$lista['ok']) {
        return ['ok' => false, 'erro' => $lista['erro']];
    }

    $up = $db->prepare('INSERT INTO ' . DB_PREFIX . 'historico_midia
        (cliente_id, ig_media_id, media_type, produto, legenda, permalink, thumb_url, publicado_em, curtidas, comentarios)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            legenda=VALUES(legenda), permalink=VALUES(permalink), thumb_url=VALUES(thumb_url),
            curtidas=VALUES(curtidas), comentarios=VALUES(comentarios)');

    $novas = 0;
    $ids = [];
    foreach ($lista['itens'] as $it) {
        $mid = (string) ($it['id'] ?? '');
        if ($mid === '') {
            continue;
        }
        $ids[] = $mid;
        $ts = strtotime((string) ($it['timestamp'] ?? ''));
        $up->execute([
            (int) $cliente['id'],
            $mid,
            (string) ($it['media_type'] ?? ''),
            (string) ($it['media_product_type'] ?? ''),
            mb_substr((string) ($it['caption'] ?? ''), 0, 2000),
            mb_substr((string) ($it['permalink'] ?? ''), 0, 500),
            mb_substr(hist_thumb($it), 0, 700),
            $ts !== false ? date('Y-m-d H:i:s', $ts) : null,
            isset($it['like_count']) ? (int) $it['like_count'] : null,
            isset($it['comments_count']) ? (int) $it['comments_count'] : null,
        ]);
        $novas += ($up->rowCount() === 1) ? 1 : 0; // 1 = INSERT, 2 = UPDATE
    }

    // Atualiza insights das mídias sem métricas ou desatualizadas (limita custo de API).
    $insAtualizados = 0;
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare('SELECT ig_media_id FROM ' . DB_PREFIX . 'historico_midia
            WHERE ig_media_id IN (' . $in . ')
              AND (insights_em IS NULL OR insights_em < ?)');
        $params = $ids;
        $params[] = date('Y-m-d H:i:s', time() - $insightsStaleH * 3600);
        $st->execute($params);
        $alvos = array_column($st->fetchAll(), 'ig_media_id');

        $updIns = $db->prepare('UPDATE ' . DB_PREFIX . 'historico_midia
            SET alcance=?, salvos=?, compart=?, interacoes=?, insights_em=NOW()
            WHERE ig_media_id=?');
        foreach ($alvos as $mid) {
            $i = ig_insights_midia($token, (string) $mid);
            if (!$i['ok']) {
                continue;
            }
            $d = $i['dados'];
            $updIns->execute([
                isset($d['reach']) ? (int) $d['reach'] : null,
                isset($d['saved']) ? (int) $d['saved'] : null,
                isset($d['shares']) ? (int) $d['shares'] : null,
                isset($d['total_interactions']) ? (int) $d['total_interactions'] : null,
                $mid,
            ]);
            $insAtualizados++;
        }
    }

    return ['ok' => true, 'novas' => $novas, 'insights' => $insAtualizados, 'erro' => ''];
}

/* ---- Captura os stories ativos do cliente AGORA (grava antes de expirarem) ----
   Retorna ['ok'=>bool,'capturados'=>int,'erro'=>string]. */
function historico_capturar_stories(PDO $db, array $cliente): array
{
    $token = (string) ($cliente['access_token'] ?? '');
    $igId  = (string) ($cliente['ig_user_id'] ?? '');
    if ($token === '') {
        return ['ok' => false, 'erro' => 'Cliente sem token do Instagram.'];
    }

    $lista = ig_listar_stories($token, $igId);
    if (!$lista['ok']) {
        return ['ok' => false, 'erro' => $lista['erro']];
    }

    $up = $db->prepare('INSERT INTO ' . DB_PREFIX . 'historico_stories
        (cliente_id, ig_story_id, media_type, permalink, thumb_url, publicado_em,
         alcance, respostas, saidas, toques_frente, toques_voltar)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            thumb_url=VALUES(thumb_url), alcance=VALUES(alcance), respostas=VALUES(respostas),
            saidas=VALUES(saidas), toques_frente=VALUES(toques_frente), toques_voltar=VALUES(toques_voltar)');

    $n = 0;
    foreach ($lista['itens'] as $it) {
        $sid = (string) ($it['id'] ?? '');
        if ($sid === '') {
            continue;
        }
        $ts = strtotime((string) ($it['timestamp'] ?? ''));
        $met = ig_insights_story($token, $sid);
        $d = $met['ok'] ? $met['dados'] : [];
        $up->execute([
            (int) $cliente['id'],
            $sid,
            (string) ($it['media_type'] ?? ''),
            mb_substr((string) ($it['permalink'] ?? ''), 0, 500),
            mb_substr(hist_thumb($it), 0, 700),
            $ts !== false ? date('Y-m-d H:i:s', $ts) : null,
            isset($d['reach']) ? (int) $d['reach'] : null,
            isset($d['replies']) ? (int) $d['replies'] : null,
            isset($d['exits']) ? (int) $d['exits'] : null,
            isset($d['taps_forward']) ? (int) $d['taps_forward'] : null,
            isset($d['taps_back']) ? (int) $d['taps_back'] : null,
        ]);
        $n++;
    }
    return ['ok' => true, 'capturados' => $n, 'erro' => ''];
}

/* ---- Lê o histórico (feed + stories) de um mês, agrupado por dia (YYYY-MM-DD) ----
   Cada item: ['kind'=>'feed'|'story', ...campos...]. */
function historico_do_mes(PDO $db, int $clienteId, string $ini, string $fim): array
{
    $porDia = [];

    $m = $db->prepare('SELECT ig_media_id, media_type, produto, legenda, permalink, thumb_url,
            publicado_em, curtidas, comentarios, alcance, salvos, compart, interacoes
        FROM ' . DB_PREFIX . 'historico_midia
        WHERE cliente_id=? AND publicado_em >= ? AND publicado_em < ?
        ORDER BY publicado_em');
    $m->execute([$clienteId, $ini, $fim]);
    foreach ($m->fetchAll() as $r) {
        $r['kind'] = 'feed';
        $porDia[substr((string) $r['publicado_em'], 0, 10)][] = $r;
    }

    $s = $db->prepare('SELECT ig_story_id, media_type, permalink, thumb_url, publicado_em,
            alcance, impressoes, respostas, saidas, toques_frente, toques_voltar
        FROM ' . DB_PREFIX . 'historico_stories
        WHERE cliente_id=? AND publicado_em >= ? AND publicado_em < ?
        ORDER BY publicado_em');
    $s->execute([$clienteId, $ini, $fim]);
    foreach ($s->fetchAll() as $r) {
        $r['kind'] = 'story';
        $porDia[substr((string) $r['publicado_em'], 0, 10)][] = $r;
    }

    return $porDia;
}
