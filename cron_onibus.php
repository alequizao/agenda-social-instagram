<?php
declare(strict_types=1);

/**
 * cron_onibus.php — atualiza o catálogo de linhas e os horários/paradas (CittaMobi).
 *
 * Roda como CLI (root). NUNCA no load web (chamadas HTTP síncronas derrubariam
 * a página). Faz duas coisas:
 *   1) Atualiza o CATÁLOGO de linhas da cidade (rápido: 1 requisição).
 *   2) Preenche DETALHES (horários/paradas/serviceId) das linhas ainda sem
 *      detalhe ou mais antigas — em lotes, com pausa, para não martelar a fonte.
 *
 * Sugestão de crontab (1x/dia de madrugada; ajuste o caminho do PHP):
 *   30 3 * * * /usr/bin/php /www/wwwroot/publishdev.com.br/agendamentos/cron_onibus.php >> /www/wwwroot/publishdev.com.br/agendamentos/logs/cron_onibus.log 2>&1
 *
 * Parâmetros (env/arg):
 *   LOTE=50   -> quantas linhas detalhar por execução (padrão 40)
 */

require __DIR__ . '/init.php';
require __DIR__ . '/lib_onibus.php';

function olog(string $m): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
}

log_rotacionar(__DIR__ . '/logs/cron_onibus.log');

/* trava: uma instância por vez */
$lock = fopen(__DIR__ . '/logs/cron_onibus.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    olog('já rodando — saindo.');
    exit(0);
}

$db = db();

/* 1) catálogo */
$cat = onibus_scrape_catalogo($db);
if ($cat['ok']) {
    olog("catálogo OK: {$cat['n']} linhas.");
} else {
    olog('catálogo FALHOU: ' . $cat['erro']);
}

/* 2) detalhes em lote — primeiro as sem detalhe, depois as mais antigas */
$lote = (int) ($argv[1] ?? getenv('LOTE') ?: 40);
$lote = max(1, min(300, $lote));
$linhas = $db->query('SELECT id, slug FROM onibus_linhas
    ORDER BY detalhe_em IS NULL DESC, detalhe_em ASC
    LIMIT ' . $lote)->fetchAll();

$ok = 0;
$falha = 0;
foreach ($linhas as $l) {
    $r = onibus_scrape_linha($db, $l);
    if ($r['ok']) {
        $ok++;
    } else {
        $falha++;
        olog("linha #{$l['id']} falhou: {$r['erro']}");
    }
    usleep(400000); // 0,4s entre linhas — educado com a fonte
}
olog("detalhes: {$ok} ok, {$falha} falha (lote={$lote}).");

flock($lock, LOCK_UN);
fclose($lock);
