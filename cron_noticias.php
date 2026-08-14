<?php
declare(strict_types=1);

/**
 * cron_noticias.php — automação MULTI-PERFIL.
 * A cada minuto, para CADA perfil com automação ligada (config_perfil ia_ativo=1):
 *   1) sincroniza os feeds DO PERFIL (throttle 3 min);
 *   2) fluxo "auto": mantém a fila distribuída (agendador) | "aprovacao": 1 rascunho/intervalo.
 *
 * Crontab: * * * * * php cron_noticias.php >> logs/cron_noticias.log 2>&1
 */

require __DIR__ . '/init.php';
require __DIR__ . '/lib_gerador.php';
require __DIR__ . '/lib_agendador.php';

function nlog(string $m): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
}

log_rotacionar(__DIR__ . '/logs/cron_noticias.log');

$lock = fopen(__DIR__ . '/logs/cron_noticias.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

/* ---- Pausa MANUAL da automação (botão): não busca nem agenda nada ---- */
if (automacao_pausada()) {
    nlog('automação PAUSADA manualmente (botão). Sem buscar/agendar até religar.');
    exit(0);
}

$db = db();
$perfis = perfis_ativos($db);
if (!$perfis) {
    exit(0); // nenhum perfil com automação ligada
}

foreach ($perfis as $c) {
    $cid = (int) $c['id'];

    // 1) sincroniza feeds do perfil (throttle 3 min)
    $ult = (string) pcfg_get($cid, 'noticias_sync_em', '');
    if ($ult === '' || (time() - strtotime($ult)) > 180) {
        $n = noticias_sincronizar($db, $cid);
        pcfg_set($cid, 'noticias_sync_em', date('Y-m-d H:i:s'));
        if ($n > 0) {
            nlog("#{$cid} {$c['nome']}: sync +{$n} no pool.");
        }
    }

    // 1b) CLASSIFICA o que chegou (filtro por tema + filtro do filtro por IA nas dúvidas)
    $cl = temas_classificar_pendentes($db, $cid, 120);
    if ($cl['classificadas'] > 0) {
        nlog("#{$cid} {$c['nome']}: classificadas {$cl['classificadas']} (IA {$cl['ia']}).");
    }

    // 1c) self-heal do áudio: posts que saíram como imagem (gerados no site) viram MP4 com som
    $fz = gerador_finalizar_audio($db, $cid, 4);
    if ($fz > 0) {
        nlog("#{$cid} {$c['nome']}: áudio finalizado em {$fz} post(s).");
    }

    $fluxo = (string) pcfg_get($cid, 'ia_fluxo', 'curadoria');

    // 2) fluxo CURADORIA (redator-chefe): só agenda aprovadas + ônibus (auto)
    if ($fluxo === 'curadoria') {
        $r = curadoria_rodar($db, $cid);
        if ($r['criados'] > 0) {
            nlog("#{$cid} {$c['nome']}: " . $r['detalhe']);
        }
        continue;
    }

    // 2b) fluxo AUTOMÁTICO (legado): agenda distribuído sozinho
    if ($fluxo === 'auto') {
        $r = agendador_rodar($db, $cid);
        if ($r['criados'] > 0) {
            nlog("#{$cid} {$c['nome']}: " . $r['detalhe']);
        }
        continue;
    }

    // ---- fluxo APROVAÇÃO (1 rascunho por intervalo) ----
    $hora = (int) date('G');
    $hIni = (int) substr((string) pcfg_get($cid, 'ia_janela_inicio', '05:00'), 0, 2);
    $hFim = (int) substr((string) pcfg_get($cid, 'ia_janela_fim', '23:30'), 0, 2);
    if ($hora < $hIni || $hora >= $hFim) {
        continue;
    }
    $intervalo = max(5, (int) pcfg_get($cid, 'ia_intervalo_min', '240'));
    $ultExec = (string) pcfg_get($cid, 'ia_ultima_exec', '');
    if ($ultExec !== '' && (strtotime($ultExec) + $intervalo * 60) - time() > 0) {
        continue;
    }
    pcfg_set($cid, 'ia_ultima_exec', date('Y-m-d H:i:s'));
    $r = gerar_rascunho_noticia($db, $cid);
    if ($r['ok']) {
        nlog("#{$cid} {$c['nome']}: rascunho #{$r['pub_id']} — " . $r['titulo']);
    }
}
exit(0);
