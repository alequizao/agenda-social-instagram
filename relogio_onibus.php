<?php
declare(strict_types=1);
date_default_timezone_set('America/Maceio');

/**
 * relogio_onibus.php — API ENXUTA para o app Garmin "Próximo Ônibus" (FR55/FR165/FR165 Music)
 * e para o painel alequizao.com/garmin (prévia, simulador e escolha de favoritos).
 *
 * Desenvolvido por Alequizao <alequizao.dev@gmail.com> · © 2026 Alequizao
 *
 * Público, sem login e SEM sessão (não usa init.php: o cookie PHPSESSID é compartilhado em
 * alequizao.com e abrir sessão aqui mexeria na sessão de outros apps do domínio).
 * Nada de scraping aqui (regra do módulo): só lê o banco e chama a previsão ao vivo do
 * CittaMobi (1 requisição rápida por favorito, a mesma que o local.php já faz).
 *
 * Ações (GET):
 *   a=prox&f=ID.STOP[,ID.STOP...]  (até 4)  → {"f":[[cod,destino,ponto,fonte,[seg,...],info], ...]}
 *        fonte: 1 = ao vivo (GPS dos ônibus) · 2 = programado (tabela de hoje) · 0 = sem previsão · -1 = inválido
 *        seg  : segundos até cada um dos próximos (no máximo 3), já em ordem
 *        info : janela de operação de hoje e frequência ("05:17-21:57 · 83 min") ou o motivo
 *   a=perto&lat=&lon=                       → {"p":[[ponto,metros,[[id,"stop",cod,destino],...]], ...]}  (até 4 pontos, 6 linhas cada)
 *   a=linhas&q=texto                        → {"l":[{id,cod,nome,destino,empresa}]}      (painel)
 *   a=paradas&l=ID                          → {"s":[{id,nome,curto}] , cod, destino}      (painel)
 *
 * Respostas curtas de propósito: o FR55 dá 128 KB ao app inteiro.
 */

require __DIR__ . '/config.php';

/* ---- bootstrap mínimo (mesmas assinaturas do init.php, sem sessão) ---- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '-03:00'");
    }
    return $pdo;
}

function cfg_get(string $chave, ?string $padrao = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT chave, valor FROM ' . DB_PREFIX . 'configuracoes')->fetchAll() as $r) {
            $cache[$r['chave']] = $r['valor'];
        }
    }
    return array_key_exists($chave, $cache) ? $cache[$chave] : $padrao;
}

require __DIR__ . '/lib_onibus.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Desenvolvido-Por: Alequizao');

function ro_sair(array $j, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Corta no servidor (o relógio não tem memória para textos longos). Termina com "." se cortou. */
function ro_curto(string $s, int $n): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if (mb_strlen($s, 'UTF-8') <= $n) {
        return $s;
    }
    return rtrim(mb_substr($s, 0, $n - 1, 'UTF-8'), ' ,-/') . '.';
}

/** "RIO LARGO MATA DO ROLO" → "Rio Largo Mata do Rolo" (só quando vem tudo em maiúsculas). */
function ro_capitaliza(string $s): string
{
    if ($s !== mb_strtoupper($s, 'UTF-8')) {
        return $s;
    }
    $s = mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    return preg_replace_callback('/\b(De|Da|Do|Das|Dos|E|Via)\b/u', fn($m) => mb_strtolower($m[1], 'UTF-8'), $s);
}

/** Destino do sentido: tira numeração, parênteses e "via ...", e pega o trecho depois do último
    " / " ou " x " ("Rio Largo / Eustáquio Gomes" → "Eustáquio Gomes"; "Ponta Verde (via Farol)" → "Ponta Verde"). */
function ro_destino(array $l): string
{
    $nome = trim((string) ($l['service_mnemonic'] ?: $l['route_mnemonic']));
    $s = preg_replace('/^\d+\s*-\s*/u', '', $nome);
    $s = preg_replace('/\([^)]*\)/u', ' ', $s);
    $s = preg_replace('/\s*-?\s*\bvia\b.*$/iu', '', $s);
    $partes = preg_split('#\s*/\s*|\s+x\s+#iu', $s);
    $d = '';
    while ($partes && $d === '') {
        $d = trim((string) array_pop($partes), " -.\t");
    }
    if ($d === '') {
        $d = $nome;
    }
    return ro_capitaliza(preg_replace('/\s+/u', ' ', $d));
}

/** Nome curto do ponto a partir do endereço: "Avenida Vaz De Castro, Rio Largo..." → "Av. Vaz De Castro". */
function ro_ponto(string $end): string
{
    $p = explode(',', $end);
    $rua = trim($p[0]);
    $rua = preg_replace(['/^Avenida\b/u', '/^Rua\b/u', '/^Rodovia\b/u', '/^Travessa\b/u', '/^Praça\b/u', '/^Estrada\b/u', '/^Conjunto\b/u'],
        ['Av.', 'R.', 'Rod.', 'Tv.', 'Pç.', 'Estr.', 'Cj.'], $rua);
    return $rua !== '' ? $rua : trim($end);
}

/** Tipo do dia (útil/sábado/domingo) — os horários programados só valem para o mesmo tipo. */
function ro_tipo_dia(string $ymd): string
{
    $w = (int) date('w', strtotime($ymd . ' 12:00:00'));
    return $w === 0 ? 'dom' : ($w === 6 ? 'sab' : 'util');
}

function ro_dia_semana(): string
{
    return ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'][(int) date('w')];
}

/** Janela de operação de hoje + frequência, bem curta: "05:17-21:57 · 83 min". */
function ro_info_hoje(array $l): string
{
    $h = json_decode((string) ($l['horarios'] ?? ''), true);
    $dia = ro_dia_semana();
    $faixa = is_array($h) ? (string) ($h[$dia] ?? '') : '';
    $pa = json_decode((string) ($l['partidas'] ?? ''), true);
    $fq = is_array($pa) ? (string) ($pa['f'][$dia] ?? '') : '';
    $faixa = str_replace(' - ', '-', $faixa);
    if ($faixa === '-') {
        return 'não roda hoje';
    }
    if ($faixa === '') {
        $faixa = str_replace(' - ', '-', preg_replace('/\s*\(.*$/', '', (string) ($l['operacao'] ?? '')));
    }
    if (preg_match('/^(\d{2}:\d{2})-\1$/', $faixa, $m)) {
        $faixa = 'só às ' . $m[1];          // uma viagem por dia
    }
    if (preg_match('/^0\D/', $fq)) {
        $fq = '';
    }
    return trim($faixa . ($fq !== '' ? ' · ' . $fq : ''), ' ·');
}

/* ================================== ações ================================== */

$a = (string) ($_GET['a'] ?? '');
$db = db();

try {
    if ($a === 'prox') {
        $f = (string) ($_GET['f'] ?? '');
        if (!preg_match('/^\d{1,7}\.[0-9a-fA-F-]{36}(,\d{1,7}\.[0-9a-fA-F-]{36}){0,3}$/', $f)) {
            ro_sair(['e' => 'favoritos inválidos'], 400);
        }
        $agora = time();
        $hoje = date('Y-m-d');
        $out = [];
        $ids = [];
        foreach (explode(',', $f) as $par) {
            [$id, $stop] = explode('.', $par, 2);
            $stop = strtolower($stop);
            $st = $db->prepare('SELECT * FROM onibus_linhas WHERE id=?');
            $st->execute([(int) $id]);
            $l = $st->fetch();
            if (!$l) {
                $out[] = ['?', 'Linha não existe', '', -1, [], 'remova e escolha de novo'];
                continue;
            }
            $ids[] = (int) $l['id'];
            $cod = trim((string) $l['route_code']);
            $dest = ro_curto(ro_destino($l), 18);
            $paradas = json_decode((string) $l['paradas'], true) ?: [];
            $pos = null;
            $nomePonto = '';
            foreach ($paradas as $i => $p) {
                if (strtolower((string) ($p['id'] ?? '')) === $stop) {
                    $pos = $i;
                    $nomePonto = (string) ($p['nome'] ?? '');
                    break;
                }
            }
            if ($pos === null) {
                $out[] = [$cod, $dest, '', -1, [], 'ponto fora da linha'];
                continue;
            }
            $ponto = ro_curto(ro_ponto($nomePonto), 22);
            $info = ro_info_hoje($l);

            // 1) ao vivo (GPS dos ônibus, CittaMobi)
            $seg = [];
            if ((string) $l['service_id'] !== '') {
                $pv = onibus_previsao((string) $l['service_id'], $stop, 6);
                if ($pv['ok']) {
                    foreach ($pv['veiculos'] as $v) {
                        if ($v['segundos'] > 0 && count($seg) < 3) {
                            $seg[] = (int) $v['segundos'];
                        }
                    }
                }
            }
            if ($seg) {
                $out[] = [$cod, $dest, $ponto, 1, $seg, $info];
                continue;
            }

            // 2) programado: tabela da própria parada, só se for do mesmo tipo de dia de hoje
            $pa = json_decode((string) ($l['partidas'] ?? ''), true);
            $lista = is_array($pa) ? ($pa['p'][$pos] ?? $pa['p'][(string) $pos] ?? null) : null;
            if (is_array($lista) && ro_tipo_dia((string) ($pa['d'] ?? '')) === ro_tipo_dia($hoje)) {
                foreach ($lista as $hm) {
                    $t = strtotime($hoje . ' ' . $hm . ':00');
                    if ($t !== false && $t >= $agora - 30 && count($seg) < 3) {
                        $seg[] = max(0, $t - $agora);
                    }
                }
                if ($seg) {
                    $out[] = [$cod, $dest, $ponto, 2, $seg, $info];
                } else {
                    $out[] = [$cod, $dest, $ponto, 0, [], $lista ? 'sem mais horários hoje' : 'não roda hoje'];
                }
                continue;
            }
            $out[] = [$cod, $dest, $ponto, 0, [], $info !== '' ? $info : 'sem previsão agora'];
        }
        // lembra que um relógio usa estas linhas: o cron diário atualiza os horários delas primeiro
        if ($ids) {
            $db->exec('UPDATE onibus_linhas SET relogio_em=NOW() WHERE id IN (' . implode(',', array_unique($ids)) . ')
                AND (relogio_em IS NULL OR relogio_em < NOW() - INTERVAL 1 HOUR)');
        }
        ro_sair(['f' => $out]);
    }

    if ($a === 'perto') {
        $lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($_GET['lon'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lon === false || $lat === null || $lon === null || abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) {
            ro_sair(['e' => 'posição inválida'], 400);
        }
        // índice ponto → coordenadas + linhas (reconstruído no máx. 1x/hora; só lê o cache de geocode)
        $arq = __DIR__ . '/logs/relogio_paradas.cache.json';
        $idx = null;
        if (is_file($arq) && filemtime($arq) > time() - 3600) {
            $idx = json_decode((string) file_get_contents($arq), true);
        }
        if (!is_array($idx)) {
            $geo = [];
            foreach ($db->query('SELECT chave, lat, lng FROM onibus_geocode WHERE lat IS NOT NULL') as $g) {
                $geo[$g['chave']] = [(float) $g['lat'], (float) $g['lng']];
            }
            $idx = [];
            foreach ($db->query("SELECT id, route_code, service_mnemonic, route_mnemonic, paradas FROM onibus_linhas
                                 WHERE paradas IS NOT NULL AND paradas <> '[]' AND service_id <> ''") as $l) {
                if (strpos((string) $l['paradas'], '{ ') !== false) {
                    continue;   // raspagem antiga quebrada (CSS no 1º nome → nomes desalinhados dos ids)
                }
                $ps = json_decode((string) $l['paradas'], true) ?: [];
                $dest = ro_curto(ro_destino($l), 16);
                foreach ($ps as $p) {
                    $nome = (string) ($p['nome'] ?? '');
                    if ($nome === '') {
                        continue;
                    }
                    $k = md5(onibus_norm($nome));
                    if (!isset($geo[$k])) {
                        continue;
                    }
                    $sid = strtolower((string) $p['id']);
                    if (!isset($idx[$sid])) {
                        $idx[$sid] = [ro_curto(ro_ponto($nome), 22), $geo[$k][0], $geo[$k][1], []];
                    }
                    $idx[$sid][3][] = [(int) $l['id'], trim((string) $l['route_code']), $dest];
                }
            }
            @file_put_contents($arq, json_encode($idx, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        $raio = max(300, (int) cfg_get('onibus_ponto_max_m', '1500'));
        $cands = [];
        foreach ($idx as $sid => $p) {
            $d = onibus_haversine((float) $lat, (float) $lon, (float) $p[1], (float) $p[2]);
            if ($d <= $raio) {
                $cands[] = [$d, $sid];
            }
        }
        usort($cands, fn($x, $y) => $x[0] <=> $y[0]);
        // paradas com o mesmo endereço (lados da rua, abrigo duplo) viram UM ponto com as linhas somadas
        $grupos = [];
        foreach ($cands as [$d, $sid]) {
            $p = $idx[$sid];
            $k = $p[0] . '|' . round((float) $p[1], 4) . '|' . round((float) $p[2], 4);
            if (!isset($grupos[$k])) {
                if (count($grupos) >= 4) {
                    continue;
                }
                $grupos[$k] = [$p[0], (int) round($d), [], []];
            }
            foreach ($p[3] as $l) {
                $v = $l[1] . '|' . $l[2];
                if (isset($grupos[$k][3][$v]) || count($grupos[$k][2]) >= 6) {
                    continue;
                }
                $grupos[$k][3][$v] = true;
                $grupos[$k][2][] = [$l[0], $sid, $l[1], $l[2]];
            }
        }
        $out = [];
        foreach ($grupos as $g) {
            $out[] = [$g[0], $g[1], $g[2]];
        }
        ro_sair(['p' => $out, 'r' => $raio]);
    }

    if ($a === 'linhas') {
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) > 60) {
            ro_sair(['e' => 'busca longa demais'], 400);
        }
        $rows = $q !== '' ? onibus_buscar($db, $q, 30)
            : $db->query("SELECT * FROM onibus_linhas ORDER BY (route_code REGEXP '^[0-9]+$') DESC, CAST(route_code AS UNSIGNED), route_code LIMIT 30")->fetchAll();
        $out = [];
        foreach ($rows as $l) {
            if ((string) ($l['service_id'] ?? '') === '') {
                continue;
            }
            $out[] = ['id' => (int) $l['id'], 'cod' => trim((string) $l['route_code']),
                'nome' => trim((string) ($l['service_mnemonic'] ?: $l['route_mnemonic'])),
                'destino' => ro_destino($l), 'empresa' => (string) $l['company']];
        }
        ro_sair(['l' => $out]);
    }

    if ($a === 'paradas') {
        $st = $db->prepare('SELECT * FROM onibus_linhas WHERE id=?');
        $st->execute([(int) ($_GET['l'] ?? 0)]);
        $l = $st->fetch();
        if (!$l) {
            ro_sair(['e' => 'linha não encontrada'], 404);
        }
        $out = [];
        foreach (json_decode((string) $l['paradas'], true) ?: [] as $p) {
            $nome = (string) ($p['nome'] ?? '');
            if (strpos($nome, '{') !== false) {
                $nome = '';   // raspagem antiga quebrada: o cron diário corrige
            }
            $out[] = ['id' => strtolower((string) $p['id']), 'nome' => $nome, 'curto' => $nome !== '' ? ro_curto(ro_ponto($nome), 22) : 'Ponto ' . ($p['seq'] ?? '')];
        }
        ro_sair(['cod' => trim((string) $l['route_code']), 'destino' => ro_destino($l), 'info' => ro_info_hoje($l), 's' => $out]);
    }

    ro_sair(['e' => 'ação desconhecida'], 400);
} catch (Throwable $e) {
    error_log('relogio_onibus: ' . $e->getMessage());
    ro_sair(['e' => 'fonte fora do ar'], 503);
}
