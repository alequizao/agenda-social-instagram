<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * lib_direct.php
 * Direct (mensagens) do Instagram: receber (via webhook), guardar, responder
 * (manual ou automático: palavra-chave + IA de fallback).
 *
 * Requer token com escopo de mensagens (instagram_manage_messages) e um Webhook
 * cadastrado na Meta apontando para webhook.php (campo "messages").
 */

require_once __DIR__ . '/lib_ia.php'; // openai_post()

/* Alvo do path conforme o tipo de token (IG login usa "me"). */
function dm_alvo(string $token, string $igId): string
{
    return (stripos($token, 'IG') === 0 || $igId === '') ? 'me' : $igId;
}

/* Cliente (conta) dono de um ig_user_id (destinatário da mensagem recebida). */
function dm_cliente_por_ig(PDO $db, string $igUserId): ?array
{
    $st = $db->prepare('SELECT id, nome, ig_user_id, access_token FROM ' . DB_PREFIX . 'clientes WHERE ig_user_id = ? LIMIT 1');
    $st->execute([$igUserId]);
    $c = $st->fetch();
    return $c ?: null;
}

/* Como graph_get(), mas com timeout curto (p/ não travar o carregamento da página). */
function graph_get_timeout(string $path, array $params, int $timeout = 8): array
{
    if (!function_exists('curl_init')) {
        return graph_get($path, $params); // sem curl, usa o caminho padrão
    }
    $url = graph_host($params) . '/' . GRAPH_VERSION . '/' . ltrim($path, '/') . '?' . http_build_query($params);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => max(3, (int) ($timeout / 2)),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno || $body === false) {
        return ['ok' => false, 'erro' => 'timeout/conexao'];
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json) || isset($json['error'])) {
        return ['ok' => false, 'erro' => $json['error']['message'] ?? 'resposta invalida'];
    }
    return ['ok' => true, 'dados' => $json];
}

/* Busca o @username (ou nome) do remetente na Graph API e salva em dm_conversas.nome.
   Retorna o nome encontrado ou null. Best-effort: falha silenciosa se a API não permitir. */
function dm_perfil_buscar(PDO $db, array $cliente, string $remetenteId, int $timeout = 8): ?string
{
    $token = (string) ($cliente['access_token'] ?? '');
    if ($token === '' || $remetenteId === '') {
        return null;
    }
    // marca a tentativa já de cara, para não re-buscar a cada carregamento se a API falhar
    $db->prepare('UPDATE ' . DB_PREFIX . 'dm_conversas SET perfil_em=NOW() WHERE cliente_id=? AND remetente_id=?')
       ->execute([(int) $cliente['id'], $remetenteId]);

    $r = graph_get_timeout($remetenteId, [
        'fields'       => 'name,username,profile_pic,follower_count,is_user_follow_business',
        'access_token' => $token,
    ], $timeout);
    if (!$r['ok']) {
        return null;
    }
    $d     = $r['dados'];
    $nome  = trim((string) ($d['username'] ?? '')) ?: trim((string) ($d['name'] ?? ''));
    $foto  = trim((string) ($d['profile_pic'] ?? ''));
    $seg   = isset($d['follower_count']) ? (int) $d['follower_count'] : null;
    $segue = array_key_exists('is_user_follow_business', $d) ? (int) (bool) $d['is_user_follow_business'] : null;
    if ($nome === '' && $foto === '' && $seg === null) {
        return null;
    }
    try {
        $db->prepare('UPDATE ' . DB_PREFIX . 'dm_conversas
                SET nome = COALESCE(NULLIF(?, ""), nome),
                    foto = COALESCE(NULLIF(?, ""), foto),
                    seguidores = COALESCE(?, seguidores),
                    segue = COALESCE(?, segue),
                    segue_desde = CASE WHEN ? = 1 AND segue_desde IS NULL THEN NOW()
                                       WHEN ? = 0 THEN NULL
                                       ELSE segue_desde END,
                    perfil_em = NOW()
                WHERE cliente_id=? AND remetente_id=?')
           ->execute([mb_substr($nome, 0, 160), $foto, $seg, $segue, $segue, $segue, (int) $cliente['id'], $remetenteId]);
    } catch (Throwable $e) {
        return null; // não derruba a página por falha ao salvar perfil
    }
    return $nome !== '' ? mb_substr($nome, 0, 160) : null;
}

/* HTML do avatar da conversa (foto da Meta com fallback p/ ícone). $size em px. */
function dm_avatar_html(array $cv, int $size = 44): string
{
    $foto = trim((string) ($cv['foto'] ?? ''));
    $s    = (int) $size;
    $img  = '';
    if ($foto !== '') {
        $img = '<img src="' . htmlspecialchars($foto, ENT_QUOTES) . '" alt="" loading="lazy"'
             . ' onerror="this.parentNode.classList.add(\'is-blank\');this.remove();">';
    }
    $cls = 'dm__avatar' . ($foto === '' ? ' is-blank' : '');
    return '<span class="' . $cls . '" style="width:' . $s . 'px;height:' . $s . 'px">'
         . $img . '<i class="fa-solid fa-user"></i></span>';
}

/* HTML de UMA mensagem (usado no template e na atualização AJAX). */
function dm_msg_html(array $m): string
{
    $dir   = $m['direcao'] === 'out' ? 'out' : 'in';
    $midia = trim((string) ($m['midia'] ?? ''));
    $mtipo = (string) ($m['midia_tipo'] ?? '');
    $texto = (string) ($m['texto'] ?? '');
    $u     = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES);

    $h = '<div class="dm__msg dm__msg--' . $dir . '" data-id="' . (int) $m['id'] . '">';
    if ($midia !== '') {
        $h .= '<span class="dm__bubble dm__bubble--midia">';
        if ($mtipo === 'image') {
            $h .= '<a href="' . $u($midia) . '" target="_blank" rel="noopener"><img src="' . $u($midia) . '" alt="imagem" loading="lazy"></a>';
        } elseif ($mtipo === 'video') {
            $h .= '<video src="' . $u($midia) . '" controls preload="metadata"></video>';
        } elseif ($mtipo === 'audio') {
            $h .= '<audio src="' . $u($midia) . '" controls preload="metadata"></audio>';
        } else {
            $h .= '<a href="' . $u($midia) . '" target="_blank" rel="noopener"><i class="fa-solid fa-paperclip"></i> Anexo</a>';
        }
        $h .= '</span>';
    }
    if (trim($texto) !== '') {
        $h .= '<span class="dm__bubble">' . nl2br($u($texto)) . '</span>';
    }
    $extra = $m['origem'] !== 'humano' ? ' · ' . $u(str_replace('auto_', 'auto ', (string) $m['origem'])) : '';
    $h .= '<span class="dm__time">' . $u(date('d/m H:i', strtotime((string) $m['criado_em']))) . $extra . '</span>';
    $h .= '</div>';
    return $h;
}

/* Badge "te segue / não te segue". Retorna '' quando desconhecido (null). */
function dm_segue_badge($cv): string
{
    if (!array_key_exists('segue', (array) $cv) || $cv['segue'] === null) {
        return '';
    }
    if ((int) $cv['segue'] === 1) {
        return '<span class="dm-follow dm-follow--yes"><i class="fa-solid fa-user-check"></i> Te segue</span>';
    }
    return '<span class="dm-follow dm-follow--no"><i class="fa-solid fa-user-xmark"></i> Não te segue</span>';
}

/* Formata contagem de seguidores: 1234 -> "1,2 mil", 1200000 -> "1,2 mi". */
function dm_seguidores_fmt($n): string
{
    $n = (int) $n;
    if ($n >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1, ',', '.'), '0'), ',') . ' mi';
    }
    if ($n >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1, ',', '.'), '0'), ',') . ' mil';
    }
    return number_format($n, 0, ',', '.');
}

/* Rótulo de exibição da conversa: @username quando houver, senão @<id> completo. */
function dm_nome_exibir(array $cv): string
{
    $nome = trim((string) ($cv['nome'] ?? ''));
    if ($nome !== '') {
        // se for um handle (sem espaços) garante o @; nomes com espaço ficam como estão
        return (mb_strpos($nome, ' ') === false && $nome[0] !== '@') ? '@' . $nome : $nome;
    }
    return '@' . (string) ($cv['remetente_id'] ?? '');
}

/* HTML de UM item da lista de conversas (mesmo markup no load e no refresh AJAX). */
function dm_conv_item_html(array $cv, int $cliSel, int $selId): string
{
    $on    = $selId === (int) $cv['id'] ? ' is-on' : '';
    $badge = (int) $cv['nao_lidas'] > 0 ? '<span class="nav-badge">' . (int) $cv['nao_lidas'] . '</span>' : '';
    $seg   = (isset($cv['seguidores']) && $cv['seguidores'] !== null)
        ? '<i class="fa-solid fa-user-group"></i> ' . e(dm_seguidores_fmt($cv['seguidores'])) . ' · ' : '';
    $quando = $cv['ultima_em'] ? e(date('d/m H:i', strtotime((string) $cv['ultima_em']))) : '';
    return '<a class="dm__conv' . $on . '" data-id="' . (int) $cv['id'] . '" data-em="' . e((string) ($cv['ultima_em'] ?? '')) . '"'
        . ' href="direct.php?cli=' . $cliSel . '&c=' . (int) $cv['id'] . '">'
        . dm_avatar_html($cv, 56)
        . '<div class="dm__conv-main"><div class="dm__conv-top"><b>' . e(dm_nome_exibir($cv)) . '</b>' . $badge . '</div>'
        . '<div class="dm__conv-prev">' . e(mb_strimwidth((string) ($cv['ultima_msg'] ?? ''), 0, 48, '…')) . '</div>'
        . '<div class="dm__conv-meta">' . $seg . $quando . ' ' . dm_segue_badge($cv) . '</div></div></a>';
}

/* HTML da lista inteira de conversas de um perfil. */
function dm_lista_html(PDO $db, int $cliSel, int $selId): string
{
    $st = $db->prepare('SELECT cv.*, c.nome AS cliente FROM ' . DB_PREFIX . 'dm_conversas cv
        JOIN ' . DB_PREFIX . 'clientes c ON c.id = cv.cliente_id
        WHERE cv.cliente_id = ? ORDER BY cv.ultima_em DESC LIMIT 100');
    $st->execute([$cliSel]);
    $rows = $st->fetchAll();
    if (!$rows) {
        return '<div class="empty"><div class="empty__mark"><i class="fa-regular fa-comments"></i></div>'
            . '<h2>Sem conversas</h2><p>Quando alguém mandar Direct, aparece aqui.</p></div>';
    }
    $out = '';
    foreach ($rows as $cv) {
        $out .= dm_conv_item_html($cv, $cliSel, $selId);
    }
    return $out;
}

/* Upsert da conversa; retorna o id. */
function dm_conversa_obter(PDO $db, int $clienteId, string $remetenteId, ?string $nome = null): int
{
    $st = $db->prepare('SELECT id FROM ' . DB_PREFIX . 'dm_conversas WHERE cliente_id=? AND remetente_id=? LIMIT 1');
    $st->execute([$clienteId, $remetenteId]);
    $id = (int) ($st->fetchColumn() ?: 0);
    if ($id > 0) {
        if ($nome) {
            $db->prepare('UPDATE ' . DB_PREFIX . 'dm_conversas SET nome=? WHERE id=?')->execute([mb_substr($nome, 0, 160), $id]);
        }
        return $id;
    }
    $db->prepare('INSERT INTO ' . DB_PREFIX . 'dm_conversas (cliente_id, remetente_id, nome) VALUES (?,?,?)')
       ->execute([$clienteId, $remetenteId, $nome ? mb_substr($nome, 0, 160) : null]);
    return (int) $db->lastInsertId();
}

/* Registra uma mensagem (anti-duplicado por mid). Atualiza a conversa. Retorna true se nova.
   $midia/$midiaTipo opcionais p/ anexos (image|video|audio). */
function dm_registrar(PDO $db, int $conversaId, string $direcao, string $texto, ?string $mid, string $origem = 'humano', ?string $midia = null, ?string $midiaTipo = null): bool
{
    $ins = $db->prepare('INSERT IGNORE INTO ' . DB_PREFIX . 'dm_mensagens
        (conversa_id, direcao, texto, midia, midia_tipo, mid, origem) VALUES (?,?,?,?,?,?,?)');
    $ins->execute([$conversaId, $direcao, $texto, $midia, $midiaTipo, $mid, $origem]);
    if ($ins->rowCount() === 0) {
        return false; // duplicada (mid já existia)
    }
    $prev = $texto !== '' ? $texto : (['image' => '📷 Foto', 'video' => '🎬 Vídeo', 'audio' => '🎵 Áudio'][(string) $midiaTipo] ?? '📎 Anexo');
    if ($direcao === 'in') {
        $db->prepare('UPDATE ' . DB_PREFIX . 'dm_conversas
            SET ultima_msg=?, ultima_em=NOW(), ultima_recebida_em=NOW(), nao_lidas=nao_lidas+1 WHERE id=?')
           ->execute([mb_substr($prev, 0, 500), $conversaId]);
    } else {
        $db->prepare('UPDATE ' . DB_PREFIX . 'dm_conversas SET ultima_msg=?, ultima_em=NOW() WHERE id=?')
           ->execute([mb_substr($prev, 0, 500), $conversaId]);
    }
    return true;
}

/* Envia uma mensagem pelo Direct e registra como "out". */
function dm_enviar(PDO $db, array $cliente, string $remetenteId, string $texto, string $origem = 'humano'): array
{
    $token = (string) ($cliente['access_token'] ?? '');
    $igId  = (string) ($cliente['ig_user_id'] ?? '');
    if ($token === '' || $texto === '') {
        return ['ok' => false, 'erro' => 'Sem token ou texto.'];
    }
    $r = graph_post(dm_alvo($token, $igId) . '/messages', [
        'access_token' => $token,
        'recipient'    => json_encode(['id' => $remetenteId]),
        'message'      => json_encode(['text' => $texto]),
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'erro' => $r['erro']];
    }
    $conversaId = dm_conversa_obter($db, (int) $cliente['id'], $remetenteId);
    $mid = (string) ($r['dados']['message_id'] ?? ('out-' . bin2hex(random_bytes(8))));
    dm_registrar($db, $conversaId, 'out', $texto, $mid, $origem);
    return ['ok' => true, 'mid' => $mid];
}

/* Envia texto COM botões de resposta rápida (quick replies) e registra como "out".
   $opcoes: lista de ['content_type'=>'text','title'=>..., 'payload'=>...] (máx 13). */
function dm_enviar_opcoes(PDO $db, array $cliente, string $remetenteId, string $texto, array $opcoes, string $origem = 'humano'): array
{
    $token = (string) ($cliente['access_token'] ?? '');
    $igId  = (string) ($cliente['ig_user_id'] ?? '');
    if ($token === '' || $texto === '') {
        return ['ok' => false, 'erro' => 'Sem token ou texto.'];
    }
    $message = ['text' => $texto];
    $opcoes = array_slice(array_values($opcoes), 0, 13);
    if ($opcoes) {
        $message['quick_replies'] = $opcoes;
    }
    $r = graph_post(dm_alvo($token, $igId) . '/messages', [
        'access_token' => $token,
        'recipient'    => json_encode(['id' => $remetenteId]),
        'message'      => json_encode($message),
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'erro' => $r['erro']];
    }
    $conversaId = dm_conversa_obter($db, (int) $cliente['id'], $remetenteId);
    $mid = (string) ($r['dados']['message_id'] ?? ('out-' . bin2hex(random_bytes(8))));
    dm_registrar($db, $conversaId, 'out', $texto, $mid, $origem);
    return ['ok' => true, 'mid' => $mid];
}

/* Indicador de ação no Direct: 'mark_seen' (visto) | 'typing_on' | 'typing_off'.
   typing_on é limpo automaticamente quando a mensagem é enviada. Best-effort. */
function dm_acao(array $cliente, string $remetenteId, string $action): void
{
    $token = (string) ($cliente['access_token'] ?? '');
    $igId  = (string) ($cliente['ig_user_id'] ?? '');
    if ($token === '' || $remetenteId === '') {
        return;
    }
    graph_post(dm_alvo($token, $igId) . '/messages', [
        'access_token'  => $token,
        'recipient'     => json_encode(['id' => $remetenteId]),
        'sender_action' => $action,
    ]);
}

/* Valida e salva um arquivo enviado em uploads/direct. Retorna [true,[urlPublica,tipo]] ou [false,erroMsg]. */
function dm_processar_upload(array $file, ?string $tipoForcado = null): array
{
    $max = 25 * 1024 * 1024; // 25 MB (limite da Meta p/ vídeo/áudio)
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $max) {
        return [false, 'Arquivo vazio ou maior que 25 MB.'];
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $mapa = [
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
        'mp4' => 'video', 'mov' => 'video', 'webm' => 'video',
        'mp3' => 'audio', 'm4a' => 'audio', 'aac' => 'audio', 'wav' => 'audio', 'ogg' => 'audio',
    ];
    if ($ext === '') {
        $ext = 'webm'; // gravação do microfone pode vir sem extensão
    }
    if (!isset($mapa[$ext])) {
        return [false, 'Tipo não suportado (use jpg, png, gif, mp4, mov, mp3, m4a, aac, wav).'];
    }
    // gravação de microfone: força tipo "audio" mesmo em container webm/ogg
    $tipo = in_array($tipoForcado, ['image', 'video', 'audio'], true) ? $tipoForcado : $mapa[$ext];
    $dir  = __DIR__ . '/uploads/direct';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $nome = date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $dest = $dir . '/' . $nome;
    if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
        return [false, 'Falha ao salvar o arquivo no servidor.'];
    }
    @chmod($dest, 0644);
    $url = rtrim(BASE_URL, '/') . '/uploads/direct/' . $nome;
    return [true, [$url, $tipo]];
}

/* Envia uma MÍDIA (image|video|audio) pelo Direct e registra como "out".
   $urlPublica precisa ser acessível pela Meta (https). */
function dm_enviar_midia(PDO $db, array $cliente, string $remetenteId, string $urlPublica, string $tipo, string $origem = 'humano'): array
{
    $token = (string) ($cliente['access_token'] ?? '');
    $igId  = (string) ($cliente['ig_user_id'] ?? '');
    $tipo  = in_array($tipo, ['image', 'video', 'audio'], true) ? $tipo : 'image';
    if ($token === '' || $urlPublica === '') {
        return ['ok' => false, 'erro' => 'Sem token ou arquivo.'];
    }
    $r = graph_post(dm_alvo($token, $igId) . '/messages', [
        'access_token' => $token,
        'recipient'    => json_encode(['id' => $remetenteId]),
        'message'      => json_encode(['attachment' => ['type' => $tipo, 'payload' => ['url' => $urlPublica]]]),
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'erro' => $r['erro']];
    }
    $conversaId = dm_conversa_obter($db, (int) $cliente['id'], $remetenteId);
    $mid = (string) ($r['dados']['message_id'] ?? ('out-' . bin2hex(random_bytes(8))));
    try {
        $ins = $db->prepare('INSERT IGNORE INTO ' . DB_PREFIX . 'dm_mensagens
            (conversa_id, direcao, texto, midia, midia_tipo, mid, origem) VALUES (?,?,?,?,?,?,?)');
        $ins->execute([$conversaId, 'out', '', $urlPublica, $tipo, $mid, $origem]);
        $rotulo = ['image' => '📷 Foto', 'video' => '🎬 Vídeo', 'audio' => '🎵 Áudio'][$tipo] ?? 'Mídia';
        $db->prepare('UPDATE ' . DB_PREFIX . 'dm_conversas SET ultima_msg=?, ultima_em=NOW() WHERE id=?')
           ->execute([$rotulo, $conversaId]);
    } catch (Throwable $e) {
        // mensagem foi enviada na Meta; só falhou registrar localmente
    }
    return ['ok' => true, 'mid' => $mid];
}

/* Resposta por PALAVRA-CHAVE (regras dm_regras DO CLIENTE + globais). Retorna texto ou null. */
function dm_resposta_palavra(PDO $db, string $texto, int $clienteId): ?string
{
    $t = ' ' . mb_strtolower(trim($texto), 'UTF-8') . ' ';
    $st = $db->prepare('SELECT gatilho, resposta FROM ' . DB_PREFIX . 'dm_regras
        WHERE ativo=1 AND (cliente_id=? OR cliente_id IS NULL) ORDER BY posicao, id');
    $st->execute([$clienteId]);
    foreach ($st->fetchAll() as $r) {
        foreach (preg_split('/\s*,\s*/', mb_strtolower((string) $r['gatilho'], 'UTF-8')) ?: [] as $g) {
            $g = trim($g);
            if ($g !== '' && mb_strpos($t, $g) !== false) {
                return (string) $r['resposta'];
            }
        }
    }
    return null;
}

/* Resposta por IA (OpenAI) no tom do perfil. Retorna texto ou null. */
function dm_resposta_ia(PDO $db, int $clienteId, array $historico, string $texto): ?string
{
    if (OPENAI_API_KEY === '') {
        return null;
    }
    $modelo = (string) pcfg_get($clienteId, 'dm_ia_modelo', 'gpt-4o-mini');
    $sys = (string) pcfg_get($clienteId, 'dm_ia_prompt', '')
        ?: 'Você é o atendente do perfil @tevinobuzao, que mostra notícias de Maceió e Alagoas. '
         . 'Responda mensagens do Direct de forma curta, simpática e objetiva, em português do Brasil, '
         . 'com no máximo 2 frases e 1 emoji. Se não souber, peça mais detalhes educadamente. '
         . 'Nunca invente informações nem prometa nada em nome de terceiros.';

    $msgs = [['role' => 'system', 'content' => $sys]];
    foreach ($historico as $h) { // histórico recente p/ contexto
        $msgs[] = ['role' => $h['direcao'] === 'in' ? 'user' : 'assistant', 'content' => (string) $h['texto']];
    }
    $msgs[] = ['role' => 'user', 'content' => $texto];

    $r = openai_post('chat/completions', [
        'model' => $modelo, 'messages' => $msgs, 'temperature' => 0.6, 'max_tokens' => 200,
    ]);
    if (!$r['ok']) {
        return null;
    }
    $out = trim((string) ($r['dados']['choices'][0]['message']['content'] ?? ''));
    if ($out === '') {
        return null;
    }
    $tin = (int) ($r['dados']['usage']['prompt_tokens'] ?? 0);
    $tout = (int) ($r['dados']['usage']['completion_tokens'] ?? 0);
    $p = ia_precos_texto()[$modelo] ?? ['in' => 0.0, 'out' => 0.0];
    ia_registrar_custo($db, 'texto', $modelo, 'direct', $tin, $tout, ($tin / 1000) * $p['in'] + ($tout / 1000) * $p['out']);
    return $out;
}

/* Modo de auto-resposta do cliente:
   'off' (desligado) | 'palavra' (só palavra-chave) | 'ia' (só IA) | 'palavra_ia' (palavra-chave + IA).
   Deriva dos toggles antigos quando 'dm_modo' ainda não foi definido. */
function dm_modo_atual(int $cid): string
{
    $m = (string) pcfg_get($cid, 'dm_modo', '');
    if (in_array($m, ['off', 'palavra', 'ia', 'palavra_ia'], true)) {
        return $m;
    }
    $auto = (string) pcfg_get($cid, 'dm_auto_ativo', '0') === '1';
    $ia   = (string) pcfg_get($cid, 'dm_ia_fallback', '0') === '1';
    if (!$auto && !$ia) {
        return 'off';
    }
    if ($auto && $ia) {
        return 'palavra_ia';
    }
    return $auto ? 'palavra' : 'ia';
}

/* Sincroniza conversas do Direct via Graph API (recebidas E iniciadas pelo @ conectado).
   Importa participantes + mensagens recentes (com o horário real). Retorna ['ok','n','erro']. */
function dm_sincronizar_conversas(PDO $db, array $cliente): array
{
    $token = (string) ($cliente['access_token'] ?? '');
    $igId  = (string) ($cliente['ig_user_id'] ?? '');
    $cid   = (int) $cliente['id'];
    if ($token === '') {
        return ['ok' => false, 'n' => 0, 'erro' => 'Sem token.'];
    }
    $r = graph_get_timeout('me/conversations', [
        'platform'     => 'instagram',
        'fields'       => 'participants,messages.limit(10){from,message,created_time}',
        'limit'        => 15,
        'access_token' => $token,
    ], 20);
    if (!$r['ok']) {
        return ['ok' => false, 'n' => 0, 'erro' => $r['erro'] ?? 'Falha na Meta.'];
    }
    $n = 0;
    $insMsg = $db->prepare('INSERT IGNORE INTO ' . DB_PREFIX . 'dm_mensagens
        (conversa_id, direcao, texto, mid, origem, criado_em) VALUES (?,?,?,?,?,?)');
    foreach (($r['dados']['data'] ?? []) as $conv) {
        $outro = null;
        foreach (($conv['participants']['data'] ?? []) as $p) {
            if ((string) ($p['id'] ?? '') !== $igId) {
                $outro = $p;
                break;
            }
        }
        if (!$outro || empty($outro['id'])) {
            continue;
        }
        $remId = (string) $outro['id'];
        $nome  = trim((string) ($outro['username'] ?? '')) ?: trim((string) ($outro['name'] ?? ''));
        $convId = dm_conversa_obter($db, $cid, $remId, $nome ?: null);

        // busca foto, seguidores e "te segue / não te segue" (a API de conversas não traz isso)
        $jaTem = (int) $db->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'dm_conversas
            WHERE id=' . (int) $convId . ' AND segue IS NOT NULL AND foto IS NOT NULL')->fetchColumn();
        if ($jaTem === 0) {
            dm_perfil_buscar($db, $cliente, $remId, 7);
        }

        $ultTxt = '';
        $ultEm  = null;
        foreach (($conv['messages']['data'] ?? []) as $m) {
            $txt = trim((string) ($m['message'] ?? ''));
            if ($txt === '') {
                continue; // sync só de texto (mídia chega via webhook)
            }
            $dir = ((string) ($m['from']['id'] ?? '') === $igId) ? 'out' : 'in';
            $em  = isset($m['created_time']) ? date('Y-m-d H:i:s', strtotime((string) $m['created_time'])) : date('Y-m-d H:i:s');
            $mid = (string) ($m['id'] ?? '') ?: ('sync-' . md5($remId . $txt . $em));
            $insMsg->execute([$convId, $dir, $txt, $mid, 'humano', $em]);
            if ($ultEm === null) { // messages vêm do mais novo p/ o mais antigo
                $ultTxt = $txt;
                $ultEm  = $em;
            }
        }
        if ($ultEm !== null) {
            $db->prepare('UPDATE ' . DB_PREFIX . 'dm_conversas SET ultima_msg=?, ultima_em=GREATEST(COALESCE(ultima_em,0), ?) WHERE id=?')
               ->execute([mb_substr($ultTxt, 0, 500), $ultEm, $convId]);
        }
        $n++;
    }
    return ['ok' => true, 'n' => $n, 'erro' => ''];
}

/* Orquestra a auto-resposta de UMA mensagem recebida, conforme o modo do cliente.
   Só responde se a automação estiver LIGADA (modo != off) e a conversa permitir. */
function dm_auto_responder(PDO $db, array $cliente, int $conversaId, string $remetenteId, string $texto): void
{
    $cid  = (int) $cliente['id'];
    $modo = dm_modo_atual($cid);
    if ($modo === 'off') {
        return; // automação desligada -> nunca responde sozinho
    }
    // conversa pode ter auto desligado individualmente
    $auto = (int) $db->query('SELECT auto_ativo FROM ' . DB_PREFIX . 'dm_conversas WHERE id=' . (int) $conversaId)->fetchColumn();
    if ($auto !== 1) {
        return;
    }

    $resp   = null;
    $origem = '';
    if ($modo === 'palavra' || $modo === 'palavra_ia') {
        $resp   = dm_resposta_palavra($db, $texto, $cid);
        $origem = 'auto_palavra';
    }
    if ($resp === null && ($modo === 'ia' || $modo === 'palavra_ia')) {
        $hist = $db->query('SELECT direcao, texto FROM ' . DB_PREFIX . 'dm_mensagens
            WHERE conversa_id=' . (int) $conversaId . ' ORDER BY id DESC LIMIT 6')->fetchAll();
        $resp   = dm_resposta_ia($db, $cid, array_reverse($hist), $texto);
        $origem = 'auto_ia';
    }
    if ($resp === null || $resp === '') {
        return;
    }

    // --- anti-spam: não repetir a mesma resposta nem responder em rajada ---
    $ult = $db->query('SELECT texto, origem, criado_em FROM ' . DB_PREFIX . 'dm_mensagens
        WHERE conversa_id=' . (int) $conversaId . " AND direcao='out' ORDER BY id DESC LIMIT 1")->fetch();
    if ($ult) {
        // mesma resposta automática enviada por último -> não reenvia
        if (trim((string) $ult['texto']) === trim($resp) && strpos((string) $ult['origem'], 'auto') === 0) {
            return;
        }
        // rajada: já respondeu automaticamente há menos de 45s -> espera
        if (strpos((string) $ult['origem'], 'auto') === 0
            && (time() - strtotime((string) $ult['criado_em'])) < 45) {
            return;
        }
    }

    // simula leitura + "digitando…" antes de responder (tempo proporcional ao texto, 2–6s)
    dm_acao($cliente, $remetenteId, 'mark_seen');
    dm_acao($cliente, $remetenteId, 'typing_on');
    $espera = min(6.0, max(2.0, mb_strlen($resp) / 12));
    usleep((int) ($espera * 1000000));

    dm_enviar($db, $cliente, $remetenteId, $resp, $origem);
}

/* Responde a quem RESPONDEU um STORY com o LINK da matéria de origem.
   100% DETERMINÍSTICO (zero IA): casa o ig_media_id do story respondido com a
   publicação que o gerou e pega o fonte_url já gravado. NUNCA inventa link:
   se não houver match exato com URL http(s) válida, simplesmente NÃO envia.
   Liga/desliga por perfil em dm_story_link_ativo (0/1).
   Retorna ['enviou'=>bool, 'motivo'=>string, 'url'=>string] (motivo p/ log/observabilidade). */
function dm_responder_link_story(PDO $db, array $cliente, string $remetenteId, string $storyMediaId): array
{
    $cid = (int) $cliente['id'];
    if ($storyMediaId === '') {
        return ['enviou' => false, 'motivo' => 'sem story_id', 'url' => ''];
    }
    if ((string) pcfg_get($cid, 'dm_story_link_ativo', '0') !== '1') {
        return ['enviou' => false, 'motivo' => 'automação desligada (dm_story_link_ativo=0)', 'url' => ''];
    }

    // match EXATO: story respondido -> publicação que o originou
    $st = $db->prepare('SELECT fonte_url FROM ' . DB_PREFIX . 'publicacoes
        WHERE cliente_id=? AND tipo="story" AND status="publicado" AND ig_media_id=? LIMIT 1');
    $st->execute([$cid, $storyMediaId]);
    $url = trim((string) ($st->fetchColumn() ?: ''));
    if ($url === '') {
        return ['enviou' => false, 'motivo' => "story {$storyMediaId} não encontrado nas publicações deste perfil", 'url' => ''];
    }
    if (!preg_match('~^https?://~i', $url)) {
        return ['enviou' => false, 'motivo' => 'publicação sem URL válida (não arrisca link errado)', 'url' => $url];
    }

    $conversaId = dm_conversa_obter($db, $cid, $remetenteId);

    // anti-duplicado: não reenvia o MESMO link na janela configurável (padrão 30 min)
    $dedupMin = max(0, (int) pcfg_get($cid, 'dm_story_link_dedup_min', '30'));
    if ($dedupMin > 0) {
        $ja = $db->prepare('SELECT 1 FROM ' . DB_PREFIX . 'dm_mensagens
            WHERE conversa_id=? AND direcao="out" AND origem="auto_story"
              AND texto LIKE ? AND criado_em >= (NOW() - INTERVAL ? MINUTE) LIMIT 1');
        $ja->execute([$conversaId, '%' . $url . '%', $dedupMin]);
        if ($ja->fetchColumn()) {
            return ['enviou' => false, 'motivo' => "mesmo link já enviado nos últimos {$dedupMin} min", 'url' => $url];
        }
    }

    // Mensagem amigável SEM o link + o LINK PURO numa 2ª mensagem.
    // Uma URL sozinha numa mensagem é renderizada como link CLICÁVEL pelo Instagram
    // (link colado a texto/emoji muitas vezes vira texto simples, não clicável).
    $modelo = trim((string) pcfg_get($cid, 'dm_story_link_msg', ''));
    $saudacao = $modelo !== ''
        ? trim((string) preg_replace('/\s*\{link\}\s*/u', '', $modelo)) // remove o {link} do texto
        : 'Aqui está a matéria completa 👇';

    dm_acao($cliente, $remetenteId, 'mark_seen');
    if ($saudacao !== '') {
        dm_enviar($db, $cliente, $remetenteId, $saudacao, 'auto_story');
    }
    // o link sozinho = clicável
    $r = dm_enviar($db, $cliente, $remetenteId, $url, 'auto_story');
    if (empty($r['ok'])) {
        return ['enviou' => false, 'motivo' => 'falha no envio: ' . ($r['erro'] ?? '?'), 'url' => $url];
    }
    return ['enviou' => true, 'motivo' => 'ok', 'url' => $url];
}
