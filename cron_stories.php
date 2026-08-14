<?php
declare(strict_types=1);

/**
 * cron_stories.php — captura os STORIES ativos de cada cliente e guarda no histórico.
 *
 * A Graph API só devolve stories das últimas 24h; depois somem. Este cron tira uma
 * "fotografia" periódica para que o histórico de stories se acumule a partir de agora.
 *
 * Rode pelo crontab A CADA HORA (basta; stories duram 24h):
 *   0 * * * * /usr/bin/php /www/wwwroot/publishdev.com.br/agendamentos/cron_stories.php >> /www/wwwroot/publishdev.com.br/agendamentos/logs/cron_stories.log 2>&1
 *
 * (Se preferir rodar a cada minuto junto dos outros crons, o intervalo abaixo
 *  evita bater na API toda hora — ajuste em historico_stories_intervalo_min.)
 */

require __DIR__ . '/init.php';
require __DIR__ . '/lib_historico.php';

function slog(string $m): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
}

log_rotacionar(__DIR__ . '/logs/cron_stories.log');

/* ---- Trava: uma instância por vez ---- */
$lock = fopen(__DIR__ . '/logs/cron_stories.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$db = db();

/* ---- Respeita o intervalo configurado (padrão 5 min) ---- */
$intervalo = max(5, (int) cfg_get('historico_stories_intervalo_min', '5'));
$ultima = (string) cfg_get('historico_stories_em', '');
if ($ultima !== '') {
    $faltam = (strtotime($ultima) + $intervalo * 60) - time();
    if ($faltam > 0) {
        exit(0); // ainda no intervalo
    }
}
cfg_set('historico_stories_em', date('Y-m-d H:i:s'));

$clientes = $db->query('SELECT id, nome, access_token, ig_user_id FROM ' . DB_PREFIX . 'clientes
    WHERE access_token IS NOT NULL AND access_token <> "" AND ig_user_id IS NOT NULL AND ig_user_id <> ""')
    ->fetchAll();

if (!$clientes) {
    slog('nenhum cliente conectado.');
    exit(0);
}

$totalCap = 0;
foreach ($clientes as $c) {
    // 1) captura os stories ativos + insights (antes de expirarem)
    $r = historico_capturar_stories($db, $c);
    if ($r['ok']) {
        $totalCap += $r['capturados'];
        if ($r['capturados'] > 0) {
            slog("cliente #{$c['id']} ({$c['nome']}): {$r['capturados']} story(ies) capturado(s).");
        }
    } else {
        slog("cliente #{$c['id']} ({$c['nome']}): erro stories — " . $r['erro']);
    }

    // 2) sincroniza o histórico de feed/reels + estatísticas (= botão "Sincronizar" automático)
    $rm = historico_sync_midias($db, $c, 50);
    if ($rm['ok']) {
        if ($rm['novas'] > 0 || $rm['insights'] > 0) {
            slog("cliente #{$c['id']} ({$c['nome']}): histórico +{$rm['novas']} post(s), {$rm['insights']} estatística(s).");
        }
    } else {
        slog("cliente #{$c['id']} ({$c['nome']}): erro histórico — " . $rm['erro']);
    }
}
slog("total stories capturados neste ciclo: {$totalCap}.");
exit(0);
