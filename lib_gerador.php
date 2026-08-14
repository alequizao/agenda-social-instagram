<?php
declare(strict_types=1);

/**
 * lib_gerador.php
 * Geracao de posts de noticia em DUAS etapas (para economizar API):
 *   1) gerar_rascunho_noticia()  -> coleta + legenda (TEXTO barato), SEM imagem.
 *                                   Cria rascunho "aguardando_aprovacao".
 *   2) gerar_midia_publicacao()  -> so na APROVACAO: gera a IMAGEM (cara) + arte
 *                                   e anexa a midia ao post.
 * Assim a imagem (gpt-image, ~US$0,04) so e gerada no que voce realmente aprova.
 */

require_once __DIR__ . '/lib_noticias.php';
require_once __DIR__ . '/lib_ia.php';
require_once __DIR__ . '/lib_arte.php';
require_once __DIR__ . '/lib_materia.php';
require_once __DIR__ . '/lib_arte_codigo.php';
require_once __DIR__ . '/lib_video.php';

/* Opções de arte por código a partir das configurações DO PERFIL. */
function gerador_arte_opts(int $clienteId): array
{
    return [
        'template' => (int) pcfg_get($clienteId, 'ia_template', '1'),
        'handle'   => (string) pcfg_get($clienteId, 'ia_handle', '@tevinobuzao'),
        'cenario'  => (string) pcfg_get($clienteId, 'ia_cenario', 'onibus'), // onibus | off
    ];
}

/* Anexa áudio (gera MP4) se ligado e disponível no PERFIL. Retorna [arquivoRel, tipoMidia]. */
function gerador_talvez_audio(string $arquivoRel, int $clienteId): array
{
    if ((string) pcfg_get($clienteId, 'ia_audio_ativo', '0') !== '1') {
        return [$arquivoRel, 'imagem'];
    }
    $audioRel = (string) pcfg_get($clienteId, 'ia_audio_arquivo', '');
    if ($audioRel === '' || !is_file(__DIR__ . '/' . ltrim($audioRel, '/'))) {
        return [$arquivoRel, 'imagem'];
    }
    $imgAbs = __DIR__ . '/' . ltrim($arquivoRel, '/');
    $mp4Rel = preg_replace('/\.jpg$/i', '.mp4', $arquivoRel);
    $destAbs = __DIR__ . '/' . ltrim($mp4Rel, '/');
    $v = video_de_imagem($imgAbs, __DIR__ . '/' . ltrim($audioRel, '/'), $destAbs, (int) pcfg_get($clienteId, 'ia_audio_seg', '8'));
    return $v['ok'] ? [$mp4Rel, 'video'] : [$arquivoRel, 'imagem'];
}

/**
 * SELF-HEAL DO ÁUDIO (rodado pelo cron/CLI).
 * No site (PHP-FPM) o shell_exec está bloqueado, então um post gerado pela web sai
 * como IMAGEM (sem som). Aqui o cron transforma esses posts em MP4 com a trilha.
 * Bounded: poucos por ciclo p/ não pesar. Só toca posts IA não publicados.
 * @return int quantos foram convertidos
 */
function gerador_finalizar_audio(PDO $db, int $clienteId, int $maxPorCiclo = 4): int
{
    if ((string) pcfg_get($clienteId, 'ia_audio_ativo', '0') !== '1' || !video_shell_ok()) {
        return 0;
    }
    $audioRel = (string) pcfg_get($clienteId, 'ia_audio_arquivo', '');
    $audioAbs = __DIR__ . '/' . ltrim($audioRel, '/');
    if ($audioRel === '' || !is_file($audioAbs)) {
        return 0;
    }
    $st = $db->prepare('SELECT pm.id mid, pm.arquivo FROM ' . DB_PREFIX . 'publicacao_midia pm
        JOIN ' . DB_PREFIX . 'publicacoes p ON p.id = pm.publicacao_id
        WHERE p.origem = "ia" AND p.cliente_id = ?
          AND p.status IN ("agendado","aguardando_aprovacao","revisao_arte")
          AND pm.tipo = "imagem" AND pm.arquivo LIKE "%.jpg"
        ORDER BY pm.id DESC LIMIT ' . (int) max(1, $maxPorCiclo));
    $st->execute([$clienteId]);
    $rows = $st->fetchAll();
    if (!$rows) {
        return 0;
    }
    $seg = (int) pcfg_get($clienteId, 'ia_audio_seg', '8');
    $n = 0;
    foreach ($rows as $r) {
        $imgRel = (string) $r['arquivo'];
        $imgAbs = __DIR__ . '/' . ltrim($imgRel, '/');
        if (!is_file($imgAbs)) {
            continue;
        }
        $mp4Rel = preg_replace('/\.jpg$/i', '.mp4', $imgRel);
        $destAbs = __DIR__ . '/' . ltrim($mp4Rel, '/');
        $v = video_de_imagem($imgAbs, $audioAbs, $destAbs, $seg);
        if ($v['ok']) {
            $url = rtrim(BASE_URL, '/') . '/' . ltrim($mp4Rel, '/');
            $db->prepare('UPDATE ' . DB_PREFIX . 'publicacao_midia SET tipo="video", arquivo=?, url_publica=? WHERE id=?')
               ->execute([$mp4Rel, $url, (int) $r['mid']]);
            $n++;
        }
    }
    return $n;
}

/* Constrói a arte (imagem) de uma notícia conforme o MODO configurado.
   @return array ['ok'=>bool,'arquivo'=>relativo,'erro'=>string] */
function gerador_construir_arte(PDO $db, int $clienteId, array $noticia, string $formato): array
{
    $modo = (string) pcfg_get($clienteId, 'ia_modo_geracao', 'codigo');
    if ($modo === 'ia') {
        $img = ia_gerar_imagem($db, $noticia, $clienteId);
        if (!$img['ok']) {
            return ['ok' => false, 'erro' => 'Imagem IA: ' . $img['erro']];
        }
        $arte = arte_compor($img['png'], (string) $noticia['titulo'], (string) $noticia['fonte'], $clienteId, (string) ($noticia['imagem'] ?? ''));
        return $arte['ok'] ? ['ok' => true, 'arquivo' => $arte['arquivo']] : ['ok' => false, 'erro' => 'Arte: ' . $arte['erro']];
    }
    // modo = codigo (orgânico, sem custo): enriquece pelo link e compõe
    $materia = [
        'titulo'  => (string) ($noticia['titulo'] ?? ''),
        'fonte'   => (string) ($noticia['fonte'] ?? ''),
        'data'    => (string) ($noticia['data'] ?? ''),
        'link'    => (string) ($noticia['link'] ?? ''),
        'imagens' => array_filter([(string) ($noticia['imagem'] ?? '')]),
    ];
    $link = (string) ($noticia['link'] ?? '');
    if ($link !== '') {
        $m = materia_extrair($link, $materia['fonte'], $materia['titulo']);
        if ($m['ok']) {
            if ($m['titulo'] !== '') { $materia['titulo'] = $m['titulo']; }
            if (!empty($m['imagens'])) { $materia['imagens'] = $m['imagens']; }
            if ($m['data'] !== '') { $materia['data'] = $m['data']; }
            if ($materia['fonte'] === '') { $materia['fonte'] = $m['fonte']; }
        }
    }
    $opts = gerador_arte_opts($clienteId);
    $opts['formato'] = $formato;
    // CTA "comente e receba o link" só faz sentido no story E se a resposta automática estiver ligada
    if ($formato === 'story' && (string) pcfg_get($clienteId, 'dm_story_link_ativo', '0') === '1') {
        $opts['cta_link'] = (string) pcfg_get($clienteId, 'ia_cta_link_texto', 'Comente aqui e receba o link da matéria');
    }
    return arte_codigo_compor($materia, $clienteId, $opts);
}

/**
 * Cria 1 rascunho automaticamente (escolhe a melhor noticia fresca).
 * @return array ['ok'=>bool,'pub_id'=>int,'titulo'=>string,'erro'=>string]
 */
function gerar_rascunho_noticia(PDO $db, int $clienteId): array
{
    $noticia = noticias_escolher($db, $clienteId);
    if (!$noticia) {
        return ['ok' => false, 'erro' => 'Nenhuma noticia nova (ou so repeticoes de assuntos do dia).'];
    }
    return gerar_rascunho_de($db, $clienteId, $noticia);
}

/**
 * Cria 1 rascunho em "aguardando_aprovacao" SEM imagem, a partir de uma noticia ESCOLHIDA.
 * @return array ['ok'=>bool,'pub_id'=>int,'titulo'=>string,'erro'=>string]
 */
function gerar_rascunho_de(PDO $db, int $clienteId, array $noticia): array
{
    $cs = $db->prepare('SELECT id FROM ' . DB_PREFIX . 'clientes WHERE id = ? LIMIT 1');
    $cs->execute([$clienteId]);
    if (!$cs->fetch()) {
        return ['ok' => false, 'erro' => 'Cliente alvo nao existe.'];
    }
    if (empty($noticia['titulo'])) {
        return ['ok' => false, 'erro' => 'Noticia invalida.'];
    }

    $runStart = date('Y-m-d H:i:s');
    $tipo = (string) pcfg_get($clienteId, 'ia_tipo_post', 'feed');

    // Story NAO usa legenda no Instagram -> nao chama a IA de texto (sem gasto).
    if ($tipo === 'story') {
        $legenda = '';
    } else {
        // legenda (TEXTO, custo irrisorio); fallback = manchete real
        $leg = ia_legenda($db, $noticia, $clienteId);
        $legenda = $leg['ok'] ? $leg['texto'] : (string) $noticia['titulo'];
        $legenda .= "\n\nFonte: " . $noticia['fonte'];
        if (!empty($noticia['link'])) {
            $legenda .= ' — ' . $noticia['link'];
        }
    }

    $db->beginTransaction();
    try {
        $ins = $db->prepare('INSERT INTO ' . DB_PREFIX . 'publicacoes
            (cliente_id, tipo, origem, legenda, fonte_nome, fonte_url, ia_dados, agendado_para, status)
            VALUES (?,?,?,?,?,?,?,?,"aguardando_aprovacao")');
        $ins->execute([
            $clienteId, $tipo, 'ia', $legenda,
            mb_substr((string) $noticia['fonte'], 0, 160),
            mb_substr((string) $noticia['link'], 0, 512),
            json_encode($noticia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            date('Y-m-d H:i:s'),
        ]);
        $pubId = (int) $db->lastInsertId();

        // vincula o custo da legenda a este post
        $db->prepare('UPDATE ' . DB_PREFIX . 'custos_ia
            SET publicacao_id = ? WHERE publicacao_id IS NULL AND criado_em >= ?')
           ->execute([$pubId, $runStart]);

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        return ['ok' => false, 'erro' => 'Gravacao: ' . $ex->getMessage()];
    }

    // marca como usada JA (nao re-sugerir, mesmo que seja reprovada depois) — DESTE perfil
    noticias_marcar_usada($db, $noticia, $clienteId);

    // marca na caixa de descobertas como "gerada"
    if (!empty($noticia['hash'])) {
        $db->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas SET status="gerada" WHERE cliente_id=? AND hash=?')
           ->execute([$clienteId, $noticia['hash']]);
    }

    return ['ok' => true, 'pub_id' => $pubId, 'titulo' => (string) $noticia['titulo']];
}

/**
 * Gera a IMAGEM (cara) + arte de um rascunho e anexa a midia.
 * Chamado no momento da APROVACAO. Nao altera o status (quem chama decide).
 * Idempotente: se ja houver midia, nao gera de novo (evita custo duplicado).
 * @return array ['ok'=>bool,'arquivo'=>string,'erro'=>string]
 */
function gerar_midia_publicacao(PDO $db, int $pubId): array
{
    $st = $db->prepare('SELECT id, cliente_id, ia_dados, tipo FROM ' . DB_PREFIX . 'publicacoes WHERE id = ? LIMIT 1');
    $st->execute([$pubId]);
    $pub = $st->fetch();
    if (!$pub) {
        return ['ok' => false, 'erro' => 'Publicacao nao encontrada.'];
    }

    // ja tem midia? nao gera de novo
    $jm = $db->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'publicacao_midia WHERE publicacao_id = ?');
    $jm->execute([$pubId]);
    if ((int) $jm->fetchColumn() > 0) {
        return ['ok' => true, 'arquivo' => ''];
    }

    $noticia = json_decode((string) $pub['ia_dados'], true);
    if (!is_array($noticia) || empty($noticia['titulo'])) {
        return ['ok' => false, 'erro' => 'Dados da noticia ausentes neste rascunho (gerado em versao antiga?).'];
    }
    $clienteId = (int) $pub['cliente_id'];
    $runStart  = date('Y-m-d H:i:s');
    $formato   = ((string) $pub['tipo'] === 'story') ? 'story' : 'feed';

    // 1+2) arte conforme o MODO (IA ou Codigo organico) e o formato
    $arte = gerador_construir_arte($db, $clienteId, $noticia, $formato);
    if (!$arte['ok']) {
        return ['ok' => false, 'erro' => $arte['erro']];
    }
    // 3) audio opcional -> vira MP4
    [$arquivo, $tipoMidia] = gerador_talvez_audio($arte['arquivo'], $clienteId);
    $urlPublica = rtrim(BASE_URL, '/') . '/' . ltrim($arquivo, '/');

    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO ' . DB_PREFIX . 'publicacao_midia
            (publicacao_id, posicao, tipo, arquivo, url_publica) VALUES (?,?,?,?,?)')
           ->execute([$pubId, 0, $tipoMidia, $arquivo, $urlPublica]);

        $db->prepare('UPDATE ' . DB_PREFIX . 'custos_ia
            SET publicacao_id = ? WHERE publicacao_id IS NULL AND criado_em >= ?')
           ->execute([$pubId, $runStart]);

        $db->commit();
    } catch (Throwable $ex) {
        $db->rollBack();
        return ['ok' => false, 'erro' => 'Gravacao: ' . $ex->getMessage()];
    }

    return ['ok' => true, 'arquivo' => $arquivo];
}
