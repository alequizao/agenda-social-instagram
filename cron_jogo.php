<?php

declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * cron_jogo.php — manutenção do Jogo da Forca (rodar a cada 5 minutos).
 *
 * 1) Expira partidas paradas há mais de dm_jogo_timeout minutos e AVISA a pessoa
 *    (hoje a expiração só acontecia quando ela mandava outra mensagem).
 * 2) Sorteia a palavra do dia de cada perfil com o jogo ligado (assim o 1º jogador
 *    do dia não paga a espera da IA).
 * 3) Manda o RANKING SEMANAL na segunda-feira (dm_jogo_ranking_semanal=1).
 * 4) Limpa artes antigas de uploads/jogo.
 *
 * Crontab sugerido:
 *   [a cada 5 min] /usr/bin/php /www/wwwroot/publishdev.com.br/agendamentos/cron_jogo.php >/dev/null 2>&1
 */

require __DIR__ . '/init.php';
require __DIR__ . '/lib_direct.php';
require __DIR__ . '/lib_jogo.php';
require __DIR__ . '/lib_jogos.php';

$lock = fopen(__DIR__ . '/logs/cron_jogo.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // já tem um rodando
}

function jlog(string $m): void
{
    @file_put_contents(__DIR__ . '/logs/cron_jogo.log', '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n", FILE_APPEND);
}

$db = db();
$clientes = $db->query('SELECT * FROM ' . DB_PREFIX . 'clientes')->fetchAll();

foreach ($clientes as $cliente) {
    $cid = (int) $cliente['id'];
    if (!jogo_ativo($cid)) {
        continue;
    }

    /* ---- 1) partidas paradas ---- */
    $timeout = max(0, (int) pcfg_get($cid, 'dm_jogo_timeout', '60'));
    if ($timeout > 0) {
        $st = $db->prepare('SELECT * FROM ' . DB_PREFIX . 'jogo_partidas
            WHERE cliente_id=? AND status="ativa" AND atualizado_em < (NOW() - INTERVAL ? MINUTE)
            LIMIT 20');
        $st->execute([$cid, $timeout]);
        foreach ($st->fetchAll() as $p) {
            $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_partidas SET status="expirada", finalizada_em=NOW() WHERE id=?')
               ->execute([(int) $p['id']]);
            $txt = "⌛ Sua partida expirou (ficou parada por " . $timeout . " min).\n\n"
                 . 'A palavra era: ' . mb_strtoupper((string) $p['palavra'], 'UTF-8')
                 . "\n\n🎮 Manda \"forca\" quando quiser começar outra.";
            $r = dm_enviar($db, $cliente, (string) $p['remetente_id'], $txt, 'auto_jogo');
            jlog('expirou partida #' . $p['id'] . ' cliente#' . $cid . ' avisou=' . (!empty($r['ok']) ? '1' : '0'));
        }
    }

    /* ---- 1b) sessões do arcade paradas ---- */
    if ($timeout > 0) {
        $st = $db->prepare('SELECT * FROM ' . DB_PREFIX . 'jogo_sessoes
            WHERE cliente_id=? AND status="ativa" AND atualizado_em < (NOW() - INTERVAL ? MINUTE) LIMIT 20');
        $st->execute([$cid, $timeout]);
        foreach ($st->fetchAll() as $ss) {
            $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_sessoes SET status="expirada" WHERE id=?')->execute([(int) $ss['id']]);
            $cat = jogos_catalogo();
            $rot = (string) ($cat[(string) $ss['jogo']]['rotulo'] ?? $ss['jogo']);
            $r = dm_enviar($db, $cliente, (string) $ss['remetente_id'],
                "⌛ Sua partida de " . $rot . " expirou (ficou parada).\n\n🕹️ Manda \"jogo\" pra ver os jogos.", 'auto_jogo');
            jlog('expirou sessão #' . $ss['id'] . ' (' . $ss['jogo'] . ') cliente#' . $cid . ' avisou=' . (!empty($r['ok']) ? '1' : '0'));
        }
    }

    /* ---- 2) palavra do dia adiantada ---- */
    if ((string) pcfg_get($cid, 'dm_jogo_palavra_dia', '1') === '1') {
        $tem = $db->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'jogo_dia WHERE cliente_id=? AND dia=CURDATE()');
        $tem->execute([$cid]);
        if ((int) $tem->fetchColumn() === 0) {
            $pd = jogo_palavra_do_dia($db, $cid);
            jlog('palavra do dia cliente#' . $cid . ': ' . ($pd['palavra'] ?? 'FALHOU'));
        }
    }

    /* ---- 3) ranking semanal (segunda-feira, uma vez) ---- */
    if ((string) pcfg_get($cid, 'dm_jogo_ranking_semanal', '0') === '1'
        && (int) date('N') === 1
        && (int) date('G') >= (int) pcfg_get($cid, 'dm_jogo_ranking_hora', '9')) {
        $marca = 'dm_jogo_ranking_enviado';
        if ((string) pcfg_get($cid, $marca, '') !== date('Y-m-d')) {
            $lista = jogo_ranking($db, $cid, 7, 10);
            if ($lista) {
                $texto = jogo_ranking_texto($db, $cid, 7);
                $n = 0;
                foreach ($lista as $l) {
                    $r = dm_enviar($db, $cliente, (string) $l['remetente_id'], $texto, 'auto_jogo');
                    $n += !empty($r['ok']) ? 1 : 0;
                    usleep(400000); // não manda em rajada
                }
                jlog('ranking semanal cliente#' . $cid . ' enviado p/ ' . $n . ' pessoa(s)');
            }
            pcfg_set($cid, $marca, date('Y-m-d'));
        }
    }
}

/* ---- 4) limpeza de artes antigas ---- */
$dir = __DIR__ . '/uploads/jogo';
if (is_dir($dir)) {
    $n = 0;
    foreach (glob($dir . '/*.jpg') ?: [] as $f) {
        if (@filemtime($f) < time() - 86400 && @unlink($f)) {
            $n++;
        }
    }
    if ($n > 0) {
        jlog('limpou ' . $n . ' arte(s) antiga(s)');
    }
}

flock($lock, LOCK_UN);
fclose($lock);
exit(0);
