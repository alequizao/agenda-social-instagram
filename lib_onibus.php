<?php
declare(strict_types=1);

/**
 * lib_onibus.php — Horário dos ônibus de Maceió/Rio Largo.
 *
 * Fonte de dados: CittaMobi (linhas.cittamobi.com.br + core.cittamobi.com.br).
 * NÃO é API oficial da SMTT: é a mesma origem que o site público do CittaMobi
 * consome. Por isso o token e as URLs ficam em config (editáveis) — se a
 * CittaMobi mudar, dá para ajustar sem mexer no código.
 *
 * Duas camadas:
 *   1) CATÁLOGO + HORÁRIOS (estável): raspa a página da cidade (catálogo de
 *      linhas em JSON embutido) e a página de cada linha (horários por dia,
 *      paradas e o serviceId). Roda no CRON (cron_onibus.php), nunca no load web.
 *   2) PREVISÃO AO VIVO (experimental): chama o endpoint de predictions e
 *      decodifica o protobuf. Pode quebrar se a CittaMobi mudar o formato.
 *
 * Desenvolvido por @alequizao.
 */

/* ============================ Config / constantes ============================ */

/* URLs vêm do .env via config.php (ONIBUS_URL_LINHAS/CORE). Fallback se ausente. */
defined('ONIBUS_URL_LINHAS') || define('ONIBUS_URL_LINHAS', 'https://linhas.cittamobi.com.br');
defined('ONIBUS_URL_CORE')   || define('ONIBUS_URL_CORE',   'https://core.cittamobi.com.br');
/* Token embutido do site público do CittaMobi (header "username"), usado como último fallback. */
const ONIBUS_TOKEN_PADRAO = '$2a$10$aGN1MBopPjiM/97AzcG28ugrkNejBLKz8sJ/.vJ3RNDRENgyUX1t6';
const ONIBUS_CIDADE_PADRAO = 'alagoas/maceio';

/* Precedência: config do painel (cfg) > .env (credenciais) > padrão embutido. */
function onibus_token(): string
{
    $t = trim((string) cfg_get('onibus_token', ''));
    if ($t !== '') {
        return $t;
    }
    if (defined('ONIBUS_TOKEN_ENV') && ONIBUS_TOKEN_ENV !== '') {
        return ONIBUS_TOKEN_ENV;
    }
    return ONIBUS_TOKEN_PADRAO;
}

function onibus_cidade_slug(): string
{
    $s = trim((string) cfg_get('onibus_cidade_slug', ''));
    if ($s !== '') {
        return $s;
    }
    if (defined('ONIBUS_CIDADE_ENV') && ONIBUS_CIDADE_ENV !== '') {
        return ONIBUS_CIDADE_ENV;
    }
    return ONIBUS_CIDADE_PADRAO;
}

function onibus_ativo(): bool
{
    return cfg_get('onibus_ativo', '1') === '1';
}

/* ================================ HTTP ==================================== */

/** GET simples via cURL. Retorna [ok, corpo, http, erro]. $bin=true não força texto. */
function onibus_http_get(string $url, array $headers = [], int $timeout = 15): array
{
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true,
            'header' => implode("\r\n", $headers)]]);
        $body = @file_get_contents($url, false, $ctx);
        return ['ok' => $body !== false, 'corpo' => (string) $body, 'http' => 0, 'erro' => $body === false ? 'falha' : ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => array_merge(['User-Agent: Mozilla/5.0 (AgendaSocial/onibus)'], $headers),
    ]);
    $body  = curl_exec($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    curl_close($ch);
    if ($errno) {
        return ['ok' => false, 'corpo' => '', 'http' => $http, 'erro' => $err];
    }
    return ['ok' => $http >= 200 && $http < 400, 'corpo' => (string) $body, 'http' => $http, 'erro' => ''];
}

/* ============================ Normalização/busca ========================== */

/** minúsculas + sem acento (para comparar texto do usuário com o catálogo). */
function onibus_norm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false) {
        $s = $t;
    }
    return preg_replace('/[^a-z0-9]+/', ' ', $s) ?: '';
}

/** Palavras "de lugar/destino" úteis para busca: remove ruído, saudações e verbos. */
function onibus_palavras_lugar(string $textoNorm): array
{
    static $stop = [
        // intenção / conversa
        'onibus', 'onib', 'onibs', 'bus', 'linha', 'linhas', 'horario', 'horarios', 'hora', 'horas',
        'que', 'passa', 'para', 'pra', 'sai', 'chega', 'com', 'qual', 'quais', 'quanto', 'quando',
        'tem', 'vai', 'vem', 'ate', 'the', 'sobre', 'saber', 'informacao', 'informacoes', 'itinerario',
        'coletivo', 'ponto', 'parada', 'viacao', 'circular', 'proximo', 'proxima',
        // saudações / cortesia (não são destino!)
        'bom', 'boa', 'dia', 'tarde', 'noite', 'oi', 'ola', 'ei', 'eai', 'opa', 'obrigado', 'obrigada',
        'valeu', 'por', 'favor', 'porfavor', 'gente', 'amigo', 'amiga', 'tudo', 'bem', 'blz',
    ];
    return array_values(array_filter(explode(' ', $textoNorm),
        fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stop, true)));
}

/** Há intenção EXPLÍCITA de transporte no texto? (para não responder a "bom dia"). */
function onibus_tem_intencao(string $textoNorm): bool
{
    return (bool) preg_match('/\b(onibus|onib|bus|linha|linhas|horario|horarios|passa|parada|ponto|viacao|itinerario|coletivo|circular|busao|buzao|busu)\b/', $textoNorm);
}

/* =========================== Camada 1: catálogo =========================== */

/** Decodifica o JSON de transfer-state embutido na página (entidades &q; etc.). */
function onibus_extrair_state(string $html): ?array
{
    if (!preg_match('#<script[^>]*type="application/json"[^>]*>(.*?)</script>#is', $html, $m)) {
        return null;
    }
    $dec = strtr($m[1], ['&q;' => '"', '&a;' => '&', '&l;' => '<', '&g;' => '>', '&s;' => "'"]);
    $j = json_decode($dec, true);
    return is_array($j) ? $j : null;
}

/** Varre o state e devolve a lista de linhas (agencies[].lines[]). */
function onibus_linhas_do_state(array $state): array
{
    $out = [];
    $walk = function ($node) use (&$walk, &$out) {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['agencies']) && is_array($node['agencies'])) {
            foreach ($node['agencies'] as $ag) {
                foreach (($ag['lines'] ?? []) as $ln) {
                    $slug = (string) ($ln['lineRoutesPath'] ?? $ln['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $out[$slug] = [
                        'slug'             => $slug,
                        'route_code'       => (string) ($ln['routeCode'] ?? ''),
                        'service_mnemonic' => (string) ($ln['serviceMnemonic'] ?? ''),
                        'route_mnemonic'   => (string) ($ln['routeMnemonic'] ?? ''),
                        'company'          => (string) ($ln['companyName'] ?? ($ag['name'] ?? '')),
                    ];
                }
            }
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($state);
    return array_values($out);
}

/** Raspa o catálogo de linhas da cidade e faz upsert. Retorna [ok,n,erro]. */
function onibus_scrape_catalogo(PDO $db): array
{
    $url = ONIBUS_URL_LINHAS . '/linhas/estado/' . onibus_cidade_slug();
    $r = onibus_http_get($url, [], 25);
    if (!$r['ok']) {
        return ['ok' => false, 'n' => 0, 'erro' => 'HTTP ' . $r['http'] . ' ' . $r['erro']];
    }
    $state = onibus_extrair_state($r['corpo']);
    if ($state === null) {
        return ['ok' => false, 'n' => 0, 'erro' => 'não achei o JSON da página'];
    }
    $linhas = onibus_linhas_do_state($state);
    if (!$linhas) {
        return ['ok' => false, 'n' => 0, 'erro' => 'catálogo vazio'];
    }
    $cidade = trim((string) (($p = explode('/', onibus_cidade_slug())) ? end($p) : 'Maceió'));
    $cidade = $cidade !== '' ? ucwords(str_replace('-', ' ', $cidade)) : 'Maceió';
    $up = $db->prepare('INSERT INTO onibus_linhas
        (slug, route_code, service_mnemonic, route_mnemonic, company, cidade)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          route_code=VALUES(route_code), service_mnemonic=VALUES(service_mnemonic),
          route_mnemonic=VALUES(route_mnemonic), company=VALUES(company)');
    $n = 0;
    foreach ($linhas as $l) {
        $up->execute([$l['slug'], $l['route_code'], $l['service_mnemonic'], $l['route_mnemonic'], $l['company'], $cidade]);
        $n++;
    }
    return ['ok' => true, 'n' => $n, 'erro' => ''];
}

/** Extrai serviceId, paradas, horários e janela de operação do HTML de uma linha. */
function onibus_parse_linha(string $html): array
{
    $out = ['service_id' => '', 'paradas' => [], 'horarios' => [], 'operacao' => ''];

    if (preg_match('/id="serviceId"[^>]*value="([0-9a-fA-F-]{36})"/', $html, $m)) {
        $out['service_id'] = $m[1];
    }

    // paradas: data-stop-id + stop-name aparecem na mesma ordem
    preg_match_all('/data-stop-id="([0-9a-fA-F-]{36})"/', $html, $ids);
    preg_match_all('/stop-name[^>]*>([^<]+)</', $html, $nomes);
    $ids = $ids[1] ?? [];
    $nomes = $nomes[1] ?? [];
    foreach ($ids as $i => $id) {
        $out['paradas'][] = [
            'id'   => $id,
            'nome' => trim(html_entity_decode((string) ($nomes[$i] ?? ''), ENT_QUOTES, 'UTF-8')),
            'seq'  => $i + 1,
        ];
    }

    // horários por dia: bloco "container-horarios-mobile" (colunas "Dia" e "Horário")
    $pos = strpos($html, 'container-horarios-mobile');
    if ($pos !== false) {
        $seg = substr($html, $pos, 5000);
        // cada célula é <p class="p-14">...</p> — pode ter <b> dentro (cabeçalhos)
        preg_match_all('/<p class="p-14">(.*?)<\/p>/s', $seg, $ps);
        $vals = array_map(
            fn($x) => trim(html_entity_decode(strip_tags($x), ENT_QUOTES, 'UTF-8')),
            $ps[1] ?? []
        );
        // sequência esperada: "Dia", <7 dias>, "Horário", <7 faixas>
        $dias = [];
        $faixas = [];
        $modo = '';
        foreach ($vals as $v) {
            if ($v === 'Dia') { $modo = 'dia'; continue; }
            if ($v === 'Horário' || $v === 'Horario') { $modo = 'faixa'; continue; }
            if ($v === '') { continue; }
            if ($modo === 'dia') { $dias[] = $v; }
            elseif ($modo === 'faixa') { $faixas[] = $v; }
        }
        foreach ($dias as $i => $d) {
            if (isset($faixas[$i]) && $faixas[$i] !== '') {
                $out['horarios'][$d] = $faixas[$i];
            }
        }
    }

    // janela de operação (do <meta description>)
    if (preg_match('/opera(?:ç|c)(?:ã|a)o de ([0-9:]{4,5}\s*-\s*[0-9:]{4,5}[^"<.]*)/iu', $html, $op)) {
        $out['operacao'] = trim(preg_replace('/\s+/', ' ', $op[1]));
    }

    return $out;
}

/** Raspa detalhes (horários/paradas/serviceId) de UMA linha e salva. Retorna [ok,erro]. */
function onibus_scrape_linha(PDO $db, array $linha): array
{
    $url = ONIBUS_URL_LINHAS . '/linha/' . rawurlencode($linha['slug']);
    // o slug já vem "limpo"; rawurlencode quebraria as barras internas — usa direto
    $url = ONIBUS_URL_LINHAS . '/linha/' . $linha['slug'];
    $r = onibus_http_get($url, [], 20);
    if (!$r['ok']) {
        return ['ok' => false, 'erro' => 'HTTP ' . $r['http']];
    }
    $d = onibus_parse_linha($r['corpo']);
    $db->prepare('UPDATE onibus_linhas
        SET service_id=?, operacao=?, horarios=?, paradas=?, detalhe_em=NOW()
        WHERE id=?')->execute([
        $d['service_id'],
        $d['operacao'],
        json_encode($d['horarios'], JSON_UNESCAPED_UNICODE),
        json_encode($d['paradas'], JSON_UNESCAPED_UNICODE),
        (int) $linha['id'],
    ]);
    return ['ok' => true, 'erro' => ''];
}

/* ============================== Consulta ================================= */

/** Busca linhas por texto livre (código e/ou nome/bairro/destino). */
function onibus_buscar(PDO $db, string $texto, int $limite = 5): array
{
    $q = onibus_norm($texto);
    if ($q === '') {
        return [];
    }
    $achados = [];

    // 1) por código de linha (2 a 4 dígitos)
    if (preg_match_all('/\b(\d{2,4})\b/', $q, $mm)) {
        foreach (array_unique($mm[1]) as $code) {
            $codeZ = str_pad($code, 4, '0', STR_PAD_LEFT);
            $st = $db->prepare('SELECT * FROM onibus_linhas
                WHERE route_code = ? OR route_code = ? OR CAST(route_code AS UNSIGNED) = ?
                ORDER BY detalhe_em IS NULL, service_mnemonic LIMIT ?');
            $st->bindValue(1, $code);
            $st->bindValue(2, $codeZ);
            $st->bindValue(3, (int) $code, PDO::PARAM_INT);
            $st->bindValue(4, $limite, PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll() as $row) {
                $achados[$row['id']] = $row;
            }
        }
    }

    // 2) por nome/bairro/destino — casa PALAVRAS INTEIRAS (não substring), sem acento.
    //    O catálogo é pequeno (~centenas de linhas): carrega tudo e filtra em PHP,
    //    o que evita falsos positivos do LIKE (ex.: "rio" dentro de "tenório").
    $palavras = onibus_palavras_lugar($q);
    if ($palavras && count($achados) < $limite) {
        $todas = $db->query('SELECT * FROM onibus_linhas')->fetchAll();
        $ranked = [];
        foreach ($todas as $row) {
            if (isset($achados[$row['id']])) {
                continue;
            }
            $alvo = ' ' . onibus_norm($row['service_mnemonic'] . ' ' . $row['route_mnemonic']) . ' ';
            $hits = 0;
            foreach ($palavras as $w) {
                if (strpos($alvo, ' ' . $w . ' ') !== false) {
                    $hits++;
                }
            }
            if ($hits > 0) {
                $ranked[] = ['hits' => $hits, 'row' => $row];
            }
        }
        // mais palavras casadas primeiro; empate: quem já tem detalhe
        usort($ranked, fn($a, $b) => $b['hits'] <=> $a['hits']
            ?: (($a['row']['detalhe_em'] === null) <=> ($b['row']['detalhe_em'] === null)));
        foreach ($ranked as $r) {
            $achados[$r['row']['id']] = $r['row'];
        }
    }

    // remove linhas com título idêntico (mesmo código + mesmo nome em sentidos iguais)
    $vistos = [];
    $final = [];
    foreach ($achados as $row) {
        $chave = onibus_norm(onibus_titulo($row));
        if (isset($vistos[$chave])) {
            continue;
        }
        $vistos[$chave] = true;
        $final[] = $row;
    }

    return array_slice($final, 0, $limite);
}

/* ===================== Camada 2: previsão ao vivo ======================= */

/** Decoder genérico de protobuf (wire format). Retorna [campo => [valores]]. */
function onibus_pb_decode(string $bin): array
{
    $out = [];
    $i = 0;
    $len = strlen($bin);
    $readVarint = function () use ($bin, &$i, $len) {
        $shift = 0;
        $result = 0;
        while ($i < $len) {
            $b = ord($bin[$i++]);
            $result |= ($b & 0x7f) << $shift;
            if (($b & 0x80) === 0) {
                break;
            }
            $shift += 7;
        }
        return $result;
    };
    while ($i < $len) {
        $tag = $readVarint();
        $field = $tag >> 3;
        $wire = $tag & 7;
        if ($wire === 0) {          // varint
            $out[$field][] = $readVarint();
        } elseif ($wire === 2) {    // length-delimited (string/bytes/nested)
            $l = $readVarint();
            $out[$field][] = substr($bin, $i, $l);
            $i += $l;
        } elseif ($wire === 5) {    // 32-bit
            $out[$field][] = substr($bin, $i, 4);
            $i += 4;
        } elseif ($wire === 1) {    // 64-bit
            $out[$field][] = substr($bin, $i, 8);
            $i += 8;
        } else {
            break; // desconhecido
        }
    }
    return $out;
}

/**
 * Previsão AO VIVO para uma parada. Retorna [ok, veiculos[ [veiculo, seg, min] ], erro].
 * Campos do protobuf (ServicePredictions #12 = veículos; no veículo: #3 seg, #4 min, #1 id).
 */
function onibus_previsao(string $serviceId, string $stopId, int $timeout = 8): array
{
    if ($serviceId === '' || $stopId === '') {
        return ['ok' => false, 'veiculos' => [], 'erro' => 'sem serviceId/stopId'];
    }
    $url = ONIBUS_URL_CORE . '/linesroutes/service/predictions?serviceId=' . rawurlencode($serviceId)
        . '&stopId=' . rawurlencode($stopId);
    $r = onibus_http_get($url, ['username: ' . onibus_token(), 'Accept: */*'], $timeout);
    if (!$r['ok'] || $r['corpo'] === '') {
        return ['ok' => false, 'veiculos' => [], 'erro' => 'HTTP ' . $r['http']];
    }
    $top = onibus_pb_decode($r['corpo']);
    $veics = [];
    $unpackFloat = function ($b) {
        // protobuf é little-endian: 'e' = double (8b), 'g' = float (4b)
        if ($b === null) {
            return null;
        }
        if (strlen($b) === 8) {
            return unpack('e', $b)[1] ?? null;
        }
        if (strlen($b) === 4) {
            return unpack('g', $b)[1] ?? null;
        }
        return null;
    };
    foreach (($top[12] ?? []) as $raw) {
        $v = onibus_pb_decode((string) $raw);
        $seg = (int) ($v[3][0] ?? 0);
        $min = (int) ($v[4][0] ?? 0);
        if ($min === 0 && $seg > 0) {
            $min = (int) round($seg / 60);
        }
        $veics[] = [
            'veiculo'  => (string) ($v[1][0] ?? ''),
            'segundos' => $seg,
            'minutos'  => $min,
            'lat'      => $unpackFloat($v[8][0] ?? null),
            'lng'      => $unpackFloat($v[9][0] ?? null),
        ];
    }
    usort($veics, fn($a, $b) => ($a['segundos'] ?: PHP_INT_MAX) <=> ($b['segundos'] ?: PHP_INT_MAX));
    return ['ok' => true, 'veiculos' => $veics, 'erro' => ''];
}

/* ==================== Próximo ônibus no ponto (igual CittaMobi) ==========
 * Metodologia (sem depender de GPS, que o Direct do Instagram não entrega de
 * forma confiável): a pessoa cita a LINHA, o SENTIDO (destino) e o LOCAL onde
 * está (bairro/rua/ponto). Como cada sentido é uma linha própria no catálogo e
 * cada parada guarda o ENDEREÇO completo, dá para:
 *   1) escolher a variante (sentido) certa da linha;
 *   2) achar a parada mais próxima do local citado (casamento textual);
 *   3) pedir a previsão ao vivo daquela parada -> mesmo dado do CittaMobi.
 * ------------------------------------------------------------------------- */

/** Todas as variantes (sentidos) de um mesmo código de linha, já detalhadas de preferência. */
function onibus_variantes(PDO $db, string $routeCode): array
{
    $code = trim($routeCode);
    $st = $db->prepare('SELECT * FROM onibus_linhas
        WHERE route_code = ? OR CAST(route_code AS UNSIGNED) = ?
        ORDER BY detalhe_em IS NULL, service_mnemonic');
    $st->bindValue(1, $code);
    $st->bindValue(2, (int) $code, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** Separa a mensagem em "sentido" (destino) e "local" (onde a pessoa está). */
function onibus_extrair_local_sentido(string $textoNorm): array
{
    $sentido = '';
    $local = '';
    // marcadores de LOCAL (onde a pessoa está / de onde sai)
    $mLocal = 'estou|to em|tou em|to no|to na|aqui|perto de|perto|no ponto|ponto|na altura|altura|saindo de|saindo do|saindo da|saindo|partindo de|partindo do|partindo|moro em|moro no|moro na|fico em|de onde estou';
    // sentido: depois de "sentido/rumo/destino/indo para/para/pra/ate/em direcao"
    if (preg_match('/\b(?:sentido|rumo|destino|indo para|indo pra|em direcao a|em direcao ao|para o|para a|para|pra|ate)\b(.+)$/', $textoNorm, $m)) {
        $sentido = trim($m[1]);
    }
    // local: depois de um dos marcadores de local (até o próximo marcador de sentido)
    if (preg_match('/\b(?:' . $mLocal . ')\b(.+?)(?:\bsentido\b|\brumo\b|\bdestino\b|$)/', $textoNorm, $m)) {
        $local = trim($m[1]);
    }
    // o sentido não deve conter o trecho do local (quando "sentido X ... saindo do Y")
    if ($sentido !== '' && $local !== '') {
        $sentido = trim((string) preg_replace('/\b(?:' . $mLocal . ')\b.*$/', '', $sentido));
    }
    return ['sentido' => trim($sentido, " ,.;"), 'local' => trim($local, " ,.;")];
}

/** Destino "textual" de uma variante (parte após o separador x / → -). */
function onibus_destino_variante(array $l): string
{
    $nome = (string) ($l['service_mnemonic'] ?: $l['route_mnemonic']);
    $n = onibus_norm($nome);
    // pega o trecho após o último separador comum (x, /, -) = destino do sentido
    if (preg_match('/.*(?: x | \/ | - )(.+)$/', ' ' . $n . ' ', $m)) {
        return trim($m[1]);
    }
    return trim($n);
}

/** Escolhe a variante cujo DESTINO casa melhor com o sentido pedido. Retorna [row, score]. */
function onibus_escolher_sentido(array $variantes, string $sentidoNorm): array
{
    if (!$variantes) {
        return ['row' => null, 'score' => 0];
    }
    $palavras = onibus_palavras_lugar($sentidoNorm);
    if (!$palavras) {
        return ['row' => count($variantes) === 1 ? $variantes[0] : null, 'score' => 0];
    }
    $melhor = null;
    $best = -1;
    foreach ($variantes as $v) {
        $destino = ' ' . onibus_destino_variante($v) . ' ';
        $nomeTodo = ' ' . onibus_norm($v['service_mnemonic'] . ' ' . $v['route_mnemonic']) . ' ';
        $score = 0;
        foreach ($palavras as $w) {
            if (strpos($destino, ' ' . $w . ' ') !== false) {
                $score += 2;                  // no destino vale mais
            } elseif (strpos($nomeTodo, ' ' . $w . ' ') !== false) {
                $score += 1;
            }
        }
        if ($score > $best) {
            $best = $score;
            $melhor = $v;
        }
    }
    return ['row' => $best > 0 ? $melhor : null, 'score' => max(0, $best)];
}

/** Parada da variante mais próxima do LOCAL citado (casamento de tokens no endereço). */
function onibus_parada_por_referencia(array $variante, string $localNorm): ?array
{
    $paradas = json_decode((string) ($variante['paradas'] ?? ''), true);
    if (!is_array($paradas) || !$paradas) {
        return null;
    }
    $palavras = onibus_palavras_lugar($localNorm);
    if (!$palavras) {
        return null;
    }
    $melhor = null;
    $best = 0;
    foreach ($paradas as $p) {
        $alvo = ' ' . onibus_norm((string) ($p['nome'] ?? '')) . ' ';
        $score = 0;
        foreach ($palavras as $w) {
            if (strpos($alvo, ' ' . $w . ' ') !== false) {
                $score++;
            }
        }
        if ($score > $best) {
            $best = $score;
            $melhor = $p;
        }
    }
    return $best > 0 ? $melhor : null;
}

/**
 * Resolve a (variante, parada) quando a pessoa deu linha + local (+ sentido).
 * Retorna ['variante'=>row, 'parada'=>p] ou null se não deu para resolver.
 */
function onibus_resolver_no_ponto(PDO $db, string $routeCode, string $sentidoNorm, string $localNorm): ?array
{
    $variantes = onibus_variantes($db, $routeCode);
    if (!$variantes) {
        return null;
    }
    $sel = onibus_escolher_sentido($variantes, $sentidoNorm);
    $cands = $sel['row'] ? [$sel['row']] : $variantes;

    $melhorVar = null;
    $melhorParada = null;
    $best = 0;
    foreach ($cands as $v) {
        if (($v['service_id'] ?? '') === '' || empty($v['paradas'])) {
            $d = onibus_scrape_linha($db, $v);
            if ($d['ok']) {
                $v = $db->query('SELECT * FROM onibus_linhas WHERE id=' . (int) $v['id'])->fetch();
            }
        }
        $p = onibus_parada_por_referencia($v, $localNorm);
        if ($p) {
            $pw = onibus_palavras_lugar($localNorm);
            $alvo = ' ' . onibus_norm((string) $p['nome']) . ' ';
            $sc = 0;
            foreach ($pw as $w) {
                if (strpos($alvo, ' ' . $w . ' ') !== false) {
                    $sc++;
                }
            }
            if ($sc > $best) {
                $best = $sc;
                $melhorVar = $v;
                $melhorParada = $p;
            }
        }
    }
    if (!$melhorVar || !$melhorParada) {
        return null;
    }
    return ['variante' => $melhorVar, 'parada' => $melhorParada];
}

/* =================== Coleta do LOCAL (GPS + botões) ==================== */

/** Distância em metros entre dois pontos (Haversine). */
function onibus_haversine(float $la1, float $lo1, float $la2, float $lo2): float
{
    $R = 6371000;
    $dLa = deg2rad($la2 - $la1);
    $dLo = deg2rad($lo2 - $lo1);
    $a = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** Geocodifica um endereço. $rede=false = SÓ cache (não chama a rede; nunca bloqueia).
    Retorna [lat,lng], null (não encontrado/erro) ou false (ainda não tem no cache). */
function onibus_geocode(PDO $db, string $endereco, bool $rede = true)
{
    $end = trim($endereco);
    if ($end === '') {
        return null;
    }
    $chave = md5(onibus_norm($end));
    $row = $db->query('SELECT lat,lng FROM onibus_geocode WHERE chave=' . $db->quote($chave))->fetch();
    if ($row) {
        return $row['lat'] !== null ? ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng']] : null;
    }
    if (!$rede) {
        return false; // sem cache e proibido chamar a rede -> "ainda não sei"
    }
    usleep(1100000); // educado com o Nominatim (máx ~1 req/s)
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=br&q=' . rawurlencode($end);
    $r = onibus_http_get($url, ['User-Agent: AgendaSocial/onibus (contato: ' . DEV_EMAIL . ')', 'Accept: application/json'], 20);
    $lat = $lng = null;
    if ($r['ok']) {
        $j = json_decode($r['corpo'], true);
        if (is_array($j) && isset($j[0]['lat'])) {
            $lat = (float) $j[0]['lat'];
            $lng = (float) $j[0]['lon'];
        }
    }
    $db->prepare('INSERT INTO onibus_geocode (chave,endereco,lat,lng) VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE lat=VALUES(lat), lng=VALUES(lng)')
       ->execute([$chave, mb_substr($end, 0, 255), $lat, $lng]);
    return $lat !== null ? ['lat' => $lat, 'lng' => $lng] : null;
}

/**
 * Parada da variante mais próxima de um GPS. NÃO bloqueia: usa o cache de geocode
 * e geocodifica no máximo poucas paradas por chamada (orçamento de tempo), pra a
 * requisição responder rápido. O cron (cron_onibus_geo.php) completa o resto.
 * Retorna ['id','nome','seq','dist'] | null (nenhuma parada com coordenada ainda).
 */
function onibus_parada_mais_proxima_gps(PDO $db, array $variante, float $lat, float $lng): ?array
{
    $paradas = json_decode((string) ($variante['paradas'] ?? ''), true);
    if (!is_array($paradas) || !$paradas) {
        return null;
    }
    $orcamentoSeg = max(2, (int) cfg_get('onibus_geo_orcamento_seg', '6'));
    $deadline = microtime(true) + $orcamentoSeg;

    $melhor = null;
    $best = INF;
    $avaliar = function ($p, $g) use (&$melhor, &$best, $lat, $lng) {
        $d = onibus_haversine($lat, $lng, $g['lat'], $g['lng']);
        if ($d < $best) {
            $best = $d;
            $melhor = $p;
            $melhor['dist'] = $d;
        }
    };

    // 1) passada rápida SÓ com o que já está no cache
    $faltam = [];
    foreach ($paradas as $p) {
        $g = onibus_geocode($db, (string) ($p['nome'] ?? ''), false);
        if (is_array($g)) {
            $avaliar($p, $g);
        } elseif ($g === false) {
            $faltam[] = $p; // ainda não geocodificada
        }
    }

    // 2) geocodifica ALGUMAS que faltam, dentro do orçamento de tempo (não trava)
    foreach ($faltam as $p) {
        if (microtime(true) >= $deadline) {
            break;
        }
        $g = onibus_geocode($db, (string) ($p['nome'] ?? ''), true);
        if (is_array($g)) {
            $avaliar($p, $g);
        }
    }

    return $melhor;
}

/** Token que liga o link público de localização à conversa do Direct. */
function onibus_token_criar(PDO $db, int $cid, string $sender, string $code, string $sentido): string
{
    $tok = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO onibus_local_tokens (token,cliente_id,sender_id,route_code,sentido)
        VALUES (?,?,?,?,?)')->execute([$tok, $cid, $sender, $code, $sentido]);
    return $tok;
}

function onibus_token_obter(PDO $db, string $tok): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $tok)) {
        return null;
    }
    $r = $db->query('SELECT * FROM onibus_local_tokens WHERE token=' . $db->quote($tok)
        . ' AND criado_em >= (NOW() - INTERVAL 1 DAY)')->fetch();
    return $r ?: null;
}

/** Título curto p/ botão de quick reply (limite ~20 chars do Instagram). */
function onibus_qr_titulo(string $texto): string
{
    $parte = trim((string) (explode(',', $texto)[0] ?? $texto));
    return mb_strimwidth($parte !== '' ? $parte : $texto, 0, 20, '…');
}

/** Botões (quick replies) com uma amostra das paradas da variante. */
function onibus_qr_paradas(array $variante, int $max = 11): array
{
    $paradas = json_decode((string) ($variante['paradas'] ?? ''), true) ?: [];
    $n = count($paradas);
    if (!$n) {
        return [];
    }
    $qr = [];
    $passo = max(1, (int) ceil($n / $max));
    for ($i = 0; $i < $n && count($qr) < $max; $i += $passo) {
        $p = $paradas[$i];
        $qr[] = [
            'content_type' => 'text',
            'title'        => onibus_qr_titulo((string) ($p['nome'] ?? 'Ponto')),
            'payload'      => 'ONIBUS_STOP|' . (int) $variante['id'] . '|' . (string) ($p['id'] ?? ''),
        ];
    }
    return $qr;
}

/** Envia a mensagem de COLETA (link GPS + botões de parada) para uma variante. */
/**
 * Link do app de ônibus que vai no Direct.
 *
 * Com ONIBUS_APP_URL definido, aponta para o app público (Tevi no Buzão): ele não
 * usa o token da conversa — quem quer ser avisado ativa a notificação no próprio
 * aparelho. Sem a constante, mantém o comportamento antigo (local.php + token).
 */
function onibus_app_link(string $token, string $routeCode = ''): string
{
    if (defined('ONIBUS_APP_URL') && ONIBUS_APP_URL !== '') {
        $u = rtrim(ONIBUS_APP_URL, '/') . '/';
        // leva o código da linha: o app já abre com ela filtrada (era o papel do token).
        return $routeCode !== '' ? $u . '?linha=' . rawurlencode(trim($routeCode)) : $u;
    }
    return rtrim(BASE_URL, '/') . '/local.php?t=' . $token;
}

function onibus_oferecer_ponto(PDO $db, array $cli, string $sender, array $variante): array
{
    if (($variante['service_id'] ?? '') === '' || empty($variante['paradas'])) {
        $d = onibus_scrape_linha($db, $variante);
        if ($d['ok']) {
            $variante = $db->query('SELECT * FROM onibus_linhas WHERE id=' . (int) $variante['id'])->fetch();
        }
    }
    $tok = onibus_token_criar($db, (int) $cli['id'], $sender, (string) $variante['route_code'], onibus_destino_variante($variante));
    $link = onibus_app_link($tok, (string) $variante['route_code']);
    $txt = '🚍 ' . onibus_titulo($variante)
        . "\n\nPra eu ver o próximo ônibus no SEU ponto:\n📍 Toque e envie sua localização:\n" . $link
        . "\n\nOu toque no seu ponto na lista abaixo 👇";
    $qr = onibus_qr_paradas($variante, 11);
    $env = dm_enviar_opcoes($db, $cli, $sender, $txt, $qr, 'auto_onibus');
    return ['enviou' => (bool) ($env['ok'] ?? false), 'motivo' => $env['erro'] ?? 'ofereceu coleta'];
}

/** Quando não deu p/ resolver a parada: pergunta o sentido (se preciso) e oferece a coleta. */
function onibus_coletar_local(PDO $db, array $cli, string $sender, string $routeCode, string $sentidoNorm): array
{
    $variantes = onibus_variantes($db, $routeCode);
    if (!$variantes) {
        return ['enviou' => false, 'motivo' => 'sem variantes'];
    }
    $sel = onibus_escolher_sentido($variantes, $sentidoNorm);
    if (!$sel['row']) {
        // destinos distintos -> pede o sentido por botões
        $qr = [];
        $vistos = [];
        foreach ($variantes as $v) {
            $dst = onibus_destino_variante($v);
            if ($dst === '' || isset($vistos[$dst])) {
                continue;
            }
            $vistos[$dst] = true;
            $qr[] = [
                'content_type' => 'text',
                'title'        => onibus_qr_titulo((string) ($v['service_mnemonic'] ?: $v['route_mnemonic'])),
                'payload'      => 'ONIBUS_DIR|' . (int) $v['id'],
            ];
        }
        if (count($qr) > 1) {
            $txt = '🚌 Linha ' . trim($routeCode) . ' — qual sentido você quer?';
            $env = dm_enviar_opcoes($db, $cli, $sender, $txt, array_slice($qr, 0, 13), 'auto_onibus');
            return ['enviou' => (bool) ($env['ok'] ?? false), 'motivo' => 'perguntou sentido'];
        }
        $sel['row'] = $variantes[0];
    }
    return onibus_oferecer_ponto($db, $cli, $sender, $sel['row']);
}

/** Trata o toque em um botão (quick reply): escolher sentido OU parada. */
function onibus_tratar_payload(PDO $db, array $cli, string $sender, string $payload): array
{
    $parts = explode('|', $payload);
    if (($parts[0] ?? '') === 'ONIBUS_DIR' && isset($parts[1])) {
        $v = $db->query('SELECT * FROM onibus_linhas WHERE id=' . (int) $parts[1])->fetch();
        if ($v) {
            return onibus_oferecer_ponto($db, $cli, $sender, $v);
        }
    }
    if (($parts[0] ?? '') === 'ONIBUS_STOP' && isset($parts[2])) {
        $v = $db->query('SELECT * FROM onibus_linhas WHERE id=' . (int) $parts[1])->fetch();
        if ($v) {
            $stopId = (string) $parts[2];
            $nome = onibus_nome_parada($v, $stopId);
            $r = onibus_enviar_ponto($db, $cli, $sender, $v, $stopId, $nome, null);
            return ['enviou' => $r['enviou']];
        }
    }
    if (($parts[0] ?? '') === 'ONIBUS_ALERT' && isset($parts[2])) {
        $v = $db->query('SELECT * FROM onibus_linhas WHERE id=' . (int) $parts[1])->fetch();
        if ($v) {
            $stopId = (string) $parts[2];
            onibus_alerta_criar($db, (int) $cli['id'], $sender, (int) $v['id'], $stopId, onibus_nome_parada($v, $stopId));
            $env = dm_enviar($db, $cli, $sender, "✅ Combinado! Vou te mandando o tempo do ônibus até ele chegar no seu ponto. 🚍\n(pra parar, é só dizer \"parar\".)", 'auto_onibus');
            return ['enviou' => (bool) ($env['ok'] ?? false)];
        }
    }
    if (($parts[0] ?? '') === 'ONIBUS_NOALERT') {
        $env = dm_enviar($db, $cli, $sender, '👍 Beleza! Qualquer coisa é só chamar.', 'auto_onibus');
        return ['enviou' => (bool) ($env['ok'] ?? false)];
    }
    return ['enviou' => false];
}

/** Nome (endereço) de uma parada dentro da variante, pelo stopId. */
function onibus_nome_parada(array $variante, string $stopId): string
{
    $paradas = json_decode((string) ($variante['paradas'] ?? ''), true) ?: [];
    foreach ($paradas as $p) {
        if ((string) ($p['id'] ?? '') === $stopId) {
            return (string) ($p['nome'] ?? '');
        }
    }
    return '';
}

/**
 * Envia o "próximo ônibus" de uma parada + oferta de ALERTA de proximidade.
 * Diálogo curto: mostra os minutos e pergunta se quer ser avisado. Retorna [enviou, live].
 */
function onibus_enviar_ponto(PDO $db, array $cli, string $sender, array $variante, string $stopId, string $nome, ?float $dist): array
{
    $linhas = ['🚍 ' . onibus_titulo($variante)];
    if ($nome !== '') {
        $extra = $dist !== null ? ' (~' . ($dist >= 1000 ? round($dist / 1000, 1) . ' km' : round($dist) . ' m') . ')' : '';
        $linhas[] = '📍 ' . mb_strimwidth($nome, 0, 55, '…') . $extra;
    }
    $pv = onibus_previsao((string) $variante['service_id'], $stopId);
    $live = false;
    if ($pv['ok'] && $pv['veiculos']) {
        $mins = [];
        foreach ($pv['veiculos'] as $v) {
            if ($v['minutos'] > 0) {
                $mins[] = $v['minutos'] . ' min';
            }
        }
        if ($mins) {
            $live = true;
            $linhas[] = '⏱️ Próximo(s): ' . implode(', ', array_slice($mins, 0, 4));
        } else {
            $linhas[] = '⏱️ Sem previsão ao vivo agora (ônibus pode não estar circulando).';
        }
    } else {
        $linhas[] = '⏱️ Sem tempo real agora para este ponto.';
    }

    // Só oferece alerta quando há rastreamento ao vivo (senão não teria como avisar).
    if ($live) {
        $linhas[] = '';
        $linhas[] = '🔔 Quer que eu te avise quando ele estiver chegando?';
        $qr = [
            ['content_type' => 'text', 'title' => '🔔 Sim, me avise', 'payload' => 'ONIBUS_ALERT|' . (int) $variante['id'] . '|' . $stopId],
            ['content_type' => 'text', 'title' => 'Não, obrigado',   'payload' => 'ONIBUS_NOALERT'],
        ];
        $env = dm_enviar_opcoes($db, $cli, $sender, implode("\n", $linhas), $qr, 'auto_onibus');
    } else {
        $env = dm_enviar($db, $cli, $sender, implode("\n", $linhas), 'auto_onibus');
    }
    return ['enviou' => (bool) ($env['ok'] ?? false), 'live' => $live];
}

/* ------------------------- Alertas de proximidade ------------------------ */

/** Cancela (desativa) todos os acompanhamentos ativos de um remetente. Retorna qtd. */
function onibus_cancelar_alertas(PDO $db, int $cid, string $sender): int
{
    $st = $db->prepare('UPDATE onibus_alertas SET ativo=0 WHERE cliente_id=? AND sender_id=? AND ativo=1');
    $st->execute([$cid, $sender]);
    return $st->rowCount();
}

/** Cria/reativa uma assinatura de alerta "me avise quando estiver chegando". */
function onibus_alerta_criar(PDO $db, int $cid, string $sender, int $linhaId, string $stopId, string $nome): void
{
    // limite anti-abuso por remetente (evita alertas acumulados)
    $db->prepare('UPDATE onibus_alertas SET ativo=0 WHERE cliente_id=? AND sender_id=? AND ativo=1
        AND linha_id=? AND stop_id=?')->execute([$cid, $sender, $linhaId, $stopId]);
    $db->prepare('INSERT INTO onibus_alertas
        (cliente_id, sender_id, canal, linha_id, stop_id, stop_nome, limiar_min, ativo)
        VALUES (?,?,\'direct\',?,?,?,?,1)')
       ->execute([$cid, $sender, $linhaId, $stopId, mb_substr($nome, 0, 200),
           (int) cfg_get('onibus_alerta_limiar_min', '5')]);
}

/** Cria uma assinatura de alerta por WEB PUSH (aparelho). */
function onibus_alerta_criar_push(PDO $db, int $linhaId, string $stopId, string $nome,
    string $endpoint, string $p256dh, string $auth, ?int $cid = null, ?string $sender = null): void
{
    // evita duplicar a mesma inscrição/linha/parada
    $db->prepare('UPDATE onibus_alertas SET ativo=0 WHERE canal="push" AND ativo=1
        AND push_endpoint=? AND linha_id=? AND stop_id=?')->execute([$endpoint, $linhaId, $stopId]);
    $db->prepare('INSERT INTO onibus_alertas
        (cliente_id, sender_id, canal, push_endpoint, push_p256dh, push_auth, linha_id, stop_id, stop_nome, limiar_min, ativo)
        VALUES (?,?,\'push\',?,?,?,?,?,?,?,1)')
       ->execute([$cid, $sender, $endpoint, $p256dh, $auth, $linhaId, $stopId,
           mb_substr($nome, 0, 200), (int) cfg_get('onibus_alerta_limiar_min', '5')]);
}

/** Envia uma notificação de alerta pelo canal certo (Direct ou Push). Retorna bool. */
function onibus_notificar(PDO $db, array $a, string $texto): bool
{
    if (($a['canal'] ?? 'direct') === 'push') {
        require_once __DIR__ . '/lib_push.php';
        $linhasT = explode("\n", $texto);
        $titulo = array_shift($linhasT);
        $payload = json_encode([
            'title' => $titulo,
            'body'  => trim(implode("\n", $linhasT)),
            'url'   => rtrim(BASE_URL, '/') . '/local.php',
            'tag'   => 'onibus-' . (int) $a['id'],
        ], JSON_UNESCAPED_UNICODE);
        $r = push_enviar((string) $a['push_endpoint'], (string) $a['push_p256dh'], (string) $a['push_auth'], $payload);
        if (in_array($r['status'], [404, 410], true)) { // inscrição morta -> encerra
            $db->prepare('UPDATE onibus_alertas SET ativo=0 WHERE id=?')->execute([(int) $a['id']]);
        }
        return (bool) $r['ok'];
    }
    // direct
    $cli = $db->query('SELECT * FROM ' . DB_PREFIX . 'clientes WHERE id=' . (int) $a['cliente_id'])->fetch();
    if (!$cli) {
        return false;
    }
    $env = dm_enviar($db, $cli, (string) $a['sender_id'], $texto, 'auto_onibus');
    return (bool) ($env['ok'] ?? false);
}

/**
 * Processa alertas ativos: para cada um, consulta a previsão e manda ATUALIZAÇÕES
 * PERIÓDICAS no Direct (tempo restante + distância quando o ônibus reporta GPS),
 * até ele chegar/passar. Chamado pelo cron a cada minuto. Retorna [avisados, checados].
 *
 * Cadência anti-spam: no máximo 1 update a cada `onibus_alerta_intervalo_seg`
 * (padrão 120s), e só quando o tempo mudou de forma relevante — exceto o "chegando".
 */
function onibus_alertas_processar(PDO $db): array
{
    $validadeH = max(1, (int) cfg_get('onibus_alerta_validade_h', '3'));
    $intervalo = max(45, (int) cfg_get('onibus_alerta_intervalo_seg', '120'));
    $maxAvisos = max(3, (int) cfg_get('onibus_alerta_max', '20'));
    $chegou    = max(1, (int) cfg_get('onibus_alerta_chegou_min', '2'));

    // expira antigas (pessoa desistiu / ônibus nunca veio)
    $db->prepare('UPDATE onibus_alertas SET ativo=0
        WHERE ativo=1 AND criado_em < (NOW() - INTERVAL ? HOUR)')->execute([$validadeH]);

    $alertas = $db->query('SELECT * FROM onibus_alertas WHERE ativo=1 ORDER BY id LIMIT 200')->fetchAll();
    $avisados = 0;
    foreach ($alertas as $a) {
        $v = $db->query('SELECT * FROM onibus_linhas WHERE id=' . (int) $a['linha_id'])->fetch();
        if (!$v || ($v['service_id'] ?? '') === '') {
            continue;
        }
        $pv = onibus_previsao((string) $v['service_id'], (string) $a['stop_id']);

        // sem ônibus previsto agora: conta "vazios"; se já tinha acompanhado e sumiu, o ônibus passou.
        if (!$pv['ok'] || !$pv['veiculos']) {
            $vazios = (int) $a['vazios'] + 1;
            $db->prepare('UPDATE onibus_alertas SET vazios=? WHERE id=?')->execute([$vazios, (int) $a['id']]);
            if ((int) $a['avisos'] > 0 && $vazios >= 2) {
                onibus_notificar($db, $a, '🚏 Ônibus ' . onibus_titulo($v) . "\nParece que já passou no seu ponto. Quando quiser, é só chamar de novo. 🙂");
                $db->prepare('UPDATE onibus_alertas SET ativo=0 WHERE id=?')->execute([(int) $a['id']]);
            }
            continue;
        }

        // menor tempo + posição do ônibus mais próximo
        $prox = PHP_INT_MAX;
        $lat = $lng = null;
        foreach ($pv['veiculos'] as $veic) {
            if ($veic['minutos'] > 0 && $veic['minutos'] < $prox) {
                $prox = $veic['minutos'];
                $lat = $veic['lat'];
                $lng = $veic['lng'];
            }
        }
        if ($prox === PHP_INT_MAX) {
            continue;
        }

        // distância do ônibus até a parada (só quando o veículo reporta GPS)
        $distTxt = '';
        if ($lat !== null && $lng !== null) {
            $g = onibus_geocode($db, (string) $a['stop_nome']);
            if ($g) {
                $d = onibus_haversine((float) $lat, (float) $lng, $g['lat'], $g['lng']);
                $distTxt = $d >= 1000 ? ' · ~' . round($d / 1000, 1) . ' km' : ' · ~' . (int) round($d) . ' m';
            }
        }

        // decide se envia agora (cadência + mudança relevante), sempre avisa o "chegando"
        $ultMin = $a['ultimo_min'] === null ? null : (int) $a['ultimo_min'];
        $desde  = $a['ultimo_aviso_em'] ? (time() - strtotime((string) $a['ultimo_aviso_em'])) : PHP_INT_MAX;
        $chegando = $prox <= $chegou;
        $mudou = $ultMin === null || abs($ultMin - $prox) >= 1;
        $enviar = $chegando || ($desde >= $intervalo && $mudou);

        if (!$enviar) {
            continue;
        }

        if ($chegando) {
            $txt = "🔔 Tá chegando! Prepare-se 🏃\n🚍 " . onibus_titulo($v)
                . "\n📍 " . mb_strimwidth((string) $a['stop_nome'], 0, 50, '…')
                . "\n⏱️ ~{$prox} min" . $distTxt;
        } else {
            $txt = "🚍 " . onibus_titulo($v)
                . "\n📍 " . mb_strimwidth((string) $a['stop_nome'], 0, 50, '…')
                . "\n⏱️ Faltam ~{$prox} min" . $distTxt;
        }
        if (onibus_notificar($db, $a, $txt)) {
            $avisados++;
            $novoAvisos = (int) $a['avisos'] + 1;
            // encerra ao "chegar" ou ao atingir o teto de updates
            $fim = $chegando || $novoAvisos >= $maxAvisos;
            $db->prepare('UPDATE onibus_alertas
                SET ultimo_min=?, ultimo_aviso_em=NOW(), avisos=?, vazios=0, avisado_em=NOW(), ativo=?
                WHERE id=?')->execute([$prox, $novoAvisos, $fim ? 0 : 1, (int) $a['id']]);
        }
    }
    return ['avisados' => $avisados, 'checados' => count($alertas)];
}

/** Processa o GPS recebido pelo link e responde no Direct. Retorna [ok, texto, erro]. */
function onibus_responder_gps(PDO $db, array $token, float $lat, float $lng): array
{
    $db->prepare('UPDATE onibus_local_tokens SET lat=?, lng=? WHERE token=?')
       ->execute([$lat, $lng, $token['token']]);
    $cli = $db->query('SELECT * FROM ' . DB_PREFIX . 'clientes WHERE id=' . (int) $token['cliente_id'])->fetch();
    if (!$cli) {
        return ['ok' => false, 'texto' => '', 'erro' => 'cliente não encontrado'];
    }
    $variantes = onibus_variantes($db, (string) $token['route_code']);
    if (!$variantes) {
        return ['ok' => false, 'texto' => '', 'erro' => 'linha não encontrada'];
    }
    $sel = onibus_escolher_sentido($variantes, onibus_norm((string) $token['sentido']));
    $cands = $sel['row'] ? [$sel['row']] : $variantes;

    $melhorVar = null;
    $melhorParada = null;
    $best = INF;
    foreach ($cands as $v) {
        if (empty($v['paradas'])) {
            $d = onibus_scrape_linha($db, $v);
            if ($d['ok']) {
                $v = $db->query('SELECT * FROM onibus_linhas WHERE id=' . (int) $v['id'])->fetch();
            }
        }
        $p = onibus_parada_mais_proxima_gps($db, $v, $lat, $lng);
        if ($p && $p['dist'] < $best) {
            $best = $p['dist'];
            $melhorVar = $v;
            $melhorParada = $p;
        }
    }
    if (!$melhorVar || !$melhorParada) {
        return ['ok' => false, 'texto' => '', 'erro' => 'não achei parada próxima (paradas sem geocodificação?)'];
    }

    $r = onibus_enviar_ponto($db, $cli, (string) $token['sender_id'], $melhorVar,
        (string) $melhorParada['id'], (string) $melhorParada['nome'], (float) $melhorParada['dist']);
    $db->prepare('UPDATE onibus_local_tokens SET respondido_em=NOW() WHERE token=?')->execute([$token['token']]);
    return ['ok' => $r['enviou'], 'texto' => '', 'erro' => ''];
}

/* ==================== Mini-app do link (menu + GPS) ===================== */

/** Menu de linhas p/ a página do link (busca por código/nome). Dedup por título. */
function onibus_menu_linhas(PDO $db, string $q = '', int $limite = 40): array
{
    if (trim($q) !== '') {
        $rows = onibus_buscar($db, $q, $limite);
    } else {
        $rows = $db->query("SELECT * FROM onibus_linhas
            ORDER BY (route_code REGEXP '^[0-9]+$') DESC, CAST(route_code AS UNSIGNED), route_code
            LIMIT " . (int) $limite)->fetchAll();
    }
    $out = [];
    $vistos = [];
    foreach ($rows as $l) {
        $chave = onibus_norm(onibus_titulo($l));
        if (isset($vistos[$chave])) {
            continue;
        }
        $vistos[$chave] = true;
        $out[] = [
            'id'      => (int) $l['id'],
            'code'    => trim((string) $l['route_code']),
            'nome'    => (string) ($l['service_mnemonic'] ?: $l['route_mnemonic']),
            'empresa' => (string) $l['company'],
        ];
    }
    return $out;
}

/** Previsão ao vivo por (linha, GPS): acha a parada mais próxima e retorna dados estruturados. */
function onibus_prev_por_gps(PDO $db, int $linhaId, float $lat, float $lng): array
{
    $v = $db->query('SELECT * FROM onibus_linhas WHERE id=' . (int) $linhaId)->fetch();
    if (!$v) {
        return ['ok' => false, 'erro' => 'linha não encontrada'];
    }
    if (($v['service_id'] ?? '') === '' || empty($v['paradas'])) {
        $d = onibus_scrape_linha($db, $v);
        if ($d['ok']) {
            $v = $db->query('SELECT * FROM onibus_linhas WHERE id=' . (int) $v['id'])->fetch();
        }
    }
    $p = onibus_parada_mais_proxima_gps($db, $v, $lat, $lng);
    if (!$p) {
        // nenhuma parada geocodificada ainda -> o cron vai preencher; peça pra tentar de novo
        return ['ok' => false, 'preparando' => true,
            'erro' => 'Estou preparando os pontos dessa linha. Tente de novo em instantes 🙂'];
    }
    // essa linha não passa perto de você? (ponto mais próximo além do limite)
    $limiar = max(300, (int) cfg_get('onibus_ponto_max_m', '1500'));
    $longe = ((float) ($p['dist'] ?? 0)) > $limiar;
    $pv = onibus_previsao((string) $v['service_id'], (string) $p['id']);
    $mins = [];
    $buses = [];
    if ($pv['ok']) {
        foreach ($pv['veiculos'] as $veic) {
            if ($veic['minutos'] > 0) {
                $mins[] = $veic['minutos'];
            }
            if ($veic['lat'] !== null && $veic['lng'] !== null) {
                $buses[] = ['lat' => (float) $veic['lat'], 'lng' => (float) $veic['lng'], 'min' => (int) $veic['minutos']];
            }
        }
    }
    // coordenadas da parada (só cache; já foi geocodificada na busca) p/ o mapa
    $g = onibus_geocode($db, (string) $p['nome'], false);
    return [
        'ok'       => true,
        'linha_id' => (int) $v['id'],
        'titulo'   => onibus_titulo($v),
        'stop_id'  => (string) $p['id'],
        'parada'   => (string) $p['nome'],
        'dist'     => (int) round((float) ($p['dist'] ?? 0)),
        'longe'    => $longe,
        'minutos'  => array_slice($mins, 0, 4),
        'stop_lat' => is_array($g) ? $g['lat'] : null,
        'stop_lng' => is_array($g) ? $g['lng'] : null,
        'buses'    => $buses,
    ];
}

/** Cria um token de link temporário e envia a mensagem CURTA no Direct com o link. */
function onibus_enviar_link(PDO $db, array $cli, string $sender, string $routeCode = '', string $sentido = ''): array
{
    $tok = onibus_token_criar($db, (int) $cli['id'], $sender, $routeCode, $sentido);
    $link = onibus_app_link($tok, $routeCode);
    $qual = $routeCode !== '' ? ('a linha ' . trim($routeCode)) : 'os ônibus de Maceió';
    $txt = "🚌 Pra ver {$qual} ao vivo pertinho de você, toque aqui 👇\n" . $link
        . "\n\nÉ rapidinho: escolhe a linha, compartilha sua localização e eu mostro em quantos minutos passa. 😉";
    $env = dm_enviar($db, $cli, $sender, $txt, 'auto_onibus');
    return ['enviou' => (bool) ($env['ok'] ?? false), 'motivo' => $env['erro'] ?? ''];
}

/** A pessoa quer o próximo AGORA/ao vivo? (para decidir coletar o local). */
function onibus_quer_agora(string $textoNorm): bool
{
    return (bool) preg_match('/\b(agora|proximo|proxima|chega|daqui|quanto tempo|tempo real|ao vivo|ta chegando|vem|demora|falta)\b/', $textoNorm);
}

/* ============================ Formatação ================================ */

/** Nome amigável da linha. */
function onibus_titulo(array $l): string
{
    $cod = trim((string) $l['route_code']);
    $nome = trim((string) ($l['service_mnemonic'] ?: $l['route_mnemonic']));
    return trim(($cod !== '' ? "Linha {$cod}" : 'Linha') . ($nome !== '' ? " — {$nome}" : ''));
}

/** Ordena horários por dia da semana (Domingo..Sábado). */
function onibus_ordena_dias(array $h): array
{
    $ordem = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];
    uksort($h, function ($a, $b) use ($ordem) {
        $ia = array_search($a, $ordem, true);
        $ib = array_search($b, $ordem, true);
        return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
    });
    return $h;
}

/** Monta o texto da resposta para o Direct. $aoVivo tenta a previsão da 1ª parada. */
function onibus_texto_resposta(array $l, bool $aoVivo = true): string
{
    $linhas = ['🚌 ' . onibus_titulo($l)];
    if (($l['company'] ?? '') !== '') {
        $linhas[] = '🏢 ' . $l['company'];
    }

    $h = json_decode((string) ($l['horarios'] ?? ''), true);
    if (is_array($h) && $h) {
        $h = onibus_ordena_dias($h);
        $linhas[] = '';
        $linhas[] = '🕒 Horário de operação:';
        foreach ($h as $dia => $faixa) {
            $linhas[] = "• {$dia}: {$faixa}";
        }
    } elseif (($l['operacao'] ?? '') !== '') {
        $linhas[] = '';
        $linhas[] = '🕒 Operação: ' . $l['operacao'];
    }

    $paradas = json_decode((string) ($l['paradas'] ?? ''), true);
    if (is_array($paradas) && count($paradas) >= 2) {
        $org = (string) ($paradas[0]['nome'] ?? '');
        $dst = (string) ($paradas[count($paradas) - 1]['nome'] ?? '');
        $curto = fn($s) => mb_strlen($s) > 40 ? mb_substr($s, 0, 38) . '…' : $s;
        if ($org !== '' && $dst !== '') {
            $linhas[] = '';
            $linhas[] = '📍 ' . $curto($org) . ' → ' . $curto($dst);
        }
    }

    if ($aoVivo && ($l['service_id'] ?? '') !== '' && is_array($paradas) && !empty($paradas[0]['id'])) {
        $pv = onibus_previsao((string) $l['service_id'], (string) $paradas[0]['id']);
        if ($pv['ok'] && $pv['veiculos']) {
            $mins = [];
            foreach (array_slice($pv['veiculos'], 0, 3) as $v) {
                if ($v['minutos'] > 0) {
                    $mins[] = $v['minutos'] . ' min';
                }
            }
            if ($mins) {
                $linhas[] = '';
                $linhas[] = '⏱️ Ao vivo (1º ponto): ' . implode(', ', $mins);
            }
        }
    }

    return implode("\n", $linhas);
}

/**
 * Auto-resposta determinística no Direct quando perguntam sobre uma linha.
 * NÃO usa IA. Só responde se casar com alguma linha do catálogo (senão devolve
 * enviou=false e deixa o fluxo normal seguir). Liga/desliga por perfil em
 * dm_onibus_ativo (0/1) e no geral em onibus_ativo.
 * Retorna [enviou, motivo, linha].
 */
function onibus_responder_direct(PDO $db, array $cliente, string $remetenteId, string $texto): array
{
    if (!onibus_ativo()) {
        return ['enviou' => false, 'motivo' => 'módulo ônibus desligado', 'linha' => ''];
    }
    if ((string) pcfg_get((int) $cliente['id'], 'dm_onibus_ativo', '0') !== '1') {
        return ['enviou' => false, 'motivo' => 'perfil sem dm_onibus_ativo', 'linha' => ''];
    }
    if (trim($texto) === '') {
        return ['enviou' => false, 'motivo' => 'texto vazio', 'linha' => ''];
    }

    // "parar/cancelar" -> encerra o acompanhamento ativo desta pessoa
    $qn = onibus_norm($texto);
    if (preg_match('/\b(parar|para de|pare|cancelar|cancela|chega|stop|nao quero mais|encerrar)\b/', $qn)) {
        $n = onibus_cancelar_alertas($db, (int) $cliente['id'], $remetenteId);
        if ($n > 0) {
            $env = dm_enviar($db, $cliente, $remetenteId, '👍 Parei os avisos do ônibus. Quando precisar, é só me chamar.', 'auto_onibus');
            return ['enviou' => (bool) ($env['ok'] ?? false), 'motivo' => 'cancelou alerta', 'linha' => 'cancel'];
        }
    }

    // Gatilho anti-falso-positivo: só responde por NOME se houver intenção de
    // transporte ("ônibus/linha/passa"...) OU casamento forte (2+ palavras de
    // lugar). Código de linha (ex.: "0004") sempre dispara. Assim "bom dia" passa.
    $q = onibus_norm($texto);
    $temCodigo  = (bool) preg_match('/\b\d{2,4}\b/', $q);
    $intencao   = onibus_tem_intencao($q);
    $palavras   = onibus_palavras_lugar($q);

    $achados = onibus_buscar($db, $texto, 3);

    // Intenção clara de ônibus, mas sem linha específica -> manda o link do MENU.
    if (!$achados) {
        if ($intencao) {
            $r = onibus_enviar_link($db, $cliente, $remetenteId, '', '');
            return ['enviou' => $r['enviou'], 'motivo' => 'link menu', 'linha' => 'menu'];
        }
        return ['enviou' => false, 'motivo' => 'nenhuma linha casou', 'linha' => ''];
    }

    if (!$temCodigo && !$intencao) {
        // sem código nem intenção: exige casamento forte (>=2 palavras de lugar
        // presentes no nome da 1ª linha) — evita disparar em conversa comum.
        $alvo = ' ' . onibus_norm($achados[0]['service_mnemonic'] . ' ' . $achados[0]['route_mnemonic']) . ' ';
        $hits = 0;
        foreach ($palavras as $w) {
            if (strpos($alvo, ' ' . $w . ' ') !== false) {
                $hits++;
            }
        }
        if ($hits < 2) {
            return ['enviou' => false, 'motivo' => 'sem intenção/código e match fraco', 'linha' => ''];
        }
    }

    $ls   = onibus_extrair_local_sentido($q);
    $code = trim((string) $achados[0]['route_code']);

    // Atalho: se a pessoa JÁ disse onde está, responde AO VIVO na hora (+ oferta de alerta).
    if ($ls['local'] !== '') {
        $res = onibus_resolver_no_ponto($db, $code, $ls['sentido'], $ls['local']);
        if ($res) {
            $r = onibus_enviar_ponto($db, $cliente, $remetenteId, $res['variante'],
                (string) $res['parada']['id'], (string) $res['parada']['nome'], null);
            return ['enviou' => $r['enviou'], 'motivo' => 'ponto', 'linha' => 'ponto'];
        }
    }

    // Diálogo CURTO e didático: manda o link temporário (menu + GPS + tempo real).
    $r = onibus_enviar_link($db, $cliente, $remetenteId, $code, $ls['sentido']);
    return ['enviou' => $r['enviou'], 'motivo' => $r['motivo'], 'linha' => 'link'];
}
