<?php
declare(strict_types=1);

/**
 * lib_agendador.php
 * Cérebro da automação de stories/feed: mantém uma FILA de posts AGENDADOS
 * (futuros e revisáveis) distribuída ao longo do dia.
 *
 * Princípios da lógica:
 *  - Não publica na hora: cria posts com status "agendado" em horários FUTUROS,
 *    espaçados, para que você possa revisar/recusar antes de irem ao ar.
 *  - Distribuição: o intervalo é dinâmico = janela_restante / quantidade_a_postar,
 *    então se houver 200 matérias e o dia inteiro pela frente, ele espalha tudo.
 *  - Buffer: mantém só alguns posts à frente na fila (refaz conforme publica),
 *    evitando gerar 200 artes de uma vez.
 *  - Teto diário (ia_max_dia) e janela de horário (ia_hora_inicio/fim) sempre respeitados.
 *
 * Quem PUBLICA é o cron_publicar.php quando o horário chega.
 */

require_once __DIR__ . '/lib_noticias.php';
require_once __DIR__ . '/lib_gerador.php';
require_once __DIR__ . '/lib_temas.php';

/* Tipos de post a partir da config DO PERFIL: 'feed' | 'story' | 'ambos'. */
function agendador_tipos(int $clienteId): array
{
    $t = (string) pcfg_get($clienteId, 'ia_tipo_post', 'feed');
    if ($t === 'ambos') {
        return ['feed', 'story'];
    }
    return in_array($t, ['feed', 'story'], true) ? [$t] : ['feed'];
}

/* Legenda orgânica (sem custo). Story não usa legenda no IG. */
function agendador_legenda(array $noticia, string $tipo): string
{
    if ($tipo === 'story') {
        return '';
    }
    $leg = (string) ($noticia['titulo'] ?? '');
    $fonte = (string) ($noticia['fonte'] ?? '');
    if ($fonte !== '') {
        $leg .= "\n\nFonte: " . $fonte;
    }
    if (!empty($noticia['link'])) {
        $leg .= ' — ' . $noticia['link'];
    }
    return $leg;
}

/* Fragmento SQL de recência: só matérias DE HOJE EM DIANTE (dia atual). */
function agendador_recencia_sql(): string
{
    return 'COALESCE(data_pub_dt, descoberta_em) >= CURDATE()';
}

/* Candidatas frescas e RECENTES do pool DO PERFIL (noticias_descobertas = "nova"). */
function agendador_candidatas(PDO $db, int $clienteId, int $limite = 30): array
{
    $st = $db->prepare('SELECT titulo, fonte, link, imagem, resumo, data_pub, hash
        FROM ' . DB_PREFIX . 'noticias_descobertas
        WHERE cliente_id = ? AND status = "nova" AND ' . agendador_recencia_sql() . '
        ORDER BY COALESCE(data_pub_dt, descoberta_em) DESC
        LIMIT ' . (int) $limite);
    $st->execute([$clienteId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'titulo' => (string) $r['titulo'],
            'fonte'  => (string) $r['fonte'],
            'link'   => (string) $r['link'],
            'imagem' => (string) $r['imagem'],
            'resumo' => (string) ($r['resumo'] ?? ''),
            'data'   => (string) $r['data_pub'],
            'hash'   => (string) $r['hash'],
        ];
    }
    return $out;
}

/* Normaliza texto p/ comparação (minúsculo, sem acento). */
function agendador_norm(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
    ]);
}

/* Lê uma lista de palavras de uma config DO PERFIL (separadas por vírgula/linha). */
function agendador_lista_palavras(string $chave, int $clienteId): array
{
    $raw = (string) pcfg_get($clienteId, $chave, '');
    $itens = preg_split('/[,\r\n]+/', $raw) ?: [];
    $out = [];
    foreach ($itens as $p) {
        $p = trim(agendador_norm($p));
        if ($p !== '') { $out[] = $p; }
    }
    return $out;
}

/* Resolve se a notícia tem foto utilizável (RSS ou, se preciso, via scraper).
   Atualiza $noticia['imagem'] se achar pelo link. */
function agendador_tem_foto(array &$noticia): bool
{
    if (trim((string) ($noticia['imagem'] ?? '')) !== '') {
        return true;
    }
    $link = (string) ($noticia['link'] ?? '');
    if ($link === '') {
        return false;
    }
    $m = materia_extrair($link, (string) ($noticia['fonte'] ?? ''), (string) ($noticia['titulo'] ?? ''));
    if ($m['ok'] && !empty($m['imagens'])) {
        $noticia['imagem'] = (string) $m['imagens'][0];
        return true;
    }
    return false;
}

/* Aplica os filtros configuráveis. Retorna ['ok'=>bool,'motivo'=>string].
   Pode atualizar $noticia (ex.: foto resolvida pelo link). */
function agendador_filtrar(array &$noticia, int $clienteId): array
{
    $texto = agendador_norm(((string) ($noticia['titulo'] ?? '')) . ' ' . ((string) ($noticia['resumo'] ?? '')));

    // (1) bloquear palavras
    foreach (agendador_lista_palavras('ia_bloquear_palavras', $clienteId) as $p) {
        if ($p !== '' && mb_strpos($texto, $p) !== false) {
            return ['ok' => false, 'motivo' => 'palavra bloqueada: ' . $p];
        }
    }
    // (1b) exigir palavras (allowlist opcional)
    $exigir = agendador_lista_palavras('ia_exigir_palavras', $clienteId);
    if ($exigir) {
        $achou = false;
        foreach ($exigir as $p) {
            if ($p !== '' && mb_strpos($texto, $p) !== false) { $achou = true; break; }
        }
        if (!$achou) {
            return ['ok' => false, 'motivo' => 'sem palavra exigida'];
        }
    }
    // (2) exigir foto
    if ((string) pcfg_get($clienteId, 'ia_exigir_foto', '0') === '1' && !agendador_tem_foto($noticia)) {
        return ['ok' => false, 'motivo' => 'sem foto'];
    }
    return ['ok' => true, 'motivo' => ''];
}

/* Converte "HH:MM" em minutos do dia (0..1440). */
function agendador_min_dia(string $hhmm, int $padrao): int
{
    if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
        return min(1440, max(0, (int) $m[1] * 60 + (int) $m[2]));
    }
    if (preg_match('/^\d{1,2}$/', trim($hhmm))) { // aceita "23" => 23:00
        return min(1440, (int) $hhmm * 60);
    }
    return $padrao;
}

/* Notícia urgente? (palavras-chave configuráveis em ia_palavras_urgente). */
function agendador_eh_urgente(array $noticia, int $clienteId): bool
{
    $txt = agendador_norm(((string) ($noticia['titulo'] ?? '')) . ' ' . ((string) ($noticia['resumo'] ?? '')));
    foreach (agendador_lista_palavras('ia_palavras_urgente', $clienteId) as $p) {
        if ($p !== '' && mb_strpos($txt, $p) !== false) {
            return true;
        }
    }
    return false;
}

/* FRACIONAMENTO: divide o tempo restante do dia pela quantidade a postar.
   100 matérias e 600 min restantes => 6 min entre posts (respeitando o piso).
   Se a quantidade CAI, o intervalo cresce => a fila é re-espaçada (cobre o dia todo). */
function agendador_intervalo(int $restanteMin, int $quantidade, int $minInt, bool $distribuir): int
{
    if (!$distribuir) {
        return max($minInt, (int) cfg_get('ia_intervalo_min', '240'));
    }
    return (int) max($minInt, floor($restanteMin / max(1, $quantidade)));
}

/* RECUPERAÇÃO pós-queda: descarta agendamentos que ficaram MUITO atrasados (ex.: VPS/Apache
   caiu por horas) — não vale postar notícia velha; matérias frescas entram no lugar.
   Retorna quantos foram descartados. */
function agendador_descartar_velhos(PDO $db, int $clienteId, int $agora): int
{
    $atrasoMax = max(30, (int) cfg_get('ia_recuperar_descartar_min', '120')); // minutos
    $limite = date('Y-m-d H:i:s', $agora - $atrasoMax * 60);
    $rows = $db->query('SELECT id FROM ' . DB_PREFIX . 'publicacoes
        WHERE origem="ia" AND cliente_id=' . (int) $clienteId . ' AND status="agendado" AND agendado_para < ' . $db->quote($limite))->fetchAll();
    foreach ($rows as $r) {
        foreach ($db->query('SELECT arquivo FROM ' . DB_PREFIX . 'publicacao_midia WHERE publicacao_id=' . (int) $r['id'])->fetchAll() as $m) {
            $f = __DIR__ . '/' . ltrim((string) $m['arquivo'], '/');
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes SET status="cancelado", erro_msg="Descartado na recuperação (atrasado)" WHERE id=?')
           ->execute([(int) $r['id']]);
    }
    return count($rows);
}

/* Re-espaça TODA a fila (atrasados + futuros) pelo intervalo, ancorando em "agora" quando há
   atraso. É o que "reajusta os agendamentos" quando muda a quantidade de matérias E o que faz
   o sistema CONTINUAR de onde parou após uma queda, sem disparar tudo de uma vez. */
function agendador_rebalancear(PDO $db, int $clienteId, int $intervaloMin, int $agora): int
{
    $rows = $db->query('SELECT id, agendado_para FROM ' . DB_PREFIX . 'publicacoes
        WHERE origem="ia" AND cliente_id=' . (int) $clienteId . ' AND status="agendado"
        ORDER BY agendado_para, id')->fetchAll();
    if (!$rows) {
        return 0;
    }
    // âncora = agora se o próximo já está atrasado (recuperação); senão mantém o horário dele
    $anchor = max($agora, strtotime((string) $rows[0]['agendado_para']));
    $up = $db->prepare('UPDATE ' . DB_PREFIX . 'publicacoes SET agendado_para=? WHERE id=?');
    $i = 0;
    foreach ($rows as $r) {
        $up->execute([date('Y-m-d H:i:s', $anchor + $i * $intervaloMin * 60), (int) $r['id']]);
        $i++;
    }
    return count($rows);
}

/**
 * Cria UM post agendado (1 ou 2 publicações conforme tipo) para uma notícia.
 * Gera a arte na hora (custo zero no modo código) e deixa status "agendado".
 * @return array ['ok'=>bool,'ids'=>int[],'erro'=>string]
 */
function agendador_criar_post(PDO $db, int $clienteId, array $noticia, string $quando): array
{
    if (empty($noticia['titulo'])) {
        return ['ok' => false, 'ids' => [], 'erro' => 'Notícia inválida.'];
    }
    $ids = [];
    foreach (agendador_tipos($clienteId) as $tipo) {
        $legenda = agendador_legenda($noticia, $tipo);
        $db->prepare('INSERT INTO ' . DB_PREFIX . 'publicacoes
            (cliente_id, tipo, origem, legenda, fonte_nome, fonte_url, ia_dados, agendado_para, status)
            VALUES (?,?,?,?,?,?,?,?,"agendado")')
           ->execute([
               $clienteId, $tipo, 'ia', $legenda,
               mb_substr((string) $noticia['fonte'], 0, 160),
               mb_substr((string) $noticia['link'], 0, 512),
               json_encode($noticia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
               $quando,
           ]);
        $pubId = (int) $db->lastInsertId();

        // gera a arte/mídia agora (modo, formato e áudio conforme config)
        $mid = gerar_midia_publicacao($db, $pubId);
        if (!$mid['ok']) {
            // limpa o rascunho que não conseguiu arte
            $db->prepare('DELETE FROM ' . DB_PREFIX . 'publicacoes WHERE id=?')->execute([$pubId]);
            return ['ok' => false, 'ids' => $ids, 'erro' => $mid['erro']];
        }
        $ids[] = $pubId;
    }

    // marca a notícia como usada (não re-sugerir) e some da caixa de "novas" — DESTE perfil
    noticias_marcar_usada($db, $noticia, $clienteId);
    if (!empty($noticia['hash'])) {
        $db->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="gerada" WHERE cliente_id=? AND hash=?')
           ->execute([$clienteId, $noticia['hash']]);
    }
    return ['ok' => true, 'ids' => $ids, 'erro' => ''];
}

/* Quantos posts JÁ entram na cota de HOJE (agendados+processando+publicados, origem ia). */
function agendador_pub_hoje(PDO $db, int $clienteId): int
{
    return (int) $db->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes
        WHERE origem="ia" AND cliente_id=' . (int) $clienteId . ' AND status IN ("agendado","processando","publicado")
          AND agendado_para >= ' . $db->quote(date('Y-m-d 00:00:00')))->fetchColumn();
}

/**
 * Vagas reais p/ HOJE: cruza a trava dura diária (≤40), o limite opcional do perfil
 * E o limite AO VIVO da Meta (content_publishing_limit, com margem de segurança).
 * É o que faz o sistema "se moldar" para não bloquear.
 */
function agendador_slots_restantes(PDO $db, int $clienteId): int
{
    $capHard = max(1, (int) cfg_get('pub_cap_dia', '40'));
    $maxDia  = (int) pcfg_get($clienteId, 'ia_max_dia', '0');
    $cap = ($maxDia > 0) ? min($maxDia, $capHard) : $capHard;
    $slots = max(0, $cap - agendador_pub_hoje($db, $clienteId));

    // limite ao vivo da Meta (rolling 24h) menos uma margem p/ não encostar no teto
    $lim = pub_limite_status($clienteId);
    if (!empty($lim['ok'])) {
        $margem = max(0, (int) pcfg_get($clienteId, 'ia_limite_margem', '5'));
        $slots = min($slots, max(0, (int) $lim['restante'] - $margem));
    }
    return $slots;
}

/**
 * MESA DE REDAÇÃO (fluxo curadoria): candidatas a virar post AGORA =
 *   (a) matérias APROVADAS pelo redator-chefe  +  (b) ônibus (auto=1) ainda "nova".
 * Aprovadas primeiro; depois ônibus. Mantém a notícia inteira p/ gerar a arte.
 */
function curadoria_candidatas(PDO $db, int $clienteId, int $limite = 40): array
{
    // ônibus posta sozinho só se o toggle estiver ligado
    $onibusAuto = (string) pcfg_get($clienteId, 'ia_onibus_auto', '1') === '1';
    $clausulaAuto = $onibusAuto
        ? ' OR (status = "nova" AND auto = 1 AND veredito = "na_linha")'
        : '';
    $st = $db->prepare('SELECT titulo, fonte, link, imagem, resumo, data_pub, hash, status, tema, auto
        FROM ' . DB_PREFIX . 'noticias_descobertas
        WHERE cliente_id = ?
          AND ( status = "aprovada"' . $clausulaAuto . ' )
        ORDER BY (status = "aprovada") DESC, COALESCE(data_pub_dt, descoberta_em) DESC
        LIMIT ' . (int) $limite);
    $st->execute([$clienteId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'titulo' => (string) $r['titulo'],
            'fonte'  => (string) $r['fonte'],
            'link'   => (string) $r['link'],
            'imagem' => (string) $r['imagem'],
            'resumo' => (string) ($r['resumo'] ?? ''),
            'data'   => (string) $r['data_pub'],
            'hash'   => (string) $r['hash'],
            'tema'   => (string) ($r['tema'] ?? ''),
            'auto'   => (int) $r['auto'],
        ];
    }
    return $out;
}

/**
 * APROVAÇÃO INSTANTÂNEA: cria 1 post em Agendados na hora (chamado pela Mesa de Redação).
 * A arte (imagem) é gerada já (GD funciona na web); o ÁUDIO é finalizado pelo cron em ~1min
 * (shell/ffmpeg só roda no CLI). Agenda com folga p/ o áudio entrar antes de publicar.
 * @return array ['ok'=>bool,'ids'=>int[],'motivo'=>string]
 */
function curadoria_agendar_uma(PDO $db, int $clienteId, array $noticia): array
{
    if (agendador_slots_restantes($db, $clienteId) <= 0) {
        return ['ok' => false, 'ids' => [], 'motivo' => 'sem_vaga'];
    }
    $f = agendador_filtrar($noticia, $clienteId);
    if (!$f['ok']) {
        return ['ok' => false, 'ids' => [], 'motivo' => $f['motivo']];
    }

    $cli = (int) $clienteId;
    $P = fn(string $k, ?string $d = null) => pcfg_get($cli, $k, $d);
    $agora = time();
    $hoje0 = strtotime(date('Y-m-d 00:00:00'));
    $fimMin = agendador_min_dia((string) $P('ia_janela_fim', '23:30'), 1410);
    $fimJanelaTs = $hoje0 + $fimMin * 60;
    $minInt = max(2, (int) $P('ia_min_intervalo', '3'));
    $distribuir = (string) $P('ia_distribuir_dia', '1') === '1';

    $futuros = (int) $db->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes
        WHERE origem="ia" AND cliente_id=' . $cli . ' AND status="agendado" AND agendado_para > ' . $db->quote(date('Y-m-d H:i:s', $agora)))->fetchColumn();
    $ultimoAg = (string) $db->query('SELECT MAX(agendado_para) FROM ' . DB_PREFIX . 'publicacoes
        WHERE origem="ia" AND cliente_id=' . $cli . ' AND status="agendado" AND agendado_para > ' . $db->quote(date('Y-m-d H:i:s', $agora)))->fetchColumn();

    $restanteMin = max(1, (int) floor(($fimJanelaTs - $agora) / 60));
    $intervalo = agendador_intervalo($restanteMin, max(1, $futuros + 1), $minInt, $distribuir);
    $base = ($futuros > 0 && $ultimoAg) ? strtotime($ultimoAg) : $agora;

    // folga de 3 min p/ o cron finalizar o áudio antes do publicador pegar
    $slot = max($agora + 180, $base + $intervalo * 60);
    if ($slot > $fimJanelaTs) {
        $slot = min($fimJanelaTs, $agora + 180); // ainda HOJE
    }
    return agendador_criar_post($db, $cli, $noticia, date('Y-m-d H:i:s', $slot));
}

/**
 * Roda 1 ciclo do fluxo CURADORIA (redator-chefe). NÃO inventa nada:
 * só agenda o que o editor aprovou + o que é ônibus (auto). Respeita janela, cap 40/dia
 * e o limite ao vivo da Meta. A arte/áudio é gerada AQUI (CLI) → o áudio sempre funciona.
 */
function curadoria_rodar(PDO $db, int $clienteId): array
{
    $res = fn(string $a, string $d = '', int $c = 0) => ['acao' => $a, 'detalhe' => $d, 'criados' => $c];
    if ($clienteId <= 0) {
        return $res('sem_cliente', 'cliente inválido.');
    }
    $cli = (int) $clienteId;
    $P = fn(string $k, ?string $d = null) => pcfg_get($cli, $k, $d);

    $agora = time();
    $hoje0 = strtotime(date('Y-m-d 00:00:00'));
    $minAgora = (int) floor(($agora - $hoje0) / 60);
    $iniMin = agendador_min_dia((string) $P('ia_janela_inicio', '05:00'), 300);
    $fimMin = agendador_min_dia((string) $P('ia_janela_fim', '23:30'), 1410);
    $dentroJanela = ($minAgora >= $iniMin && $minAgora < $fimMin);
    $fimJanelaTs = $hoje0 + $fimMin * 60;

    $buffer  = max(1, (int) $P('ia_buffer', '6'));
    $perTick = max(1, (int) $P('ia_por_ciclo', '3'));
    $minInt  = max(2, (int) $P('ia_min_intervalo', '3'));
    $distribuir = (string) $P('ia_distribuir_dia', '1') === '1';

    $slots = agendador_slots_restantes($db, $cli);
    if ($slots <= 0) {
        return $res('teto', 'sem vagas hoje (cap 40/dia ou limite da Meta).');
    }

    // recuperação pós-queda + reajuste da fila existente
    $candidatas = curadoria_candidatas($db, $cli, 60);
    $temFila = (int) $db->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes
        WHERE origem="ia" AND cliente_id=' . $cli . ' AND status="agendado" AND agendado_para > ' . $db->quote(date('Y-m-d H:i:s', $agora)))->fetchColumn();

    $restanteMin = max(1, (int) floor(($fimJanelaTs - $agora) / 60));
    $alvo = max(1, $temFila + count($candidatas));
    $intervalo = agendador_intervalo($restanteMin, $alvo, $minInt, $distribuir);

    agendador_descartar_velhos($db, $cli, $agora);
    agendador_rebalancear($db, $cli, $intervalo, $agora);

    if (!$candidatas) {
        return $res('sem_aprovadas', 'nada aprovado/ônibus para agendar.');
    }

    $ultimoAg = (string) $db->query('SELECT MAX(agendado_para) FROM ' . DB_PREFIX . 'publicacoes
        WHERE origem="ia" AND cliente_id=' . $cli . ' AND status="agendado" AND agendado_para > ' . $db->quote(date('Y-m-d H:i:s', $agora)))->fetchColumn();
    $baseTs = ($temFila > 0 && $ultimoAg) ? strtotime($ultimoAg) : ($agora - $intervalo * 60);

    $vagas = max(0, $buffer - $temFila);
    $gerar = min($vagas, $perTick, $slots);
    $criados = 0; $k = 0;
    foreach ($candidatas as $noticia) {
        if ($k >= $gerar) { break; }
        // ônibus (auto) pode sair na hora; aprovadas distribuídas na janela
        $ehOnibus = ((int) ($noticia['auto'] ?? 0) === 1);
        if (!$dentroJanela && !$ehOnibus) { continue; }

        $f = agendador_filtrar($noticia, $cli);
        if (!$f['ok']) {
            $db->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="ignorada", motivo=? WHERE cliente_id=? AND hash=?')
               ->execute([mb_substr('descartada: ' . $f['motivo'], 0, 180), $cli, $noticia['hash']]);
            continue;
        }
        if (noticias_assunto_repetido($db, noticias_assinatura((string) $noticia['titulo']), $cli)) {
            $db->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="gerada" WHERE cliente_id=? AND hash=?')->execute([$cli, $noticia['hash']]);
            continue;
        }
        $quando = $ehOnibus
            ? date('Y-m-d H:i:s', $agora)
            : date('Y-m-d H:i:s', max($agora, $baseTs + ($k + 1) * $intervalo * 60));
        if (strtotime($quando) > $fimJanelaTs && !$ehOnibus) { break; }

        $r = agendador_criar_post($db, $cli, $noticia, $quando);
        if ($r['ok']) { $k++; $criados++; }
    }

    return $res('ok', "intervalo {$intervalo}min · agendados {$criados} · fila {$temFila} · vagas {$slots}", $criados);
}

/**
 * Roda 1 ciclo do agendador automático (chamado pelo cron a cada minuto).
 * @return array ['acao'=>string,'detalhe'=>string,'criados'=>int]
 */
function agendador_rodar(PDO $db, int $clienteId): array
{
    $res = fn(string $a, string $d = '', int $c = 0) => ['acao' => $a, 'detalhe' => $d, 'criados' => $c];
    if ($clienteId <= 0) {
        return $res('sem_cliente', 'cliente inválido.');
    }
    $P = fn(string $k, ?string $d = null) => pcfg_get($clienteId, $k, $d); // config DO PERFIL
    $cli = (int) $clienteId;

    $agora = time();
    $hoje0 = strtotime(date('Y-m-d 00:00:00'));
    $minAgora = (int) floor(($agora - $hoje0) / 60);
    $iniMin = agendador_min_dia((string) $P('ia_janela_inicio', '05:00'), 300);
    $fimMin = agendador_min_dia((string) $P('ia_janela_fim', '23:30'), 1410);
    $dentroJanela = ($minAgora >= $iniMin && $minAgora < $fimMin);
    $fimJanelaTs = $hoje0 + $fimMin * 60;

    $buffer  = max(1, (int) $P('ia_buffer', '6'));
    $perTick = max(1, (int) $P('ia_por_ciclo', '3'));
    $minInt  = max(2, (int) $P('ia_min_intervalo', '3'));
    $maxDia  = (int) $P('ia_max_dia', '0'); // 0 = sem limite PROPRIO do perfil
    $distribuir = (string) $P('ia_distribuir_dia', '1') === '1';

    // Teto efetivo do dia = trava dura global (pub_cap_dia, padrao 45) cruzada com
    // o limite opcional do perfil. SEMPRE aplicado -> garante <=45 e espalha a fila.
    $capHard = max(1, (int) cfg_get('pub_cap_dia', '40')); // trava dura; ver PUB_CAP_DIA_PADRAO no publicador
    $capDia  = ($maxDia > 0) ? min($maxDia, $capHard) : $capHard;

    $pubHoje = agendador_pub_hoje($db, $cli);
    if ($pubHoje >= $capDia) {
        return $res('teto_dia', "teto {$capDia}/dia atingido");
    }
    // anti-bloqueio: respeita o limite AO VIVO da Meta (com margem)
    if (agendador_slots_restantes($db, $cli) <= 0) {
        return $res('limite_meta', 'limite da Meta (24h) — pausando para não bloquear');
    }

    $criados = 0;

    // ===== 1) URGENTES: a qualquer hora, na hora (máx ia_urgente_por_ciclo) =====
    $maxUrg = max(0, (int) $P('ia_urgente_por_ciclo', '1'));
    $urg = 0;
    foreach (agendador_candidatas($db, $cli, 60) as $noticia) {
        if ($urg >= $maxUrg) { break; }
        if (!agendador_eh_urgente($noticia, $cli)) { continue; }
        $f = agendador_filtrar($noticia, $cli);
        if (!$f['ok']) {
            $db->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="ignorada" WHERE cliente_id=? AND hash=?')->execute([$cli, $noticia['hash']]);
            continue;
        }
        if (noticias_assunto_repetido($db, noticias_assinatura((string) $noticia['titulo']), $cli)) {
            $db->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="gerada" WHERE cliente_id=? AND hash=?')->execute([$cli, $noticia['hash']]);
            continue;
        }
        $r = agendador_criar_post($db, $cli, $noticia, date('Y-m-d H:i:s', $agora));
        if ($r['ok']) { $urg++; $criados++; }
    }

    if (!$dentroJanela) {
        return $res('fora_janela', $criados > 0 ? "urgentes: {$criados}" : 'fora da janela (sem urgentes).', $criados);
    }

    // ===== 2) DISTRIBUIÇÃO: fraciona o restante do dia na janela =====
    $futuros = (int) $db->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacoes
        WHERE origem="ia" AND cliente_id=' . $cli . ' AND status="agendado" AND agendado_para > ' . $db->quote(date('Y-m-d H:i:s', $agora)))->fetchColumn();
    $poolSt = $db->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'noticias_descobertas WHERE cliente_id=? AND status="nova" AND ' . agendador_recencia_sql());
    $poolSt->execute([$cli]);
    $pool = (int) $poolSt->fetchColumn();

    $restanteMin = max(1, (int) floor(($fimJanelaTs - $agora) / 60));
    $alvo = $futuros + $pool;
    if ($maxDia > 0) { $alvo = min($alvo, $maxDia); }
    $intervalo = agendador_intervalo($restanteMin, $alvo, $minInt, $distribuir);

    // ===== 3) recuperação pós-queda + reajuste da fila =====
    agendador_descartar_velhos($db, $cli, $agora);
    agendador_rebalancear($db, $cli, $intervalo, $agora);

    $ultimoAg = (string) $db->query('SELECT MAX(agendado_para) FROM ' . DB_PREFIX . 'publicacoes
        WHERE origem="ia" AND cliente_id=' . $cli . ' AND status="agendado" AND agendado_para > ' . $db->quote(date('Y-m-d H:i:s', $agora)))->fetchColumn();
    $baseTs = ($futuros > 0 && $ultimoAg) ? strtotime($ultimoAg) : ($agora - $intervalo * 60);

    // ===== 4) reabastece o buffer =====
    $vagas = max(0, $buffer - $futuros);
    $gerar = min($vagas, $perTick - $criados);
    $k = 0;
    foreach (agendador_candidatas($db, $cli, 60) as $noticia) {
        if ($k >= $gerar) { break; }
        if (agendador_eh_urgente($noticia, $cli)) { continue; }
        $f = agendador_filtrar($noticia, $cli);
        if (!$f['ok']) {
            $db->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="ignorada" WHERE cliente_id=? AND hash=?')->execute([$cli, $noticia['hash']]);
            continue;
        }
        if (noticias_assunto_repetido($db, noticias_assinatura((string) $noticia['titulo']), $cli)) {
            $db->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="gerada" WHERE cliente_id=? AND hash=?')->execute([$cli, $noticia['hash']]);
            continue;
        }
        $slotTs = $baseTs + ($k + 1) * $intervalo * 60;
        if ($slotTs > $fimJanelaTs) { break; }
        $r = agendador_criar_post($db, $cli, $noticia, date('Y-m-d H:i:s', $slotTs));
        if ($r['ok']) { $k++; }
    }
    $criados += $k;

    return $res('ok', "intervalo {$intervalo}min · urgentes+novos {$criados} · fila {$futuros}+{$k} · pool {$pool}", $criados);
}
