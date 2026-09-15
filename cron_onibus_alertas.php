<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * cron_onibus_alertas.php — dispara os avisos "seu ônibus está chegando".
 *
 * Roda A CADA MINUTO. Para cada assinatura ativa (a pessoa tocou em
 * "🔔 me avise"), consulta a previsão ao vivo; se o ônibus está a <= limiar
 * (padrão 5 min), manda o aviso no Direct e encerra a assinatura.
 *
 * Crontab:
 *   * * * * * /usr/bin/php /www/wwwroot/publishdev.com.br/agendamentos/cron_onibus_alertas.php >> /www/wwwroot/publishdev.com.br/agendamentos/logs/cron_onibus_alertas.log 2>&1
 */

require __DIR__ . '/init.php';
require __DIR__ . '/lib_onibus.php';
require __DIR__ . '/lib_direct.php';

log_rotacionar(__DIR__ . '/logs/cron_onibus_alertas.log');

$lock = fopen(__DIR__ . '/logs/cron_onibus_alertas.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // já rodando
}

$r = onibus_alertas_processar(db());
if ($r['checados'] > 0) {
    echo '[' . date('Y-m-d H:i:s') . "] alertas: {$r['avisados']} avisados de {$r['checados']} checados.\n";
}

flock($lock, LOCK_UN);
fclose($lock);
