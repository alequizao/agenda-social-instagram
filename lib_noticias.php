<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * lib_noticias.php
 * Coleta de noticias por RSS (portais de Alagoas/Maceio).
 *
 * - Le os feeds configurados em `configuracoes.ia_feeds`.
 * - Normaliza cada item: titulo, resumo, link, imagem, fonte, data.
 * - Deduplica contra a tabela `noticias_usadas` (anti-repeticao).
 *
 * Nao depende de nada externo: cURL + SimpleXML (DOM como fallback).
 */

/* ---- Baixa o XML de um feed ---- */
function noticias_baixar(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'AgendaSocialBot/1.0 (+https://publishdev.com.br/agendamentos)',
            CURLOPT_HTTPHEADER     => ['Accept: application/rss+xml, application/xml, text/xml'],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($errno || !is_string($body) || $body === '') {
            return null;
        }
        return $body;
    }
    $body = @file_get_contents($url);
    return is_string($body) && $body !== '' ? $body : null;
}

/* ---- Tenta extrair a 1a imagem de um item RSS (varios formatos comuns) ---- */
function noticias_extrair_imagem(SimpleXMLElement $item): string
{
    // media:content / media:thumbnail (namespace media)
    $media = $item->children('http://search.yahoo.com/mrss/');
    if ($media) {
        foreach (['content', 'thumbnail'] as $tag) {
            if (isset($media->$tag)) {
                $u = (string) ($media->$tag->attributes()->url ?? '');
                if ($u !== '') {
                    return $u;
                }
            }
        }
    }
    // enclosure url="..."
    if (isset($item->enclosure)) {
        $type = (string) ($item->enclosure->attributes()->type ?? '');
        $u    = (string) ($item->enclosure->attributes()->url ?? '');
        if ($u !== '' && ($type === '' || stripos($type, 'image') !== false)) {
            return $u;
        }
    }
    // <img src> dentro de content:encoded ou description
    $content = '';
    $enc = $item->children('http://purl.org/rss/1.0/modules/content/');
    if ($enc && isset($enc->encoded)) {
        $content = (string) $enc->encoded;
    }
    if ($content === '') {
        $content = (string) ($item->description ?? '');
    }
    if ($content !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
}

/* ---- Resumo limpo (sem HTML), curto ---- */
function noticias_resumo(string $html, int $max = 240): string
{
    $txt = trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    $txt = preg_replace('/\s+/u', ' ', $txt) ?? '';
    if (mb_strlen($txt) > $max) {
        $txt = mb_substr($txt, 0, $max - 1) . '…';
    }
    return $txt;
}

/* ---- Le UM feed e retorna itens normalizados ---- */
function noticias_ler_feed(array $feed): array
{
    $url  = (string) ($feed['url'] ?? '');
    $fonte = (string) ($feed['nome'] ?? '');
    if ($url === '') {
        return [];
    }
    $xml = noticias_baixar($url);
    if ($xml === null) {
        return [];
    }
    $prev = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if ($sx === false) {
        return [];
    }

    // RSS 2.0 (channel/item) ou Atom (entry)
    $itens = [];
    $nodes = [];
    if (isset($sx->channel->item)) {
        $nodes = $sx->channel->item;
    } elseif (isset($sx->entry)) {
        $nodes = $sx->entry;
    }

    foreach ($nodes as $it) {
        $titulo = trim((string) ($it->title ?? ''));
        if ($titulo === '') {
            continue;
        }
        // link: RSS usa <link>texto; Atom usa <link href="">
        $link = trim((string) ($it->link ?? ''));
        if ($link === '' && isset($it->link['href'])) {
            $link = (string) $it->link['href'];
        }
        $descricao = (string) ($it->description ?? $it->summary ?? '');
        $data = (string) ($it->pubDate ?? $it->updated ?? $it->published ?? '');

        $itens[] = [
            'titulo' => html_entity_decode($titulo, ENT_QUOTES, 'UTF-8'),
            'resumo' => noticias_resumo($descricao),
            'link'   => $link,
            'imagem' => noticias_extrair_imagem($it),
            'fonte'  => $fonte,
            'data'   => $data,
            'hash'   => sha1($link !== '' ? $link : $titulo),
        ];
    }
    return $itens;
}

/* ---- Coleta BRUTA dos feeds DO PERFIL (hash => item), sem filtrar usadas ---- */
function noticias_coletar_brutas(int $clienteId): array
{
    $todos = [];
    foreach (pcfg_feeds($clienteId) as $feed) {
        foreach (noticias_ler_feed($feed) as $item) {
            $todos[$item['hash']] = $item; // dedup por hash dentro da coleta
        }
    }
    return $todos;
}

/* ---- Salva tudo que foi descoberto (scrap completo) p/ um PERFIL; retorna quantas SAO NOVAS ---- */
function noticias_sincronizar(PDO $db, int $clienteId): int
{
    $todos = noticias_coletar_brutas($clienteId);
    if (!$todos) {
        return 0;
    }
    $ins = $db->prepare('INSERT IGNORE INTO ' . DB_PREFIX . 'noticias_descobertas
        (cliente_id, hash, titulo, link, fonte, imagem, resumo, data_pub, data_pub_dt) VALUES (?,?,?,?,?,?,?,?,?)');
    $novas = 0;
    foreach ($todos as $n) {
        $ts = strtotime((string) ($n['data'] ?? ''));
        $ins->execute([
            $clienteId,
            $n['hash'],
            mb_substr((string) $n['titulo'], 0, 300),
            mb_substr((string) $n['link'], 0, 512),
            mb_substr((string) $n['fonte'], 0, 160),
            mb_substr((string) ($n['imagem'] ?? ''), 0, 700),
            mb_substr((string) ($n['resumo'] ?? ''), 0, 500),
            mb_substr((string) ($n['data'] ?? ''), 0, 60),
            $ts !== false ? date('Y-m-d H:i:s', $ts) : null,
        ]);
        $novas += $ins->rowCount(); // 1 = inserida (nova), 0 = ja existia
    }
    return $novas;
}

/* ---- Formata a data de publicacao da materia (pubDate do RSS) p/ exibir ---- */
function noticias_data_fmt(string $raw, string $formato = 'd/m/Y H:i', string $fallback = ''): string
{
    $raw = trim($raw);
    if ($raw !== '') {
        $ts = strtotime($raw);
        if ($ts !== false) {
            return date($formato, $ts);
        }
    }
    return $fallback;
}

/* ---- Quantas noticias estao marcadas como "nova" (badge) ---- */
function noticias_novas_count(PDO $db, int $clienteId): int
{
    $st = $db->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'noticias_descobertas WHERE cliente_id=? AND status="nova"');
    $st->execute([$clienteId]);
    return (int) $st->fetchColumn();
}

/* ---- Coleta dos feeds DO PERFIL, ja sem as ja usadas ---- */
function noticias_coletar(PDO $db, int $clienteId): array
{
    $todos = noticias_coletar_brutas($clienteId);
    if (!$todos) {
        return [];
    }

    // remove as ja usadas (deste perfil)
    $hashes = array_keys($todos);
    $in = implode(',', array_fill(0, count($hashes), '?'));
    $st = $db->prepare('SELECT hash FROM ' . DB_PREFIX . 'noticias_usadas WHERE cliente_id=? AND hash IN (' . $in . ')');
    $st->execute(array_merge([$clienteId], $hashes));
    foreach ($st->fetchAll() as $r) {
        unset($todos[$r['hash']]);
    }

    return array_values($todos);
}

/* ---- Assinatura de ASSUNTO: tokens significativos do titulo (sem acento/stopwords) ---- */
function noticias_assinatura(string $titulo): array
{
    $t = mb_strtolower($titulo, 'UTF-8');
    $t = strtr($t, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
    ]);
    $t = preg_replace('/[^a-z0-9\s]/u', ' ', $t) ?? '';
    static $stop = [
        'a','o','os','as','um','uma','uns','umas','de','do','da','dos','das','no','na','nos','nas',
        'em','por','para','pra','com','sem','sob','sobre','ou','que','se','ao','aos','pelo','pela',
        'apos','contra','entre','ate','mais','menos','seu','sua','seus','suas','este','esta','isso',
        'foi','sao','ser','tem','tera','sera','vai','vao','dia','dias','novo','nova','diz','traz',
        'apenas','ainda','como','onde','quando','sao','tras','vão','são','após',
    ];
    $tokens = [];
    foreach (preg_split('/\s+/', trim($t)) ?: [] as $w) {
        if (mb_strlen($w) >= 4 && !in_array($w, $stop, true)) {
            $tokens[$w] = true;
        }
    }
    return array_keys($tokens);
}

/* ---- Ja saiu algo do MESMO assunto HOJE? (anti-repeticao por tema, sem custo de API)
   Compara os tokens do titulo candidato com os titulos ja usados hoje.
   Se a sobreposicao for alta -> mesmo assunto -> pula. Titulo bem diferente = novidade = passa. */
function noticias_assunto_repetido(PDO $db, array $sig, int $clienteId, float $limiar = 0.6): bool
{
    if (count($sig) < 2) {
        return false; // titulo curto demais p/ julgar com seguranca
    }
    $st = $db->prepare('SELECT titulo FROM ' . DB_PREFIX . 'noticias_usadas WHERE cliente_id=? AND criado_em >= ?');
    $st->execute([$clienteId, date('Y-m-d 00:00:00')]);
    foreach ($st->fetchAll() as $r) {
        $sig2 = noticias_assinatura((string) $r['titulo']);
        $base = min(count($sig), count($sig2));
        if ($base > 0 && (count(array_intersect($sig, $sig2)) / $base) >= $limiar) {
            return true; // mesmo assunto ja usado hoje
        }
    }
    return false;
}

/* ---- Escolhe a melhor noticia fresca (preferindo as que tem imagem) ---- */
function noticias_escolher(PDO $db, int $clienteId): ?array
{
    $itens = noticias_coletar($db, $clienteId);
    if (!$itens) {
        return null;
    }
    // prioriza itens COM imagem (melhor material p/ a cena), mantendo ordem dos feeds
    usort($itens, fn($a, $b) => ($b['imagem'] !== '') <=> ($a['imagem'] !== ''));
    // pula o que for repeticao de assunto ja publicado HOJE (economiza API)
    foreach ($itens as $it) {
        if (!noticias_assunto_repetido($db, noticias_assinatura((string) $it['titulo']), $clienteId)) {
            return $it;
        }
    }
    return null; // so sobrou repeticao de assuntos do dia -> nao gera (sem gasto)
}

/* ---- Marca uma noticia como usada ---- */
function noticias_marcar_usada(PDO $db, array $n, int $clienteId): void
{
    $db->prepare('INSERT IGNORE INTO ' . DB_PREFIX . 'noticias_usadas (cliente_id, hash, titulo, link, fonte)
        VALUES (?,?,?,?,?)')->execute([
        $clienteId,
        $n['hash'],
        mb_substr((string) $n['titulo'], 0, 300),
        mb_substr((string) $n['link'], 0, 512),
        mb_substr((string) $n['fonte'], 0, 160),
    ]);
}
