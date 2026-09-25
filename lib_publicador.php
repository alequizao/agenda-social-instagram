<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * lib_publicador.php
 * Logica de publicacao no Instagram via Graph API (Content Publishing).
 *
 * Fluxo geral:
 *   1) cria um "container" de midia (POST /{ig-user-id}/media)
 *   2) aguarda o container ficar FINISHED (videos/reels processam no servidor)
 *   3) publica (POST /{ig-user-id}/media_publish)
 *
 * Maquina de estados (coluna status de ag_publicacoes):
 *   agendado -> processando -> publicado | erro
 * O id do container principal fica salvo em ig_container_id, permitindo
 * que o cron RESUMA a publicacao no proximo tick sem recriar nada.
 */

/* ---- URL absoluta de um arquivo local (a Meta precisa baixar por URL) ---- */
function pub_url(string $arquivoRelativo): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($arquivoRelativo, '/');
}

/* ===========================================================================
 * CONTROLE DE RATE LIMIT DA META  ("User is performing too many actions")
 * ---------------------------------------------------------------------------
 * Sem isso o cron continua batendo na API a cada minuto, e CADA tentativa
 * conta como mais uma acao -> o bloqueio nunca e liberado e os agendamentos
 * sao queimados como "erro". Estes helpers fazem o publicador PARAR por um
 * tempo quando a Meta reclama, e espacar as publicacoes.
 * ========================================================================= */

/** Espacamento minimo (segundos) entre publicacoes que vao ao ar.
 *  Ritmo CONTINUO e SEGURO: ~1 story a cada 4 min (15/h) fluindo o dia todo,
 *  abaixo do limite de rajada da Meta. NUNCA para; so trickle constante. */
const PUB_INTERVALO_MINIMO_SEG = 240; // 4 min

/** ESCALA de backoff (minutos) por bloqueios CONSECUTIVOS da Meta.
 *  1o bloqueio espera 30min, 2o 1h, 3o 2h, 4o 4h, 5o+ 8h. Isso dá um
 *  "prazo confiavel": a cada nova recusa o sistema espera MAIS, em vez de
 *  ficar cutucando a Meta e prolongando o bloqueio. Zera ao publicar com sucesso. */
const PUB_BACKOFF_MIN = [30, 60, 120, 240, 480];

function pub_arq_pausa(): string  { return __DIR__ . '/logs/rate_limit.pause'; }
function pub_arq_ultima(): string { return __DIR__ . '/logs/last_publish.ts'; }
function pub_arq_streak(): string { return __DIR__ . '/logs/rate_limit.streak'; }

/** Detecta a mensagem de rate limit da Meta (varias variacoes). */
function pub_erro_rate_limit(string $erro): bool
{
    $e = mb_strtolower($erro);
    return strpos($e, 'too many actions') !== false
        || strpos($e, 'limit')            !== false && strpos($e, 'rate') !== false
        || strpos($e, 'try again later')  !== false;
}

/** Se houver pausa ativa, retorna os segundos restantes; senao 0. */
function pub_pausa_restante(): int
{
    $f = pub_arq_pausa();
    if (!is_file($f)) {
        return 0;
    }
    $ate = (int) trim((string) @file_get_contents($f));
    $resta = $ate - time();
    if ($resta <= 0) {
        @unlink($f); // expirou: limpa
        return 0;
    }
    return $resta;
}

/** Nº de bloqueios consecutivos sofridos (sem sucesso no meio). */
function pub_streak_atual(): int
{
    return is_file(pub_arq_streak()) ? max(0, (int) trim((string) @file_get_contents(pub_arq_streak()))) : 0;
}

/**
 * Ativa a pausa global com backoff ESCALONADO (prazo confiavel e crescente).
 * Cada bloqueio consecutivo espera mais que o anterior (PUB_BACKOFF_MIN).
 * @return int minutos de pausa aplicados (para log/reagendamento).
 */
function pub_ativar_pausa(): int
{
    $streak = pub_streak_atual() + 1;
    @file_put_contents(pub_arq_streak(), (string) $streak);

    $idx = min($streak - 1, count(PUB_BACKOFF_MIN) - 1);
    $min = PUB_BACKOFF_MIN[$idx];

    @file_put_contents(pub_arq_pausa(), (string) (time() + $min * 60));
    return $min;
}

/** Registra o instante da ultima publicacao que foi ao ar e ZERA o backoff. */
function pub_registrar_publicacao(): void
{
    @file_put_contents(pub_arq_ultima(), (string) time());
    @unlink(pub_arq_streak());  // bloqueio acabou: a escala de espera reinicia
    @unlink(pub_arq_pausa());
}

/** Segundos desde a ultima publicacao (PHP_INT_MAX se nunca houve). */
function pub_segundos_desde_ultima(): int
{
    $f = pub_arq_ultima();
    if (!is_file($f)) {
        return PHP_INT_MAX;
    }
    $t = (int) trim((string) @file_get_contents($f));
    return $t > 0 ? time() - $t : PHP_INT_MAX;
}

/**
 * Reagenda uma publicacao para mais tarde SEM marca-la como erro.
 * Usado quando a falha e transitoria (rate limit) e nao culpa do conteudo.
 */
function pub_reagendar(PDO $db, int $pubId, int $minutos): void
{
    $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes
        SET status="agendado", agendado_para=(NOW() + INTERVAL ? MINUTE), erro_msg=?
        WHERE id=?')
       ->execute([$minutos, 'Rate limit da Meta; reagendado automaticamente.', $pubId]);
}

/* ===========================================================================
 * TETO DIÁRIO DE PUBLICAÇÕES  (evita o bloqueio da Meta por VOLUME)
 * ---------------------------------------------------------------------------
 * Diferente do rate limit (rajada), a Meta tambem limita o TOTAL de posts por
 * API numa JANELA MOVEL de 24h. A doc fala em 100, mas contas reais sao barradas
 * bem antes (~50). Para nunca encostar nisso, impomos um teto PROPRIO e
 * CONSERVADOR (config pub_cap_dia, padrao 45) POR CONTA, cruzando duas fontes:
 *   - LOCAL : nº de posts NOSSOS publicados nas ultimas 24h (sempre confiavel);
 *   - META  : o contador OFICIAL via content_publishing_limit.
 * Usamos o MAIOR dos dois. Atingiu o teto -> NAO publica: reagenda para quando
 * a janela liberar (o post mais antigo completar 24h). Nada e queimado como erro.
 * ========================================================================= */

/** Teto seguro padrao de publicacoes por janela de 24h, por conta.
 *  40 (e nao 50) deixa margem confortavel ate o bloqueio por volume da Meta. */
const PUB_CAP_DIA_PADRAO = 40;

/** Le o contador OFICIAL de publicacoes da Meta (janela movel de 24h).
 *  @return array|null ['usados'=>int,'total'=>int] ou null se indisponivel. */
function pub_quota_meta(string $igId, string $token): ?array
{
    static $cache = [];
    if (array_key_exists($igId, $cache)) {
        return $cache[$igId]; // 1 consulta por conta por execucao do cron
    }
    $r = graph_get($igId . '/content_publishing_limit', [
        'fields'       => 'config,quota_usage',
        'access_token' => $token,
    ]);
    $out = null;
    if ($r['ok'] && !empty($r['dados']['data'][0])) {
        $d = $r['dados']['data'][0];
        $out = [
            'usados' => (int) ($d['quota_usage'] ?? 0),
            'total'  => (int) ($d['config']['quota_total'] ?? 0),
        ];
    }
    $cache[$igId] = $out; // memoriza ate "null" para nao repetir consulta que falhou
    return $out;
}

/** Quantos posts NOSSOS foram publicados nas ultimas 24h para este cliente.
 *  (cada linha de ag_publicacoes = 1 media_publish; carrossel ja conta como 1.) */
function pub_publicados_24h(PDO $db, int $clienteId): int
{
    $st = $db->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes
        WHERE cliente_id=? AND status="publicado"
          AND publicado_em >= (NOW() - INTERVAL 24 HOUR)');
    $st->execute([$clienteId]);
    return (int) $st->fetchColumn();
}

/** Minutos ate a janela de 24h liberar 1 vaga (post mais antigo completar 24h). */
function pub_minutos_para_liberar(PDO $db, int $clienteId): int
{
    $st = $db->prepare('SELECT MIN(publicado_em) FROM ' . DB_PREFIX . 'publicacoes
        WHERE cliente_id=? AND status="publicado"
          AND publicado_em >= (NOW() - INTERVAL 24 HOUR)');
    $st->execute([$clienteId]);
    $min = (string) $st->fetchColumn();
    if ($min === '') {
        return 30; // sem dado local: tenta de novo em 30min (quota da Meta tende a cair)
    }
    $libera = strtotime($min) + 24 * 3600 + 60; // +1min de folga
    return max(5, (int) ceil(($libera - time()) / 60));
}

/**
 * Decide se PODE publicar agora sem estourar o teto diario da conta.
 * @return array ['pode'=>bool,'usados'=>int,'cap'=>int,'fonte'=>string,'liberaMin'=>int]
 */
function pub_checar_teto(PDO $db, int $clienteId, string $igId, string $token): array
{
    $cap = max(1, (int) cfg_get('pub_cap_dia', (string) PUB_CAP_DIA_PADRAO));

    $usados = pub_publicados_24h($db, $clienteId);
    $fonte  = 'local';

    $meta = pub_quota_meta($igId, $token);
    if ($meta !== null && $meta['usados'] > $usados) {
        $usados = $meta['usados']; // a Meta sabe de posts feitos fora do sistema tambem
        $fonte  = 'meta';
    }

    $pode = $usados < $cap;
    return [
        'pode'      => $pode,
        'usados'    => $usados,
        'cap'       => $cap,
        'fonte'     => $fonte,
        'liberaMin' => $pode ? 0 : pub_minutos_para_liberar($db, $clienteId),
    ];
}

/** Reagenda por teto diario atingido (sem marcar erro; preserva o container). */
function pub_reagendar_teto(PDO $db, int $pubId, int $minutos, int $usados, int $cap): void
{
    $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes
        SET status="agendado", agendado_para=(NOW() + INTERVAL ? MINUTE), erro_msg=?
        WHERE id=?')
       ->execute([$minutos, "Teto diario {$usados}/{$cap} posts/24h atingido; aguardando a janela liberar.", $pubId]);
}

/**
 * REPUBLICAR (acao manual do relatorio de erros): volta um item "erro" para a
 * fila, do zero — recria container, zera tentativas e publica o quanto antes
 * (o throttle do publicador cuida do ritmo). Retorna true se algo mudou.
 */
function pub_republicar(PDO $db, int $pubId): bool
{
    $st = $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes
        SET status="agendado", agendado_para=NOW(), tentativas=0,
            erro_msg=NULL, ig_container_id=NULL, ig_media_id=NULL
        WHERE id=? AND status="erro"');
    $st->execute([$pubId]);
    return $st->rowCount() > 0;
}

/* ===========================================================================
 * AUTO-RETRY: classifica a falha e decide reagendar (transitoria) ou desistir.
 * Objetivo 24/7: uma falha de rede/timeout/processamento NAO pode queimar o
 * agendamento. So vira "erro" (vai pro relatorio) depois de esgotar as tentativas.
 * ========================================================================= */

/** Quantas tentativas automaticas antes de desistir e mandar pro relatorio. */
const PUB_MAX_TENTATIVAS = 5;
/** Minutos de espera entre tentativas automaticas (backoff). */
const PUB_RETRY_BACKOFF_MIN = 10;

/** Erro transitorio? (vale tentar de novo automaticamente). */
function pub_erro_transitorio(string $erro): bool
{
    $e = mb_strtolower($erro);
    $sinais = [
        'falha de conexao', 'falha de conex', 'conexao', 'timeout', 'timed out',
        'temporar', 'try again', 'internal', 'unknown error', 'service', 'unavailable',
        'processing', 'being processed', 'nao processou', 'expired', 'container',
        'resposta invalida', '500', '502', '503', '504',
    ];
    foreach ($sinais as $t) {
        if (strpos($e, $t) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Trata QUALQUER falha de publicacao de forma unificada.
 * Retorna ['estado'=>'pausado'|'retry'|'erro', 'msg'=>...] para o log/cron.
 *
 *  - rate limit  -> pausa global + reagenda (nao gasta "tentativa ruim")
 *  - transitorio  + ainda ha tentativas -> reagenda com backoff (status volta a "agendado")
 *  - permanente OU tentativas esgotadas -> status="erro" (aparece no relatorio p/ republicar)
 */
/* Avisa (no maximo 1x por dia) que um token precisa de reconexao manual.
   O aviso aparece como faixa no topo do painel (partials/head.php) e no log. */
function pub_avisar_token(PDO $db, array $cli, string $erro): void
{
    $id   = (int) $cli['id'];
    $hoje = date('Y-m-d');
    if (cfg_get('token_aviso_' . $id, '') === $hoje) {
        return;
    }
    cfg_set('token_aviso_' . $id, $hoje);
    cfg_set('token_erro_' . $id, mb_substr($erro, 0, 300));
    error_log('[agendamentos] token do cliente #' . $id . ' precisa de reconexao manual: ' . $erro);
}

function pub_tratar_falha(PDO $db, array $p, string $erro): array
{
    $pubId = (int) $p['id'];

    // 1) Rate limit: pausa tudo (backoff escalonado) e reagenda; nao conta tentativa de conteudo.
    if (pub_erro_rate_limit($erro)) {
        $min = pub_ativar_pausa();
        pub_reagendar($db, $pubId, $min);
        return ['estado' => 'pausado', 'msg' => "rate limit (bloqueio #" . pub_streak_atual() . "); pausando {$min}min"];
    }

    // tentativa atual (le fresco do banco)
    $tent = 1 + (int) $db->query('SELECT tentativas FROM ' . DB_PREFIX . 'publicacoes WHERE id=' . $pubId)->fetchColumn();

    // 2) Transitorio e ainda dentro do limite: reagenda e recria container do zero.
    if (pub_erro_transitorio($erro) && $tent < PUB_MAX_TENTATIVAS) {
        $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes
            SET status="agendado", tentativas=?, ig_container_id=NULL,
                agendado_para=(NOW() + INTERVAL ? MINUTE), erro_msg=?
            WHERE id=?')
           ->execute([
               $tent, PUB_RETRY_BACKOFF_MIN,
               mb_substr('Tentativa ' . $tent . '/' . PUB_MAX_TENTATIVAS . ': ' . $erro, 0, 500),
               $pubId,
           ]);
        return ['estado' => 'retry', 'msg' => "tentativa {$tent}/" . PUB_MAX_TENTATIVAS . ' em ' . PUB_RETRY_BACKOFF_MIN . 'min'];
    }

    // 3) Permanente ou esgotou: marca erro definitivo (vai pro relatorio).
    $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes
        SET status="erro", tentativas=?, erro_msg=? WHERE id=?')
       ->execute([$tent, mb_substr($erro, 0, 500), $pubId]);
    return ['estado' => 'erro', 'msg' => $erro];
}

/* ---- Consulta o status_code de um container ---- */
function ig_status_container(string $containerId, string $token): string
{
    $r = graph_get($containerId, ['fields' => 'status_code,status', 'access_token' => $token]);
    if (!$r['ok']) {
        return 'ERROR';
    }
    return (string) ($r['dados']['status_code'] ?? 'IN_PROGRESS');
}

/* ---- Espera (limitada) o container ficar pronto. Retorna FINISHED/ERROR/EXPIRED/IN_PROGRESS ---- */
function ig_aguardar(string $containerId, string $token, int $tentativas = 3, int $intervalo = 6): string
{
    for ($i = 0; $i < $tentativas; $i++) {
        $s = ig_status_container($containerId, $token);
        if ($s === 'FINISHED' || $s === 'ERROR' || $s === 'EXPIRED') {
            return $s;
        }
        if ($i < $tentativas - 1) {
            sleep($intervalo);
        }
    }
    return 'IN_PROGRESS';
}

/* ---- Cria um container de item (usado em carrossel) ---- */
function ig_criar_item(string $igId, string $token, array $midia): array
{
    $params = ['access_token' => $token, 'is_carousel_item' => 'true'];
    if ($midia['tipo'] === 'video') {
        $params['media_type'] = 'VIDEO';
        $params['video_url'] = $midia['url_publica'];
    } else {
        $params['image_url'] = $midia['url_publica'];
    }
    $r = graph_post($igId . '/media', $params);
    if (!$r['ok']) {
        return ['ok' => false, 'erro' => $r['erro']];
    }
    return ['ok' => true, 'id' => (string) ($r['dados']['id'] ?? '')];
}

/**
 * Cria o container PRINCIPAL conforme o tipo da publicacao.
 * Retorna ['ok'=>true,'id'=>containerId] ou ['ok'=>false,'erro'=>...].
 */
function ig_criar_container_principal(array $p, array $midias, string $igId, string $token): array
{
    $tipo = (string) $p['tipo'];
    $legenda = trim((string) ($p['legenda'] ?? ''));
    $primeira = $midias[0] ?? null;
    if (!$primeira) {
        return ['ok' => false, 'erro' => 'Publicacao sem midia.'];
    }

    // STORY
    if ($tipo === 'story') {
        $params = ['access_token' => $token, 'media_type' => 'STORIES'];
        if ($primeira['tipo'] === 'video') {
            $params['video_url'] = $primeira['url_publica'];
        } else {
            $params['image_url'] = $primeira['url_publica'];
        }
        $r = graph_post($igId . '/media', $params);
        return $r['ok'] ? ['ok' => true, 'id' => (string) $r['dados']['id']] : ['ok' => false, 'erro' => $r['erro']];
    }

    // REELS (tipo reel, ou feed de um unico video)
    $ehVideoUnico = ($tipo === 'feed' && count($midias) === 1 && $primeira['tipo'] === 'video');
    if ($tipo === 'reel' || $ehVideoUnico) {
        $params = [
            'access_token' => $token,
            'media_type'   => 'REELS',
            'video_url'    => $primeira['url_publica'],
        ];
        if ($legenda !== '') {
            $params['caption'] = $legenda;
        }
        if (!empty($p['capa_arquivo'])) {
            $params['cover_url'] = pub_url($p['capa_arquivo']);
        }
        $r = graph_post($igId . '/media', $params);
        return $r['ok'] ? ['ok' => true, 'id' => (string) $r['dados']['id']] : ['ok' => false, 'erro' => $r['erro']];
    }

    // CARROSSEL (feed com mais de uma midia)
    if ($tipo === 'carrossel' || ($tipo === 'feed' && count($midias) > 1)) {
        $children = [];
        foreach ($midias as $m) {
            $item = ig_criar_item($igId, $token, $m);
            if (!$item['ok']) {
                return ['ok' => false, 'erro' => 'Falha ao criar item do carrossel: ' . $item['erro']];
            }
            // itens de video precisam estar prontos antes de montar o carrossel
            if ($m['tipo'] === 'video') {
                $st = ig_aguardar($item['id'], $token, 8, 8);
                if ($st !== 'FINISHED') {
                    return ['ok' => false, 'erro' => 'Video do carrossel ainda processando (' . $st . '). Tentaremos de novo.'];
                }
            }
            $children[] = $item['id'];
        }
        $params = [
            'access_token' => $token,
            'media_type'   => 'CAROUSEL',
            'children'     => implode(',', $children),
        ];
        if ($legenda !== '') {
            $params['caption'] = $legenda;
        }
        $r = graph_post($igId . '/media', $params);
        return $r['ok'] ? ['ok' => true, 'id' => (string) $r['dados']['id']] : ['ok' => false, 'erro' => $r['erro']];
    }

    // FEED imagem unica
    $params = ['access_token' => $token, 'image_url' => $primeira['url_publica']];
    if ($legenda !== '') {
        $params['caption'] = $legenda;
    }
    $r = graph_post($igId . '/media', $params);
    return $r['ok'] ? ['ok' => true, 'id' => (string) $r['dados']['id']] : ['ok' => false, 'erro' => $r['erro']];
}

/* ---- Publica um container ja pronto ---- */
function ig_publicar_container(string $igId, string $token, string $containerId): array
{
    $r = graph_post($igId . '/media_publish', [
        'access_token' => $token,
        'creation_id'  => $containerId,
    ]);
    return $r['ok'] ? ['ok' => true, 'id' => (string) ($r['dados']['id'] ?? '')] : ['ok' => false, 'erro' => $r['erro']];
}

/* ---- Marca erro na publicacao ---- */
function pub_marcar_erro(PDO $db, int $pubId, string $msg): void
{
    $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes
        SET status="erro", erro_msg=?, tentativas=tentativas+1 WHERE id=?')
       ->execute([mb_substr($msg, 0, 500), $pubId]);
}

/**
 * Processa UMA publicacao (cria container, espera, publica).
 * Idempotente: se ja existe ig_container_id, retoma do ponto de espera/publicacao.
 *
 * Retorna ['estado'=>'publicado'|'pendente'|'erro', 'msg'=>...].
 */
function processar_publicacao(PDO $db, array $p, bool $forcar = false): array
{
    $pubId = (int) $p['id'];

    // cliente / token
    $cs = $db->prepare('SELECT ig_user_id, access_token FROM ' . DB_PREFIX . 'clientes WHERE id = ? LIMIT 1');
    $cs->execute([(int) $p['cliente_id']]);
    $cli = $cs->fetch();
    if (!$cli || empty($cli['ig_user_id']) || empty($cli['access_token'])) {
        pub_marcar_erro($db, $pubId, 'Cliente sem Instagram conectado.');
        return ['estado' => 'erro', 'msg' => 'cliente sem token'];
    }
    $igId = (string) $cli['ig_user_id'];
    $token = (string) $cli['access_token'];

    // TETO DIARIO: nunca ultrapassa o limite seguro de posts/24h da conta.
    // Checado ANTES de criar o container (nao desperdica processamento na Meta).
    if (!$forcar) {
        $teto = pub_checar_teto($db, (int) $p['cliente_id'], $igId, $token);
        if (!$teto['pode']) {
            pub_reagendar_teto($db, $pubId, $teto['liberaMin'], $teto['usados'], $teto['cap']);
            return ['estado' => 'adiado',
                    'msg'    => "teto {$teto['usados']}/{$teto['cap']} ({$teto['fonte']}); reagendado +{$teto['liberaMin']}min"];
        }
    }

    // midias
    $mm = $db->prepare('SELECT tipo, arquivo, url_publica FROM ' . DB_PREFIX . 'publicacao_midia
        WHERE publicacao_id = ? ORDER BY posicao, id');
    $mm->execute([$pubId]);
    $midias = $mm->fetchAll();
    if (!$midias) {
        pub_marcar_erro($db, $pubId, 'Publicacao sem midia.');
        return ['estado' => 'erro', 'msg' => 'sem midia'];
    }

    // FASE 1: garantir container principal
    $containerId = trim((string) ($p['ig_container_id'] ?? ''));
    if ($containerId === '') {
        // trava: marca como processando antes de criar
        $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes SET status="processando" WHERE id=?')->execute([$pubId]);

        $c = ig_criar_container_principal($p, $midias, $igId, $token);
        if (!$c['ok'] || $c['id'] === '') {
            return pub_tratar_falha($db, $p, $c['erro'] ?? 'Falha ao criar container.');
        }
        $containerId = $c['id'];
        $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes SET ig_container_id=?, status="processando" WHERE id=?')
           ->execute([$containerId, $pubId]);
    }

    // FASE 2: aguardar pronto (curto; resume no proximo tick se faltar)
    $status = ig_aguardar($containerId, $token, 3, 6);
    if ($status === 'ERROR' || $status === 'EXPIRED') {
        // transitorio: pub_tratar_falha ja limpa o container e reagenda p/ recriar.
        return pub_tratar_falha($db, $p, 'Container ' . $status . ' ao processar a midia.');
    }
    if ($status !== 'FINISHED') {
        return ['estado' => 'pendente', 'msg' => 'aguardando processamento'];
    }

    // THROTTLE: espaca as publicacoes que vao ao ar (evita rajada -> rate limit).
    // O container ja esta pronto; so seguramos o media_publish ate dar o intervalo.
    if (!$forcar) {
        $desde = pub_segundos_desde_ultima();
        if ($desde < PUB_INTERVALO_MINIMO_SEG) {
            return ['estado' => 'pendente', 'msg' => 'aguardando intervalo (' . ($desde) . 's/' . PUB_INTERVALO_MINIMO_SEG . 's)'];
        }
    }

    // FASE 3: publicar
    $pub = ig_publicar_container($igId, $token, $containerId);
    if (!$pub['ok']) {
        return pub_tratar_falha($db, $p, 'Falha ao publicar: ' . $pub['erro']);
    }

    pub_registrar_publicacao();
    $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes
        SET status="publicado", ig_media_id=?, publicado_em=NOW(), erro_msg=NULL WHERE id=?')
       ->execute([$pub['id'], $pubId]);

    // APAGAR MÍDIA DA PASTA APÓS PUBLICAR (Evitando armazenamento desnecessário)
    try {
        $stM = $db->prepare('SELECT arquivo FROM ' . DB_PREFIX . 'publicacao_midia WHERE publicacao_id=?');
        $stM->execute([$pubId]);
        foreach ($stM->fetchAll() as $m) {
            $f = __DIR__ . '/' . ltrim((string) $m['arquivo'], '/');
            if (is_file($f)) {
                @unlink($f);
            }
        }
        if (!empty($p['capa_arquivo'])) {
            $fCapa = __DIR__ . '/' . ltrim((string) $p['capa_arquivo'], '/');
            if (is_file($fCapa)) {
                @unlink($fCapa);
            }
        }
        $pastaPub = __DIR__ . '/uploads/publicacoes/' . $pubId;
        if (is_dir($pastaPub)) {
            @rmdir($pastaPub);
        }
    } catch (Throwable $e) {
        // Silencioso para não interromper fluxo se falhar deleção física
    }

    return ['estado' => 'publicado', 'msg' => 'media ' . $pub['id']];
}

/**
 * Localiza publicações em status 'agendado' ou 'processando' cujo horário agendado
 * já passou há mais de 15 minutos e as marca como 'erro' para que apareçam
 * no relatório de erros e possam ser republicadas.
 */
function pub_marcar_atrasados_como_erro(PDO $db): int
{
    $st = $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes
        SET status="erro", erro_msg="Atrasado / Não publicado no horário agendado (automação pausada ou cron inativo)", tentativas=tentativas+1
        WHERE status IN ("agendado", "processando") AND agendado_para < (NOW() - INTERVAL 15 MINUTE)');
    $st->execute();
    return $st->rowCount();
}
