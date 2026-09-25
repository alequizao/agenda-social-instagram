<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * cron_publicar.php  -  Publicador automatico das postagens agendadas.
 *
 * Rode pelo crontab a cada minuto (recomendado):
 *   * * * * * /usr/bin/php /www/wwwroot/publishdev.com.br/agendamentos/cron_publicar.php >> /www/wwwroot/publishdev.com.br/agendamentos/logs/cron.log 2>&1
 *
 * - Usa flock para garantir UMA execucao por vez (evita publicar duas vezes).
 * - Pega publicacoes "agendado" ja vencidas e "processando" (para retomar videos).
 */

require __DIR__ . '/init.php';
require __DIR__ . '/lib_publicador.php';

/* ---- Trava: so uma instancia roda por vez ---- */
$lockFile = __DIR__ . '/logs/cron.lock';
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[" . date('Y-m-d H:i:s') . "] outra execucao em andamento, saindo.\n");
    exit(0);
}

function logln(string $m): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
}

log_rotacionar(__DIR__ . '/logs/cron.log');

/* ---- Pausa MANUAL da automação (botão; religa quando o usuário quiser) ---- */
if (automacao_pausada()) {
    logln('automação PAUSADA manualmente (botão). Nada será publicado até religar.');
    flock($lock, LOCK_UN);
    exit(0);
}

$db = db();

/* ---- Pausa global por rate limit da Meta ---- */
$pausa = pub_pausa_restante();
if ($pausa > 0) {
    logln('em pausa por rate limit da Meta; retoma em ' . ceil($pausa / 60) . ' min.');
    flock($lock, LOCK_UN);
    exit(0);
}

/* ---- Renova tokens perto de expirar (<=10 dias) OU sem validade conhecida ---- */
$tokens = $db->query('SELECT id, nome, ig_username, access_token, token_expira_em
    FROM ' . DB_PREFIX . 'clientes
    WHERE access_token IS NOT NULL AND access_token <> ""
      AND (token_expira_em IS NULL OR token_expira_em <= (NOW() + INTERVAL 10 DAY))')->fetchAll();
foreach ($tokens as $cli) {
    // apos uma falha, so tenta de novo daqui 1h (nao martela a Meta a cada minuto)
    $ultimaFalha = (int) cfg_get('token_falha_ts_' . (int) $cli['id'], '0');
    if ($ultimaFalha > 0 && (time() - $ultimaFalha) < 3600) {
        continue;
    }
    // sem validade no banco: descobre com a Meta antes de renovar a toa
    if ($cli['token_expira_em'] === null) {
        $ts = token_expira_timestamp((string) $cli['access_token']);
        if ($ts > 0) {
            $db->prepare('UPDATE ' . DB_PREFIX . 'clientes SET token_expira_em=? WHERE id=?')
               ->execute([date('Y-m-d H:i:s', $ts), (int) $cli['id']]);
            logln('validade descoberta p/ cliente #' . (int) $cli['id'] . ': ' . date('Y-m-d H:i:s', $ts));
            if ($ts > time() + 10 * 86400) {
                continue; // ainda longe de vencer
            }
        }
    }
    $r = cliente_renovar_token($db, $cli);
    if ($r['ok']) {
        logln('token renovado: cliente #' . (int) $cli['id'] . ' (' . (string) $cli['nome'] . ') valido ate ' . ($r['expira'] ?? 'sem data'));
        cfg_set('token_falha_ts_' . (int) $cli['id'], '0');
        continue;
    }
    logln('falha ao renovar token do cliente #' . (int) $cli['id'] . ' (' . (string) $cli['nome'] . '): ' . $r['erro']);
    cfg_set('token_falha_ts_' . (int) $cli['id'], (string) time());
    pub_avisar_token($db, $cli, $r['erro']);
}

/* Marca posts atrasados/perdidos como erro antes de processar */
pub_marcar_atrasados_como_erro($db);

/* Pega ate 20 publicacoes por execucao:
   - agendadas e vencidas
   - ou ja em processamento (retomar) */
$sql = 'SELECT * FROM ' . DB_PREFIX . 'publicacoes
        WHERE (status = "agendado" AND agendado_para <= NOW())
           OR  status = "processando"
        ORDER BY agendado_para ASC
        LIMIT 20';
$pubs = $db->query($sql)->fetchAll();

if (!$pubs) {
    logln('nada a publicar.');
    flock($lock, LOCK_UN);
    exit(0);
}

logln(count($pubs) . ' publicacao(oes) na fila.');

foreach ($pubs as $p) {
    $id = (int) $p['id'];
    try {
        $r = processar_publicacao($db, $p);
        logln("#{$id} [{$p['tipo']}] -> {$r['estado']}: {$r['msg']}");
    } catch (Throwable $e) {
        pub_marcar_erro($db, $id, 'Excecao: ' . $e->getMessage());
        logln("#{$id} EXCECAO: " . $e->getMessage());
    }
}

flock($lock, LOCK_UN);
exit(0);
