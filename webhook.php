<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * webhook.php — endpoint público do Webhook da Meta (mensagens do Direct).
 *
 * Configure na Meta:
 *   Callback URL: https://publishdev.com.br/agendamentos/webhook.php
 *   Verify token: o mesmo valor de cfg('dm_verify_token')
 *   Campo assinado: messages
 *
 * GET  -> verificação (responde hub.challenge)
 * POST -> evento de mensagem (guarda + auto-resposta)
 */

require __DIR__ . '/init.php';
require __DIR__ . '/lib_direct.php';
require __DIR__ . '/lib_onibus.php';
require __DIR__ . '/lib_jogo.php';
require __DIR__ . '/lib_jogos.php';

function wlog(string $m): void
{
    @file_put_contents(__DIR__ . '/logs/webhook.log', '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n", FILE_APPEND);
}

/* ---- 1) Verificação do webhook (GET) ---- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $verify = (string) cfg_get('dm_verify_token', '');
    if ($verify !== ''
        && ($_GET['hub_mode'] ?? '') === 'subscribe'
        && ($_GET['hub_verify_token'] ?? '') === $verify) {
        echo (string) ($_GET['hub_challenge'] ?? '');
        exit;
    }
    http_response_code(403);
    echo 'forbidden';
    exit;
}

/* ---- 2) Evento (POST) ---- */
$raw = (string) file_get_contents('php://input');

// (opcional) valida a assinatura se houver IG_APP_SECRET
if (defined('IG_APP_SECRET') && IG_APP_SECRET !== '') {
    $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $calc = 'sha256=' . hash_hmac('sha256', $raw, IG_APP_SECRET);
    if (!hash_equals($calc, $sig)) {
        http_response_code(403);
        wlog('assinatura inválida');
        exit;
    }
}

// responde rápido p/ a Meta e processa em seguida
http_response_code(200);
echo 'EVENT_RECEIVED';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['object'] ?? '') !== 'instagram') {
        exit;
    }
    $db = db();
    foreach (($data['entry'] ?? []) as $entry) {
        foreach (($entry['messaging'] ?? []) as $ev) {
            $msg = $ev['message'] ?? null;
            if (!$msg || !empty($msg['is_echo'])) {
                continue; // ignora ecos (mensagens que NÓS enviamos)
            }
            $texto = trim((string) ($msg['text'] ?? ''));
            // anexo (foto/vídeo/áudio) recebido
            $att      = $msg['attachments'][0] ?? null;
            $midia    = $att ? (string) ($att['payload']['url'] ?? '') : '';
            $midiaTipo = '';
            if ($att) {
                $t = strtolower((string) ($att['type'] ?? ''));
                $midiaTipo = in_array($t, ['image', 'video', 'audio'], true) ? $t : 'image';
            }
            if ($texto === '' && $midia === '') {
                continue; // nada utilizável
            }
            $senderId    = (string) ($ev['sender']['id'] ?? '');
            $recipientId = (string) ($ev['recipient']['id'] ?? '');
            $mid         = isset($msg['mid']) ? (string) $msg['mid'] : null;
            if ($senderId === '' || $recipientId === '') {
                continue;
            }

            $cliente = dm_cliente_por_ig($db, $recipientId);
            if (!$cliente) {
                wlog('sem cliente p/ ig_user_id=' . $recipientId);
                continue;
            }
            $soJogo = false; // true = remetente é perfil nosso; só o jogo pode responder
            $storyId = (string) ($msg['reply_to']['story']['id'] ?? '');
            $storyProprio = false; // perfil nosso respondendo story: link liberado (não gera loop)
            $conversaId = dm_conversa_obter($db, (int) $cliente['id'], $senderId);
            // se a conversa ainda não tem nome, tenta descobrir o @username na Meta
            $temNome = (string) $db->query('SELECT nome FROM ' . DB_PREFIX . 'dm_conversas WHERE id=' . (int) $conversaId)->fetchColumn();
            if ($temNome === '' || $temNome === null) {
                dm_perfil_buscar($db, $cliente, $senderId);
            }
            $nova = dm_registrar($db, $conversaId, 'in', $texto, $mid, 'humano', $midia ?: null, $midiaTipo ?: null);
            // ANTI-LOOP: o remetente é OUTRO perfil conectado neste painel? (nossos dois @
            // conversando entre si) -> guarda a mensagem, mas NÃO dispara automação nenhuma,
            // senão um bot responde o outro infinitamente. O sender.id vem ESCOPADO (não é o
            // ig_user_id), por isso a checagem é pelo @username da conversa.
            $convNome = trim((string) $db->query('SELECT nome FROM ' . DB_PREFIX . 'dm_conversas WHERE id=' . (int) $conversaId)->fetchColumn());
            if ($convNome !== '') {
                $stBot = $db->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'clientes WHERE LOWER(nome)=LOWER(?) OR ig_user_id=?');
                $stBot->execute([$convNome, $senderId]);
                if ((int) $stBot->fetchColumn() > 0) {
                    // exceção controlada: JOGADA curta (letra/palavra) continua valendo, pra
                    // poder testar o jogo do nosso outro @. Todo o resto é ignorado.
                    $ehJogada = jogo_ativo((int) $cliente['id']) && $texto !== '' && mb_strlen($texto, 'UTF-8') <= 20;
                    $storyProprio = $storyId !== '';
                    $ehJogada = $ehJogada || $storyProprio;
                    wlog('perfil nosso @' . $convNome . ' -> cliente#' . $cliente['id']
                        . ($ehJogada ? ' (só jogo)' : ' IGNORADO (anti-loop)'));
                    if (!$ehJogada) {
                        continue;
                    }
                    $soJogo = true;
                }
            }

            // resposta a um STORY nosso? envia o link da matéria de origem (determinístico)
            $respLink = false;
            if ($nova && (!$soJogo || $storyProprio) && $storyId !== '') {
                $r = dm_responder_link_story($db, $cliente, $senderId, $storyId);
                $respLink = $r['enviou'];
                wlog(($respLink ? 'LINK ENVIADO' : 'link NÃO enviado')
                    . " | story={$storyId} cliente#{$cliente['id']} | {$r['motivo']}"
                    . ($r['url'] !== '' ? " | {$r['url']}" : ''));
            } elseif ($nova && !$soJogo && $texto !== '') {
                // ajuda a diagnosticar: msg recebida que NÃO é resposta de story
                wlog("msg recebida (não é resposta de story) cliente#{$cliente['id']}: " . mb_substr($texto, 0, 40));
            }

            // toque em BOTÃO de ônibus (quick reply): escolher sentido/parada
            $qrPayload = (string) ($msg['quick_reply']['payload'] ?? '');
            if ($nova && !$soJogo && strpos($qrPayload, 'ONIBUS_') === 0) {
                $ro = onibus_tratar_payload($db, $cliente, $senderId, $qrPayload);
                wlog('ONIBUS botão (' . $qrPayload . ') cliente#' . $cliente['id'] . ' enviou=' . (($ro['enviou'] ?? false) ? '1' : '0'));
                continue; // não cai nas outras respostas
            }

            // botão do ARCADE (termo/quiz/velha/enigma/adivinha)
            if ($nova && strpos($qrPayload, 'JOGOS_') === 0) {
                $jg = jogos_payload_para_texto($qrPayload);
                if ($jg !== '') {
                    $rj = jogos_tratar_direct($db, $cliente, $senderId, $jg);
                    wlog('ARCADE botão (' . $qrPayload . ') cliente#' . $cliente['id'] . ' enviou=' . (($rj['enviou'] ?? false) ? '1' : '0'));
                    continue;
                }
            }

            // botão do JOGO (quick reply): a letra/comando vem no payload
            if ($nova && strpos($qrPayload, 'JOGO_') === 0) {
                $jog = $qrPayload === 'JOGO_DICA' ? 'dica'
                     : ($qrPayload === 'JOGO_PARAR' ? 'parar' : substr($qrPayload, 7));
                $rj = jogo_tratar_direct($db, $cliente, $senderId, $jog);
                wlog('JOGO botão (' . $qrPayload . ') cliente#' . $cliente['id'] . ' enviou=' . (($rj['enviou'] ?? false) ? '1' : '0'));
                continue;
            }

            // ARCADE (termo, quiz, enigma, velha, anagrama, adivinha) — antes da forca
            if ($nova && !$respLink && $texto !== '') {
                $ra = jogos_tratar_direct($db, $cliente, $senderId, $texto);
                if (!empty($ra['tratou'])) {
                    wlog('ARCADE (' . $ra['motivo'] . ') cliente#' . $cliente['id'] . ' enviou=' . (($ra['enviou'] ?? false) ? '1' : '0'));
                    continue;
                }
            }

            // JOGO DA FORCA: "jogo" começa a partida; com partida ativa, as letras
            // vão todas pro jogo. Tem prioridade sobre ônibus/auto-resposta.
            if ($nova && !$respLink && $texto !== '') {
                $rj = jogo_tratar_direct($db, $cliente, $senderId, $texto);
                if (!empty($rj['tratou'])) {
                    wlog('JOGO (' . $rj['motivo'] . ') cliente#' . $cliente['id'] . ' enviou=' . (($rj['enviou'] ?? false) ? '1' : '0'));
                    continue; // não cai nas outras respostas
                }
            }

            // resposta de ÔNIBUS (determinística): se a msg cita uma linha/bairro,
            // responde horários/rota. Tem prioridade sobre a auto-resposta genérica.
            $respOnibus = false;
            if ($nova && !$soJogo && !$respLink && $texto !== '') {
                $ro = onibus_responder_direct($db, $cliente, $senderId, $texto);
                $respOnibus = $ro['enviou'];
                if ($respOnibus) {
                    wlog("ONIBUS respondido ({$ro['linha']}) cliente#{$cliente['id']}: " . mb_substr($texto, 0, 40));
                }
            }

            // auto-resposta normal só se NÃO mandamos link do story NEM horário de ônibus
            if ($nova && !$soJogo && !$respLink && !$respOnibus && $texto !== '') {
                dm_auto_responder($db, $cliente, $conversaId, $senderId, $texto);
            }
        }
    }
} catch (Throwable $e) {
    wlog('EXCECAO: ' . $e->getMessage());
}
exit;
