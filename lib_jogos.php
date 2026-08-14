<?php

declare(strict_types=1);

/**
 * lib_jogos.php — ARCADE do Direct: Termo, Quiz, Enigma, Jogo da Velha,
 * Anagrama e Adivinha o número. (A Forca fica em lib_jogo.php, com tabela própria.)
 *
 * Estado de cada partida: tabela jogo_sessoes (JSON). Resultado: jogo_resultados.
 * Ponto de entrada: jogos_tratar_direct() — chamado pelo webhook ANTES da forca.
 *
 * Ligar por perfil: dm_jogo_ativo=1 (mesmo interruptor da forca).
 * Cada jogo pode ser desligado sozinho: dm_jogo_<termo|quiz|enigma|velha|anagrama|adivinha> = 0/1.
 */

require_once __DIR__ . '/lib_jogo.php'; // jogo_norm, dicionários, jogo_ativo, arte

/* Catálogo: chave => rótulo, gatilhos e descrição curta. */
function jogos_catalogo(): array
{
    return [
        'forca'    => ['rotulo' => '🎯 Forca',        'gatilhos' => ['forca', 'jogo da forca'],             'desc' => 'adivinhe a palavra letra por letra'],
        'termo'    => ['rotulo' => '🟩 Termo',        'gatilhos' => ['termo', 'wordle'],                    'desc' => '5 letras, 6 tentativas'],
        'quiz'     => ['rotulo' => '❓ Quiz',          'gatilhos' => ['quiz', 'perguntas'],                  'desc' => '5 perguntas de múltipla escolha'],
        'enigma'   => ['rotulo' => '🕵️ Enigma',       'gatilhos' => ['enigma', 'quem sou eu', 'charada'],   'desc' => '3 pistas, quem sou eu?'],
        'velha'    => ['rotulo' => '⭕ Jogo da velha', 'gatilhos' => ['velha', 'jogo da velha'],             'desc' => 'você contra o bot'],
        'anagrama' => ['rotulo' => '🔀 Anagrama',     'gatilhos' => ['anagrama', 'embaralhado'],            'desc' => 'desembaralhe a palavra'],
        'adivinha' => ['rotulo' => '🔢 Adivinha',     'gatilhos' => ['adivinha', 'numero', 'número'],       'desc' => 'achei um número de 1 a 100'],
    ];
}

/* Jogo ligado neste perfil? (a forca usa dm_jogo_ativo; cada jogo tem o seu toggle) */
function jogos_habilitado(int $clienteId, string $jogo): bool
{
    if (!jogo_ativo($clienteId)) {
        return false;
    }
    return (string) pcfg_get($clienteId, 'dm_jogo_' . $jogo, '1') === '1';
}

/* ---------------------------------------------------------------- sessão */

function jogos_sessao(PDO $db, int $cid, string $rem): ?array
{
    $st = $db->prepare('SELECT * FROM ' . DB_PREFIX . 'jogo_sessoes
        WHERE cliente_id=? AND remetente_id=? AND status="ativa" ORDER BY id DESC LIMIT 1');
    $st->execute([$cid, $rem]);
    $s = $st->fetch();
    if (!$s) {
        return null;
    }
    // expira sessão parada (mesmo timeout da forca)
    $tmo = max(0, (int) pcfg_get($cid, 'dm_jogo_timeout', '60'));
    if ($tmo > 0 && strtotime((string) $s['atualizado_em']) < time() - ($tmo * 60)) {
        $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_sessoes SET status="expirada" WHERE id=?')->execute([(int) $s['id']]);
        return null;
    }
    $s['dados'] = json_decode((string) $s['estado'], true) ?: [];
    return $s;
}

function jogos_sessao_criar(PDO $db, int $cid, string $rem, string $jogo, array $dados): int
{
    // só uma sessão ativa por pessoa
    $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_sessoes SET status="desistiu"
        WHERE cliente_id=? AND remetente_id=? AND status="ativa"')->execute([$cid, $rem]);
    $ins = $db->prepare('INSERT INTO ' . DB_PREFIX . 'jogo_sessoes (cliente_id, remetente_id, jogo, estado) VALUES (?,?,?,?)');
    $ins->execute([$cid, $rem, $jogo, json_encode($dados, JSON_UNESCAPED_UNICODE)]);
    return (int) $db->lastInsertId();
}

function jogos_sessao_salvar(PDO $db, int $id, array $dados): void
{
    $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_sessoes SET estado=? WHERE id=?')
       ->execute([json_encode($dados, JSON_UNESCAPED_UNICODE), $id]);
}

function jogos_sessao_fechar(PDO $db, int $id, string $status): void
{
    $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_sessoes SET status=? WHERE id=?')->execute([$status, $id]);
}

function jogos_registrar(PDO $db, int $cid, string $rem, string $jogo, string $status, int $pontos = 0, string $detalhe = ''): void
{
    $db->prepare('INSERT INTO ' . DB_PREFIX . 'jogo_resultados
        (cliente_id, remetente_id, jogo, status, pontos, detalhe) VALUES (?,?,?,?,?,?)')
       ->execute([$cid, $rem, $jogo, $status, $pontos, mb_substr($detalhe, 0, 190)]);
}

/* Placar da pessoa em TODOS os jogos (forca inclusa). */
function jogos_placar(PDO $db, int $cid, string $rem): array
{
    $out = [];
    $st = $db->prepare('SELECT jogo, SUM(status="ganhou") g, COUNT(*) t FROM ' . DB_PREFIX . 'jogo_resultados
        WHERE cliente_id=? AND remetente_id=? GROUP BY jogo');
    $st->execute([$cid, $rem]);
    foreach ($st->fetchAll() as $r) {
        $out[(string) $r['jogo']] = ['ganhou' => (int) $r['g'], 'total' => (int) $r['t']];
    }
    $f = jogo_placar($db, $cid, $rem);
    if ($f['ganhou'] + $f['perdeu'] > 0) {
        $out['forca'] = ['ganhou' => (int) $f['ganhou'], 'total' => (int) ($f['ganhou'] + $f['perdeu'])];
    }
    return $out;
}

/* Ranking geral do arcade (vitórias em todos os jogos). */
function jogos_ranking(PDO $db, int $cid, int $dias = 7, int $limite = 10): array
{
    $sql = 'SELECT remetente_id, SUM(v) vitorias, SUM(t) partidas FROM (
                SELECT remetente_id, (status="ganhou") v, 1 t FROM ' . DB_PREFIX . 'jogo_resultados
                 WHERE cliente_id=:c AND criado_em >= (NOW() - INTERVAL :d DAY)
                UNION ALL
                SELECT remetente_id, (status="ganhou") v, 1 t FROM ' . DB_PREFIX . 'jogo_partidas
                 WHERE cliente_id=:c2 AND criado_em >= (NOW() - INTERVAL :d2 DAY)
                   AND status IN ("ganhou","perdeu")
            ) x GROUP BY remetente_id HAVING vitorias > 0
            ORDER BY vitorias DESC, partidas ASC LIMIT ' . (int) $limite;
    $st = $db->prepare($sql);
    $st->execute([':c' => $cid, ':d' => max(1, $dias), ':c2' => $cid, ':d2' => max(1, $dias)]);
    $linhas = $st->fetchAll();
    foreach ($linhas as $i => $l) {
        $n = $db->prepare('SELECT nome FROM ' . DB_PREFIX . 'dm_conversas WHERE cliente_id=? AND remetente_id=?');
        $n->execute([$cid, (string) $l['remetente_id']]);
        $linhas[$i]['nome'] = trim((string) $n->fetchColumn()) ?: 'jogador';
    }
    return $linhas;
}

/* ---------------------------------------------------------------- envio */

function jogos_enviar(PDO $db, array $cliente, string $rem, string $texto, array $botoes = []): bool
{
    if ($botoes && (string) pcfg_get((int) $cliente['id'], 'dm_jogo_botoes', '1') === '1') {
        $r = dm_enviar_opcoes($db, $cliente, $rem, $texto, $botoes, 'auto_jogo');
        return (bool) ($r['ok'] ?? false);
    }
    $r = dm_enviar($db, $cliente, $rem, $texto, 'auto_jogo');
    return (bool) ($r['ok'] ?? false);
}

function jogos_botao(string $titulo, string $payload): array
{
    return ['content_type' => 'text', 'title' => mb_substr($titulo, 0, 20), 'payload' => $payload];
}

/* Menu do arcade. */
function jogos_menu(PDO $db, array $cliente, string $rem): bool
{
    $cid = (int) $cliente['id'];
    $txt = "🕹️ ARCADE DO DIRECT\n\nEscolha um jogo (ou escreva o nome):\n";
    $bts = [];
    foreach (jogos_catalogo() as $k => $j) {
        if ($k !== 'forca' && !jogos_habilitado($cid, $k)) {
            continue;
        }
        $txt .= "\n" . $j['rotulo'] . ' — ' . $j['desc'] . ' (escreva "' . $j['gatilhos'][0] . '")';
        $bts[] = jogos_botao($j['rotulo'], 'JOGOS_' . mb_strtoupper($k, 'UTF-8'));
    }
    $txt .= "\n\n🏆 \"ranking\" mostra o pódio · 📊 \"placar\" mostra o seu";
    return jogos_enviar($db, $cliente, $rem, $txt, array_slice($bts, 0, 13));
}

/* ---------------------------------------------------------------- TERMO */

function termo_palavra_dia(PDO $db, int $cid): string
{
    $st = $db->prepare('SELECT palavra FROM ' . DB_PREFIX . 'termo_dia WHERE cliente_id=? AND dia=CURDATE()');
    $st->execute([$cid]);
    $p = (string) $st->fetchColumn();
    if ($p !== '') {
        return $p;
    }
    $p = termo_sortear();
    if ($p === '') {
        return '';
    }
    $db->prepare('INSERT IGNORE INTO ' . DB_PREFIX . 'termo_dia (cliente_id, dia, palavra) VALUES (?, CURDATE(), ?)')
       ->execute([$cid, $p]);
    return $p;
}

function termo_sortear(): string
{
    $arq = __DIR__ . '/dicionarios/termo5.idx';
    if (!is_file($arq)) {
        return '';
    }
    $tam = (int) filesize($arq);
    $fh = @fopen($arq, 'rb');
    if (!$fh) {
        return '';
    }
    $p = '';
    for ($i = 0; $i < 6 && $p === ''; $i++) {
        fseek($fh, random_int(0, max(0, $tam - 2)));
        fgets($fh);
        $l = trim((string) (fgets($fh) ?: ''));
        if (mb_strlen($l, 'UTF-8') === 5) {
            $p = $l;
        }
    }
    fclose($fh);
    return $p;
}

/* Retorno visual do palpite: 🟩 certo no lugar, 🟨 existe fora do lugar, ⬛ não tem. */
function termo_marcar(string $alvo, string $tent): string
{
    $a = preg_split('//u', $alvo, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $t = preg_split('//u', $tent, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $res = array_fill(0, count($t), '⬛');
    $sobra = [];
    foreach ($a as $i => $c) {
        if (isset($t[$i]) && $t[$i] === $c) {
            $res[$i] = '🟩';
        } else {
            $sobra[$c] = ($sobra[$c] ?? 0) + 1;
        }
    }
    foreach ($t as $i => $c) {
        if ($res[$i] === '🟩') {
            continue;
        }
        if (!empty($sobra[$c])) {
            $res[$i] = '🟨';
            $sobra[$c]--;
        }
    }
    return implode('', $res);
}

function termo_painel(array $d, bool $fim = false): string
{
    $txt = "🟩 TERMO — 5 letras, 6 tentativas\n";
    foreach ($d['tentativas'] as $t) {
        $txt .= "\n" . $t['marcas'] . '  ' . implode(' ', preg_split('//u', $t['palavra'], -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
    if (!$fim) {
        $rest = 6 - count($d['tentativas']);
        $txt .= "\n\n✍️ Mande uma palavra de 5 letras (faltam " . $rest . ').';
    }
    return $txt;
}

function termo_iniciar(PDO $db, array $cliente, string $rem): array
{
    $cid = (int) $cliente['id'];
    $doDia = (string) pcfg_get($cid, 'dm_jogo_palavra_dia', '1') === '1';
    $palavra = '';
    if ($doDia) {
        $jaFez = $db->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'jogo_resultados
            WHERE cliente_id=? AND remetente_id=? AND jogo="termo" AND criado_em >= CURDATE() AND detalhe LIKE "dia:%"');
        $jaFez->execute([$cid, $rem]);
        if ((int) $jaFez->fetchColumn() === 0) {
            $palavra = termo_palavra_dia($db, $cid);
        }
    }
    $ehDia = $palavra !== '';
    if ($palavra === '') {
        $palavra = termo_sortear();
    }
    if ($palavra === '') {
        return ['enviou' => false, 'motivo' => 'sem dicionário do termo', 'tratou' => true];
    }
    $d = ['palavra' => $palavra, 'tentativas' => [], 'do_dia' => $ehDia ? 1 : 0];
    $d['sessao'] = jogos_sessao_criar($db, $cid, $rem, 'termo', $d);
    jogos_sessao_salvar($db, (int) $d['sessao'], $d);
    $txt = ($ehDia ? "🗓️ TERMO DO DIA — todo mundo joga a mesma palavra!\n\n" : '')
         . termo_painel($d)
         . "\n\n🟩 letra certa no lugar certo · 🟨 existe fora do lugar · ⬛ não tem"
         . "\n🏳️ \"parar\" encerra";
    return ['enviou' => jogos_enviar($db, $cliente, $rem, $txt), 'motivo' => 'termo iniciado', 'tratou' => true];
}

function termo_jogada(PDO $db, array $cliente, string $rem, array $s, string $q): array
{
    $cid = (int) $cliente['id'];
    $d   = $s['dados'];
    if (!preg_match('/^[A-Z]{5}$/u', $q)) {
        return ['enviou' => jogos_enviar($db, $cliente, $rem, termo_painel($d) . "\n\n🤔 Precisa ser uma palavra de 5 letras."), 'motivo' => 'termo tamanho', 'tratou' => true];
    }
    if (!jogo_palavra_existe($q)) {
        return ['enviou' => jogos_enviar($db, $cliente, $rem, termo_painel($d) . "\n\n📕 \"" . $q . "\" não está no dicionário."), 'motivo' => 'termo fora do dicionário', 'tratou' => true];
    }
    $marcas = termo_marcar((string) $d['palavra'], $q);
    $d['tentativas'][] = ['palavra' => $q, 'marcas' => $marcas];
    jogos_sessao_salvar($db, (int) $s['id'], $d);

    $acertou = $q === (string) $d['palavra'];
    $acabou  = $acertou || count($d['tentativas']) >= 6;
    if (!$acabou) {
        return ['enviou' => jogos_enviar($db, $cliente, $rem, termo_painel($d)), 'motivo' => 'termo jogada', 'tratou' => true];
    }
    jogos_sessao_fechar($db, (int) $s['id'], $acertou ? 'ganhou' : 'perdeu');
    jogos_registrar($db, $cid, $rem, 'termo', $acertou ? 'ganhou' : 'perdeu',
        $acertou ? (7 - count($d['tentativas'])) : 0,
        ((int) ($d['do_dia'] ?? 0) === 1 ? 'dia:' : '') . $d['palavra']);
    $grade = '';
    foreach ($d['tentativas'] as $t) {
        $grade .= $t['marcas'] . "\n";
    }
    $txt = ($acertou ? '🎉 ACERTOU em ' . count($d['tentativas']) . ' tentativa(s)!' : '😕 Acabaram as tentativas.')
         . "\n\nA palavra era: " . $d['palavra']
         . "\n\n" . $grade
         . "\n🔁 \"termo\" joga de novo · 🕹️ \"jogo\" volta ao menu";
    return ['enviou' => jogos_enviar($db, $cliente, $rem, $txt), 'motivo' => 'termo fim', 'tratou' => true];
}

/* ---------------------------------------------------------------- QUIZ */

function quiz_perguntas_fixas(): array
{
    return [
        ['p' => 'Qual é a capital de Alagoas?', 'o' => ['Maceió', 'Recife', 'Aracaju', 'Natal'], 'c' => 0],
        ['p' => 'Quantos jogadores tem um time de futebol em campo?', 'o' => ['9', '10', '11', '12'], 'c' => 2],
        ['p' => 'Qual é o maior planeta do Sistema Solar?', 'o' => ['Terra', 'Júpiter', 'Saturno', 'Marte'], 'c' => 1],
        ['p' => 'Quem escreveu "Dom Casmurro"?', 'o' => ['Machado de Assis', 'José de Alencar', 'Jorge Amado', 'Graciliano Ramos'], 'c' => 0],
        ['p' => 'Qual desses é um instrumento de sopro?', 'o' => ['Violão', 'Bateria', 'Saxofone', 'Baixo'], 'c' => 2],
        ['p' => 'Em que mês é o Natal?', 'o' => ['Novembro', 'Dezembro', 'Janeiro', 'Outubro'], 'c' => 1],
        ['p' => 'Qual é o rio mais extenso do mundo?', 'o' => ['Nilo', 'Amazonas', 'Mississipi', 'Danúbio'], 'c' => 1],
        ['p' => 'Quantas cordas tem um violão comum?', 'o' => ['4', '5', '6', '7'], 'c' => 2],
        ['p' => 'Qual fruta é conhecida como "fruta do conde"?', 'o' => ['Pinha', 'Graviola', 'Caju', 'Jaca'], 'c' => 0],
        ['p' => 'Qual é o oceano que banha o Nordeste brasileiro?', 'o' => ['Pacífico', 'Índico', 'Atlântico', 'Ártico'], 'c' => 2],
    ];
}

/* Perguntas pela IA (opcional). Retorna lista no mesmo formato ou []. */
function quiz_perguntas_ia(PDO $db, int $cid, int $qtd = 5): array
{
    if (OPENAI_API_KEY === '' || (string) pcfg_get($cid, 'dm_jogo_ia', '0') !== '1') {
        return [];
    }
    $modelo = (string) pcfg_get($cid, 'dm_jogo_ia_modelo', 'gpt-4o-mini');
    $tema   = trim((string) pcfg_get($cid, 'dm_jogo_quiz_tema', 'conhecimentos gerais do Brasil'));
    $r = openai_post('chat/completions', [
        'model' => $modelo,
        'messages' => [
            ['role' => 'system', 'content' => 'Você cria perguntas de quiz em português do Brasil. Responda SOMENTE '
                . '{"perguntas":[{"p":"...","o":["a","b","c","d"],"c":0}]} com ' . $qtd . ' perguntas, 4 alternativas curtas '
                . '(até 22 caracteres cada) e "c" = índice da correta. Nada de perguntas ambíguas ou de opinião.'],
            ['role' => 'user', 'content' => 'Tema: ' . ($tema !== '' ? $tema : 'conhecimentos gerais') . '.'],
        ],
        'temperature' => 1.0,
        'max_tokens' => 700,
        'response_format' => ['type' => 'json_object'],
    ]);
    if (!$r['ok']) {
        return [];
    }
    $tin  = (int) ($r['dados']['usage']['prompt_tokens'] ?? 0);
    $tout = (int) ($r['dados']['usage']['completion_tokens'] ?? 0);
    $pr   = ia_precos_texto()[$modelo] ?? ['in' => 0.0, 'out' => 0.0];
    ia_registrar_custo($db, 'texto', $modelo, 'quiz', $tin, $tout, ($tin / 1000) * $pr['in'] + ($tout / 1000) * $pr['out']);
    $j = json_decode(trim((string) ($r['dados']['choices'][0]['message']['content'] ?? '')), true);
    $out = [];
    foreach (($j['perguntas'] ?? []) as $p) {
        $o = array_values(array_filter(array_map('strval', $p['o'] ?? []), static function ($x) {
            return trim($x) !== '';
        }));
        if (trim((string) ($p['p'] ?? '')) !== '' && count($o) === 4 && isset($p['c']) && (int) $p['c'] >= 0 && (int) $p['c'] <= 3) {
            $out[] = ['p' => (string) $p['p'], 'o' => $o, 'c' => (int) $p['c']];
        }
    }
    return $out;
}

function quiz_mostrar(PDO $db, array $cliente, string $rem, array $d): bool
{
    $i = (int) $d['i'];
    $q = $d['perguntas'][$i];
    $letras = ['A', 'B', 'C', 'D'];
    $txt = '❓ QUIZ — pergunta ' . ($i + 1) . '/' . count($d['perguntas']) . "\n\n" . $q['p'] . "\n";
    $bts = [];
    foreach ($q['o'] as $k => $op) {
        $txt .= "\n" . $letras[$k] . ') ' . $op;
        $bts[] = jogos_botao($letras[$k] . ') ' . $op, 'JOGOS_QUIZ_' . $letras[$k]);
    }
    $txt .= "\n\n✍️ Responda A, B, C ou D · 🏳️ \"parar\" encerra";
    return jogos_enviar($db, $cliente, $rem, $txt, $bts);
}

function quiz_iniciar(PDO $db, array $cliente, string $rem): array
{
    $cid = (int) $cliente['id'];
    $pg = quiz_perguntas_ia($db, $cid, 5);
    if (count($pg) < 5) {
        $fx = quiz_perguntas_fixas();
        shuffle($fx);
        $pg = array_slice($fx, 0, 5);
    }
    $d = ['perguntas' => $pg, 'i' => 0, 'acertos' => 0];
    $d['sessao'] = jogos_sessao_criar($db, $cid, $rem, 'quiz', $d);
    jogos_sessao_salvar($db, (int) $d['sessao'], $d);
    return ['enviou' => quiz_mostrar($db, $cliente, $rem, $d), 'motivo' => 'quiz iniciado', 'tratou' => true];
}

function quiz_jogada(PDO $db, array $cliente, string $rem, array $s, string $q): array
{
    $cid = (int) $cliente['id'];
    $d = $s['dados'];
    $mapa = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3];
    $esc = null;
    if (isset($mapa[$q])) {
        $esc = $mapa[$q];
    } else {
        foreach ($d['perguntas'][(int) $d['i']]['o'] as $k => $op) { // respondeu escrevendo a alternativa
            if (jogo_norm($op) === $q) {
                $esc = $k;
                break;
            }
        }
    }
    if ($esc === null) {
        return ['enviou' => quiz_mostrar($db, $cliente, $rem, $d), 'motivo' => 'quiz resposta inválida', 'tratou' => true];
    }
    $cur = $d['perguntas'][(int) $d['i']];
    $ok  = $esc === (int) $cur['c'];
    $d['acertos'] += $ok ? 1 : 0;
    $d['i']++;
    jogos_sessao_salvar($db, (int) $s['id'], $d);

    $feedback = $ok ? '✅ Acertou!' : ('❌ Errou! Era ' . ['A', 'B', 'C', 'D'][(int) $cur['c']] . ') ' . $cur['o'][(int) $cur['c']]);
    if ((int) $d['i'] < count($d['perguntas'])) {
        jogos_enviar($db, $cliente, $rem, $feedback);
        return ['enviou' => quiz_mostrar($db, $cliente, $rem, $d), 'motivo' => 'quiz jogada', 'tratou' => true];
    }
    $ac = (int) $d['acertos'];
    $tot = count($d['perguntas']);
    jogos_sessao_fechar($db, (int) $s['id'], $ac >= 3 ? 'ganhou' : 'perdeu');
    jogos_registrar($db, $cid, $rem, 'quiz', $ac >= 3 ? 'ganhou' : 'perdeu', $ac, $ac . '/' . $tot);
    $medalha = $ac === $tot ? '🏅 GABARITOU!' : ($ac >= 3 ? '👏 Boa!' : '😅 Fica pra próxima!');
    $txt = $feedback . "\n\n" . $medalha . "\n\n📊 Você fez " . $ac . ' de ' . $tot
         . "\n\n🔁 \"quiz\" joga de novo · 🕹️ \"jogo\" volta ao menu";
    return ['enviou' => jogos_enviar($db, $cliente, $rem, $txt), 'motivo' => 'quiz fim', 'tratou' => true];
}

/* ---------------------------------------------------------------- ENIGMA */

function enigma_fixos(): array
{
    return [
        ['r' => 'GELADEIRA', 'p' => ['Fico na cozinha e nunca durmo.', 'Tenho portas, mas não sou casa.', 'Gelo por dentro e zumbido de leve.']],
        ['r' => 'RELOGIO',   'p' => ['Tenho ponteiros, mas não aponto nada.', 'Ando o dia todo sem sair do lugar.', 'Sem mim você perde a hora.']],
        ['r' => 'ESPELHO',   'p' => ['Mostro tudo, mas não guardo nada.', 'Sou sincero até demais.', 'Quem me olha se vê.']],
        ['r' => 'SOMBRA',    'p' => ['Só apareço com luz.', 'Ando sempre com você.', 'Cresço no fim da tarde.']],
        ['r' => 'CHUVA',     'p' => ['Caio sem me machucar.', 'Molho a cidade inteira.', 'Depois de mim vem o arco-íris.']],
        ['r' => 'ABELHA',    'p' => ['Trabalho o dia todo e não recebo salário.', 'Moro num prédio de seis lados.', 'O que eu faço vai no seu pão.']],
        ['r' => 'CEBOLA',    'p' => ['Tenho camadas, mas não sou bolo.', 'Faço gente chorar sem falar nada.', 'Estou em quase toda panela.']],
        ['r' => 'TELEFONE',  'p' => ['Falo sem ter boca.', 'Toco sem ser música.', 'Cabe no bolso e conecta o mundo.']],
    ];
}

function enigma_ia(PDO $db, int $cid): ?array
{
    if (OPENAI_API_KEY === '' || (string) pcfg_get($cid, 'dm_jogo_ia', '0') !== '1') {
        return null;
    }
    $modelo = (string) pcfg_get($cid, 'dm_jogo_ia_modelo', 'gpt-4o-mini');
    $r = openai_post('chat/completions', [
        'model' => $modelo,
        'messages' => [
            ['role' => 'system', 'content' => 'Crie um enigma "quem sou eu" em português do Brasil. Responda SOMENTE '
                . '{"resposta":"UMA PALAVRA","pistas":["...","...","..."]}. A resposta é um objeto/animal/coisa comum, '
                . 'uma palavra só, 4 a 12 letras. As 3 pistas vão da mais difícil para a mais fácil e NUNCA citam a resposta.'],
            ['role' => 'user', 'content' => 'Gere um enigma novo.'],
        ],
        'temperature' => 1.1,
        'max_tokens' => 200,
        'response_format' => ['type' => 'json_object'],
    ]);
    if (!$r['ok']) {
        return null;
    }
    $tin  = (int) ($r['dados']['usage']['prompt_tokens'] ?? 0);
    $tout = (int) ($r['dados']['usage']['completion_tokens'] ?? 0);
    $pr   = ia_precos_texto()[$modelo] ?? ['in' => 0.0, 'out' => 0.0];
    ia_registrar_custo($db, 'texto', $modelo, 'enigma', $tin, $tout, ($tin / 1000) * $pr['in'] + ($tout / 1000) * $pr['out']);
    $j = json_decode(trim((string) ($r['dados']['choices'][0]['message']['content'] ?? '')), true);
    $resp = jogo_norm((string) ($j['resposta'] ?? ''));
    $pistas = array_values(array_filter(array_map('strval', $j['pistas'] ?? [])));
    if (!preg_match('/^[A-Z]{4,12}$/u', $resp) || count($pistas) < 3 || !jogo_palavra_existe($resp)) {
        return null;
    }
    foreach ($pistas as $p) {
        if (mb_strpos(jogo_norm($p), $resp) !== false) {
            return null; // pista entregando a resposta
        }
    }
    return ['r' => $resp, 'p' => array_slice($pistas, 0, 3)];
}

function enigma_iniciar(PDO $db, array $cliente, string $rem): array
{
    $cid = (int) $cliente['id'];
    $e = enigma_ia($db, $cid);
    if ($e === null) {
        $fx = enigma_fixos();
        $e = $fx[random_int(0, count($fx) - 1)];
    }
    $d = ['resposta' => jogo_norm($e['r']), 'pistas' => $e['p'], 'i' => 1, 'erros' => 0];
    $d['sessao'] = jogos_sessao_criar($db, $cid, $rem, 'enigma', $d);
    jogos_sessao_salvar($db, (int) $d['sessao'], $d);
    $txt = "🕵️ ENIGMA — quem sou eu?\n\n1️⃣ " . $e['p'][0]
         . "\n\n✍️ Chuta a resposta! Se travar, mande \"pista\" (tem 3 no total).\n🏳️ \"parar\" encerra";
    return ['enviou' => jogos_enviar($db, $cliente, $rem, $txt, [jogos_botao('💡 pista', 'JOGOS_ENIGMA_PISTA'), jogos_botao('🏳️ parar', 'JOGOS_PARAR')]), 'motivo' => 'enigma iniciado', 'tratou' => true];
}

function enigma_jogada(PDO $db, array $cliente, string $rem, array $s, string $q): array
{
    $cid = (int) $cliente['id'];
    $d = $s['dados'];
    $emoji = ['1️⃣', '2️⃣', '3️⃣'];

    if ($q === 'PISTA' || $q === 'DICA') {
        if ((int) $d['i'] >= count($d['pistas'])) {
            return ['enviou' => jogos_enviar($db, $cliente, $rem, '🙃 Já dei todas as pistas! Chuta aí.'), 'motivo' => 'enigma sem pista', 'tratou' => true];
        }
        $txt = $emoji[(int) $d['i']] . ' ' . $d['pistas'][(int) $d['i']];
        $d['i']++;
        jogos_sessao_salvar($db, (int) $s['id'], $d);
        return ['enviou' => jogos_enviar($db, $cliente, $rem, $txt, [jogos_botao('💡 pista', 'JOGOS_ENIGMA_PISTA'), jogos_botao('🏳️ parar', 'JOGOS_PARAR')]), 'motivo' => 'enigma pista', 'tratou' => true];
    }
    if ($q === (string) $d['resposta']) {
        jogos_sessao_fechar($db, (int) $s['id'], 'ganhou');
        jogos_registrar($db, $cid, $rem, 'enigma', 'ganhou', max(1, 4 - (int) $d['i']), (string) $d['resposta']);
        $txt = "🎉 ISSO! Era " . $d['resposta'] . " mesmo.\n\nVocê usou " . (int) $d['i'] . " pista(s)."
             . "\n\n🔁 \"enigma\" joga de novo · 🕹️ \"jogo\" volta ao menu";
        return ['enviou' => jogos_enviar($db, $cliente, $rem, $txt), 'motivo' => 'enigma ganhou', 'tratou' => true];
    }
    $d['erros']++;
    jogos_sessao_salvar($db, (int) $s['id'], $d);
    if ((int) $d['erros'] >= 5) {
        jogos_sessao_fechar($db, (int) $s['id'], 'perdeu');
        jogos_registrar($db, $cid, $rem, 'enigma', 'perdeu', 0, (string) $d['resposta']);
        return ['enviou' => jogos_enviar($db, $cliente, $rem, "😅 Era " . $d['resposta'] . "!\n\n🔁 \"enigma\" tenta outro"), 'motivo' => 'enigma perdeu', 'tratou' => true];
    }
    return ['enviou' => jogos_enviar($db, $cliente, $rem, '❌ Não é "' . $q . '". Tenta de novo (ou peça uma pista).',
        [jogos_botao('💡 pista', 'JOGOS_ENIGMA_PISTA'), jogos_botao('🏳️ parar', 'JOGOS_PARAR')]), 'motivo' => 'enigma errou', 'tratou' => true];
}

/* ---------------------------------------------------------------- VELHA */

function velha_tabuleiro(array $t): string
{
    $ic = ['' => ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣'], 'X' => '❌', 'O' => '⭕'];
    $l = '';
    for ($i = 0; $i < 9; $i++) {
        $l .= $t[$i] === '' ? $ic[''][$i] : $ic[$t[$i]];
        $l .= ($i % 3 === 2) ? "\n" : '';
    }
    return $l;
}

function velha_vencedor(array $t): string
{
    $linhas = [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
    foreach ($linhas as $l) {
        if ($t[$l[0]] !== '' && $t[$l[0]] === $t[$l[1]] && $t[$l[1]] === $t[$l[2]]) {
            return $t[$l[0]];
        }
    }
    return in_array('', $t, true) ? '' : 'E'; // E = empate
}

/* Minimax simples: o bot joga de O e não perde. */
function velha_minimax(array $t, bool $vezBot): array
{
    $v = velha_vencedor($t);
    if ($v === 'O') {
        return ['nota' => 1, 'pos' => -1];
    }
    if ($v === 'X') {
        return ['nota' => -1, 'pos' => -1];
    }
    if ($v === 'E') {
        return ['nota' => 0, 'pos' => -1];
    }
    $melhor = $vezBot ? -2 : 2;
    $pos = -1;
    for ($i = 0; $i < 9; $i++) {
        if ($t[$i] !== '') {
            continue;
        }
        $t[$i] = $vezBot ? 'O' : 'X';
        $n = velha_minimax($t, !$vezBot)['nota'];
        $t[$i] = '';
        if (($vezBot && $n > $melhor) || (!$vezBot && $n < $melhor)) {
            $melhor = $n;
            $pos = $i;
        }
    }
    return ['nota' => $melhor, 'pos' => $pos];
}

function velha_botoes(array $t): array
{
    $b = [];
    for ($i = 0; $i < 9; $i++) {
        if ($t[$i] === '') {
            $b[] = jogos_botao((string) ($i + 1), 'JOGOS_VELHA_' . ($i + 1));
        }
    }
    $b[] = jogos_botao('🏳️ parar', 'JOGOS_PARAR');
    return $b;
}

function velha_iniciar(PDO $db, array $cliente, string $rem): array
{
    $cid = (int) $cliente['id'];
    $d = ['t' => array_fill(0, 9, '')];
    $d['sessao'] = jogos_sessao_criar($db, $cid, $rem, 'velha', $d);
    jogos_sessao_salvar($db, (int) $d['sessao'], $d);
    $txt = "⭕ JOGO DA VELHA\n\nVocê é ❌, eu sou ⭕.\n\n" . velha_tabuleiro($d['t'])
         . "\n👇 Escolha uma casa (1 a 9)";
    return ['enviou' => jogos_enviar($db, $cliente, $rem, $txt, velha_botoes($d['t'])), 'motivo' => 'velha iniciada', 'tratou' => true];
}

function velha_jogada(PDO $db, array $cliente, string $rem, array $s, string $q): array
{
    $cid = (int) $cliente['id'];
    $d = $s['dados'];
    $t = $d['t'];
    if (!preg_match('/^[1-9]$/', $q)) {
        return ['enviou' => jogos_enviar($db, $cliente, $rem, velha_tabuleiro($t) . "\n👇 Escolha um número de 1 a 9", velha_botoes($t)), 'motivo' => 'velha inválida', 'tratou' => true];
    }
    $i = ((int) $q) - 1;
    if ($t[$i] !== '') {
        return ['enviou' => jogos_enviar($db, $cliente, $rem, "🚫 Essa casa já foi.\n\n" . velha_tabuleiro($t), velha_botoes($t)), 'motivo' => 'velha ocupada', 'tratou' => true];
    }
    $t[$i] = 'X';
    $fim = velha_vencedor($t);
    if ($fim === '') {
        $pos = velha_minimax($t, true)['pos'];
        if ($pos >= 0) {
            $t[$pos] = 'O';
        }
        $fim = velha_vencedor($t);
    }
    $d['t'] = $t;
    jogos_sessao_salvar($db, (int) $s['id'], $d);

    if ($fim === '') {
        return ['enviou' => jogos_enviar($db, $cliente, $rem, velha_tabuleiro($t) . "\n👇 Sua vez", velha_botoes($t)), 'motivo' => 'velha jogada', 'tratou' => true];
    }
    $rot = ['X' => '🎉 VOCÊ GANHOU!', 'O' => '🤖 Eu venci dessa vez!', 'E' => '🤝 Deu velha (empate)!'][$fim];
    $st  = ['X' => 'ganhou', 'O' => 'perdeu', 'E' => 'empatou'][$fim];
    jogos_sessao_fechar($db, (int) $s['id'], $st);
    jogos_registrar($db, $cid, $rem, 'velha', $st, $st === 'ganhou' ? 3 : ($st === 'empatou' ? 1 : 0), '');
    $txt = velha_tabuleiro($t) . "\n" . $rot . "\n\n🔁 \"velha\" joga de novo · 🕹️ \"jogo\" volta ao menu";
    return ['enviou' => jogos_enviar($db, $cliente, $rem, $txt), 'motivo' => 'velha fim', 'tratou' => true];
}

/* ---------------------------------------------------------------- ANAGRAMA */

function anagrama_iniciar(PDO $db, array $cliente, string $rem): array
{
    $cid = (int) $cliente['id'];
    $esc = jogo_palavra_categoria('', []);
    if ($esc === null) {
        return ['enviou' => false, 'motivo' => 'sem categorias', 'tratou' => true];
    }
    $palavra = jogo_norm($esc['palavra']);
    $letras = preg_split('//u', $palavra, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    do {
        shuffle($letras);
        $misturado = implode('', $letras);
    } while ($misturado === $palavra && count($letras) > 2);

    $d = ['palavra' => $palavra, 'misturado' => $misturado, 'dica' => (string) $esc['dica'], 'erros' => 0];
    $d['sessao'] = jogos_sessao_criar($db, $cid, $rem, 'anagrama', $d);
    jogos_sessao_salvar($db, (int) $d['sessao'], $d);
    $txt = "🔀 ANAGRAMA\n\nDesembaralhe:\n\n" . implode(' ', preg_split('//u', $misturado, -1, PREG_SPLIT_NO_EMPTY) ?: [])
         . "\n\n💡 " . $d['dica'] . "\n\n✍️ Mande a palavra certa (3 tentativas) · 🏳️ \"parar\"";
    return ['enviou' => jogos_enviar($db, $cliente, $rem, $txt), 'motivo' => 'anagrama iniciado', 'tratou' => true];
}

function anagrama_jogada(PDO $db, array $cliente, string $rem, array $s, string $q): array
{
    $cid = (int) $cliente['id'];
    $d = $s['dados'];
    if ($q === (string) $d['palavra']) {
        jogos_sessao_fechar($db, (int) $s['id'], 'ganhou');
        jogos_registrar($db, $cid, $rem, 'anagrama', 'ganhou', max(1, 3 - (int) $d['erros']), (string) $d['palavra']);
        return ['enviou' => jogos_enviar($db, $cliente, $rem, "🎉 ISSO! Era " . $d['palavra'] . ".\n\n🔁 \"anagrama\" joga de novo · 🕹️ \"jogo\""), 'motivo' => 'anagrama ganhou', 'tratou' => true];
    }
    $d['erros']++;
    jogos_sessao_salvar($db, (int) $s['id'], $d);
    if ((int) $d['erros'] >= 3) {
        jogos_sessao_fechar($db, (int) $s['id'], 'perdeu');
        jogos_registrar($db, $cid, $rem, 'anagrama', 'perdeu', 0, (string) $d['palavra']);
        return ['enviou' => jogos_enviar($db, $cliente, $rem, "😕 Era " . $d['palavra'] . "!\n\n🔁 \"anagrama\" tenta outra"), 'motivo' => 'anagrama perdeu', 'tratou' => true];
    }
    $rest = 3 - (int) $d['erros'];
    $txt = "❌ Não é isso. Faltam " . $rest . " tentativa(s).\n\n"
         . implode(' ', preg_split('//u', (string) $d['misturado'], -1, PREG_SPLIT_NO_EMPTY) ?: [])
         . "\n💡 " . $d['dica'];
    return ['enviou' => jogos_enviar($db, $cliente, $rem, $txt), 'motivo' => 'anagrama errou', 'tratou' => true];
}

/* ---------------------------------------------------------------- ADIVINHA */

function adivinha_iniciar(PDO $db, array $cliente, string $rem): array
{
    $cid = (int) $cliente['id'];
    $d = ['n' => random_int(1, 100), 'tentativas' => 0, 'max' => 7];
    $d['sessao'] = jogos_sessao_criar($db, $cid, $rem, 'adivinha', $d);
    jogos_sessao_salvar($db, (int) $d['sessao'], $d);
    $txt = "🔢 ADIVINHA O NÚMERO\n\nPensei num número de 1 a 100.\nVocê tem 7 chances — eu digo se é maior ou menor.\n\n✍️ Manda seu palpite!";
    return ['enviou' => jogos_enviar($db, $cliente, $rem, $txt, [jogos_botao('50', 'JOGOS_NUM_50'), jogos_botao('🏳️ parar', 'JOGOS_PARAR')]), 'motivo' => 'adivinha iniciada', 'tratou' => true];
}

function adivinha_jogada(PDO $db, array $cliente, string $rem, array $s, string $q): array
{
    $cid = (int) $cliente['id'];
    $d = $s['dados'];
    if (!preg_match('/^\d{1,3}$/', $q)) {
        return ['enviou' => jogos_enviar($db, $cliente, $rem, '🔢 Manda um número de 1 a 100.'), 'motivo' => 'adivinha inválida', 'tratou' => true];
    }
    $palpite = (int) $q;
    $d['tentativas']++;
    jogos_sessao_salvar($db, (int) $s['id'], $d);
    $alvo = (int) $d['n'];
    $rest = (int) $d['max'] - (int) $d['tentativas'];

    if ($palpite === $alvo) {
        jogos_sessao_fechar($db, (int) $s['id'], 'ganhou');
        jogos_registrar($db, $cid, $rem, 'adivinha', 'ganhou', max(1, 8 - (int) $d['tentativas']), (string) $alvo);
        return ['enviou' => jogos_enviar($db, $cliente, $rem, "🎯 ACERTOU! Era " . $alvo . " mesmo.\nVocê usou " . (int) $d['tentativas'] . " tentativa(s).\n\n🔁 \"adivinha\" joga de novo · 🕹️ \"jogo\""), 'motivo' => 'adivinha ganhou', 'tratou' => true];
    }
    if ($rest <= 0) {
        jogos_sessao_fechar($db, (int) $s['id'], 'perdeu');
        jogos_registrar($db, $cid, $rem, 'adivinha', 'perdeu', 0, (string) $alvo);
        return ['enviou' => jogos_enviar($db, $cliente, $rem, "😅 Acabaram as chances! Era " . $alvo . ".\n\n🔁 \"adivinha\" tenta de novo"), 'motivo' => 'adivinha perdeu', 'tratou' => true];
    }
    $dist = abs($palpite - $alvo);
    $quente = $dist <= 5 ? ' 🔥 tá quente!' : ($dist <= 15 ? ' 🌡️ tá morno' : ' 🧊 tá frio');
    $seta = $palpite < $alvo ? '⬆️ É MAIOR que ' : '⬇️ É MENOR que ';
    return ['enviou' => jogos_enviar($db, $cliente, $rem, $seta . $palpite . '.' . $quente . "\n\nFaltam " . $rest . ' chance(s).'), 'motivo' => 'adivinha dica', 'tratou' => true];
}

/* ---------------------------------------------------------------- roteador */

/**
 * Trata a mensagem nos jogos do arcade (menos a forca, que roda depois).
 * Retorna ['tratou','enviou','motivo'] — tratou=false deixa o resto do webhook seguir.
 */
function jogos_tratar_direct(PDO $db, array $cliente, string $rem, string $texto): array
{
    $nao = ['tratou' => false, 'enviou' => false, 'motivo' => ''];
    $cid = (int) $cliente['id'];
    if (!jogo_ativo($cid) || trim($texto) === '') {
        return $nao;
    }
    $q = jogo_norm($texto);
    $s = jogos_sessao($db, $cid, $rem);

    // menu do arcade
    if (in_array($q, ['JOGOS', 'JOGO', 'ARCADE', 'MENU', 'JOGAR'], true)) {
        return ['tratou' => true, 'enviou' => jogos_menu($db, $cliente, $rem), 'motivo' => 'menu'];
    }

    // ranking e placar valem em qualquer momento (somam TODOS os jogos)
    if (!$s && ($q === 'RANKING' || $q === 'PODIO')) {
        return ['tratou' => true, 'enviou' => jogos_enviar($db, $cliente, $rem, jogos_ranking_texto($db, $cid)), 'motivo' => 'ranking geral'];
    }
    if ($q === 'PLACAR' || $q === 'MEUJOGO' || $q === 'MEUS JOGOS') {
        $pl = jogos_placar($db, $cid, $rem);
        if (!$pl) {
            $t = "📊 Você ainda não jogou nada por aqui.\n\n🕹️ Manda \"jogo\" pra ver os jogos.";
        } else {
            $cat = jogos_catalogo();
            $t = "📊 SEU PLACAR\n";
            $tg = 0;
            foreach ($pl as $jg => $v) {
                $t .= "\n" . (string) ($cat[$jg]['rotulo'] ?? $jg) . ' — ' . $v['ganhou'] . ' vitória(s) em ' . $v['total'];
                $tg += (int) $v['ganhou'];
            }
            $t .= "\n\n🏆 Total: " . $tg . " vitória(s)\n🕹️ \"jogo\" mostra o menu";
        }
        return ['tratou' => true, 'enviou' => jogos_enviar($db, $cliente, $rem, $t), 'motivo' => 'placar geral'];
    }

    // gatilho de algum jogo? (também encerra a sessão anterior)
    $alvo = '';
    foreach (jogos_catalogo() as $k => $j) {
        if ($k === 'forca') {
            continue;
        }
        foreach ($j['gatilhos'] as $g) {
            if ($q === jogo_norm($g)) {
                $alvo = $k;
                break 2;
            }
        }
    }
    if ($alvo !== '') {
        if (!jogos_habilitado($cid, $alvo)) {
            return $nao;
        }
        $maxDia = max(0, (int) pcfg_get($cid, 'dm_jogo_max_dia', '10'));
        if ($maxDia > 0) {
            $c = $db->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'jogo_sessoes
                WHERE cliente_id=? AND remetente_id=? AND criado_em >= CURDATE()');
            $c->execute([$cid, $rem]);
            if ((int) $c->fetchColumn() >= $maxDia * 3) { // teto folgado: 3x o da forca
                $r = dm_enviar($db, $cliente, $rem, '😅 Por hoje chega de jogo! Volta amanhã 🎮', 'auto_jogo');
                return ['tratou' => true, 'enviou' => (bool) ($r['ok'] ?? false), 'motivo' => 'limite diário'];
            }
        }
        $fn = $alvo . '_iniciar';
        return $fn($db, $cliente, $rem);
    }

    // "forca" com sessão de outro jogo aberta -> encerra e deixa a forca assumir
    if ($s) {
        foreach (jogos_catalogo()['forca']['gatilhos'] as $g) {
            if ($q === jogo_norm($g)) {
                jogos_sessao_fechar($db, (int) $s['id'], 'desistiu');
                return $nao;
            }
        }
    }

    if (!$s) {
        return $nao; // sem sessão: forca / ônibus / auto-resposta seguem normalmente
    }

    // comandos gerais durante uma sessão
    if (preg_match('/^(PARAR|PARA|PARE|DESISTO|DESISTIR|CANCELAR|SAIR|CHEGA|STOP)$/u', $q)) {
        jogos_sessao_fechar($db, (int) $s['id'], 'desistiu');
        jogos_registrar($db, $cid, $rem, (string) $s['jogo'], 'desistiu', 0, '');
        $r = dm_enviar($db, $cliente, $rem, "🏳️ Encerrei o jogo.\n\n🕹️ \"jogo\" mostra o menu.", 'auto_jogo');
        return ['tratou' => true, 'enviou' => (bool) ($r['ok'] ?? false), 'motivo' => 'desistiu'];
    }
    if ($q === 'RANKING' || $q === 'PODIO') {
        return ['tratou' => true, 'enviou' => jogos_enviar($db, $cliente, $rem, jogos_ranking_texto($db, $cid)), 'motivo' => 'ranking'];
    }

    $fn = ((string) $s['jogo']) . '_jogada';
    if (!function_exists($fn)) {
        jogos_sessao_fechar($db, (int) $s['id'], 'expirada');
        return $nao;
    }
    return $fn($db, $cliente, $rem, $s, $q);
}

/* Texto do ranking geral do arcade. */
function jogos_ranking_texto(PDO $db, int $cid, int $dias = 7): string
{
    $r = jogos_ranking($db, $cid, $dias, 10);
    if (!$r) {
        return "🏆 Ranking do arcade\n\nNinguém pontuou ainda. Manda \"jogo\" e começa 🎮";
    }
    $m = ['🥇', '🥈', '🥉'];
    $t = "🏆 RANKING DO ARCADE (últimos {$dias} dias)\n";
    foreach ($r as $i => $l) {
        $t .= "\n" . ($m[$i] ?? ($i + 1) . 'º') . ' @' . $l['nome'] . ' — ' . (int) $l['vitorias'] . ' vitória(s)';
    }
    return $t . "\n\n🕹️ \"jogo\" mostra todos os jogos";
}

/* Payload dos botões -> texto de jogada. */
function jogos_payload_para_texto(string $payload): string
{
    if (strpos($payload, 'JOGOS_VELHA_') === 0) {
        return substr($payload, 12);
    }
    if (strpos($payload, 'JOGOS_QUIZ_') === 0) {
        return substr($payload, 11);
    }
    if (strpos($payload, 'JOGOS_NUM_') === 0) {
        return substr($payload, 10);
    }
    if ($payload === 'JOGOS_ENIGMA_PISTA') {
        return 'pista';
    }
    if ($payload === 'JOGOS_PARAR') {
        return 'parar';
    }
    if (strpos($payload, 'JOGOS_') === 0) { // botão do menu: nome do jogo
        return mb_strtolower(substr($payload, 6), 'UTF-8');
    }
    return '';
}
