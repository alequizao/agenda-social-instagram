<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * cron_onibus_geo.php — geocodifica (lat/lng) os endereços das paradas, aos poucos.
 *
 * Isso deixa a consulta do app INSTANTÂNEA (ela só lê o cache; não chama a rede).
 * Roda A CADA 2 MINUTOS e processa um lote (respeitando ~1 req/s do Nominatim).
 *
 * Crontab (a cada 2 minutos):
 *   [barra]2 no 1o campo   php .../cron_onibus_geo.php  >> .../logs/cron_onibus_geo.log 2>&1
 */

require __DIR__ . '/init.php';
require __DIR__ . '/lib_onibus.php';

log_rotacionar(__DIR__ . '/logs/cron_onibus_geo.log');

$lock = fopen(__DIR__ . '/logs/cron_onibus_geo.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // já rodando
}

$db = db();
$limite = (int) ($argv[1] ?? getenv('GEO_LOTE') ?: 40); // ~40 * 1.1s = ~44s < 2min
$limite = max(1, min(80, $limite));

// endereços já no cache (evita reprocessar)
$cache = [];
foreach ($db->query('SELECT chave FROM onibus_geocode')->fetchAll(PDO::FETCH_COLUMN) as $c) {
    $cache[$c] = true;
}

$feitos = 0;
$linhas = $db->query('SELECT paradas FROM onibus_linhas
    WHERE detalhe_em IS NOT NULL AND paradas IS NOT NULL
    ORDER BY atualizado_em DESC')->fetchAll(PDO::FETCH_COLUMN);

foreach ($linhas as $pjson) {
    if ($feitos >= $limite) {
        break;
    }
    $paradas = json_decode((string) $pjson, true);
    if (!is_array($paradas)) {
        continue;
    }
    foreach ($paradas as $p) {
        if ($feitos >= $limite) {
            break;
        }
        $end = trim((string) ($p['nome'] ?? ''));
        if ($end === '') {
            continue;
        }
        $chave = md5(onibus_norm($end));
        if (isset($cache[$chave])) {
            continue;
        }
        onibus_geocode($db, $end, true); // grava no cache (sucesso OU null)
        $cache[$chave] = true;
        $feitos++;
    }
}

if ($feitos > 0) {
    $faltam = 0; // estimativa simples: quantas ainda não estão no cache não é recalculada aqui
    echo '[' . date('Y-m-d H:i:s') . "] geocode: {$feitos} novos endereços processados.\n";
}

flock($lock, LOCK_UN);
fclose($lock);
