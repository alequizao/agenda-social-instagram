<?php

declare(strict_types=1);

/**
 * lib_jogo.php — Jogo da FORCA no Direct do Instagram (determinístico, sem IA).
 *
 * Jogo INDEPENDENTE (não tem relação com o conteúdo do perfil).
 * Fluxo: o seguidor manda "forca" no Direct -> o sistema sorteia uma palavra,
 * envia a forca com os tracinhos e a dica. A pessoa vai mandando UMA LETRA por
 * mensagem (ou chuta a palavra inteira). São 6 erros até enforcar.
 *
 * Comandos aceitos durante a partida: uma letra, a palavra inteira,
 * "dica" (custa 1 erro), "placar", "parar/desistir".
 *
 * Ligar por perfil: pcfg dm_jogo_ativo = '1'  (tela Direct > Configurações).
 * Configs por perfil:
 *   dm_jogo_ativo      0/1
 *   dm_jogo_gatilho    palavras que começam a FORCA (vírgula) — padrão "forca, jogo da forca"
 *   dm_jogo_erros      máximo de erros (padrão 6)
 *   dm_jogo_timeout    minutos de inatividade até a partida expirar (padrão 60)
 *   dm_jogo_palavras   lista "PALAVRA|dica" (uma por linha); vazio = lista padrão de palavras gerais
 *
 * Tabela: jogo_partidas (migracao_jogo.sql)
 */

require_once __DIR__ . '/lib_ia.php';      // openai_post(), ia_registrar_custo()
require_once __DIR__ . '/lib_jogo_arte.php'; // arte da forca por código (GD)

const JOGO_MAX_ERROS_PADRAO = 6;

/* Quantas partidas essa pessoa já começou hoje (anti-abuso / custo de IA). */
function jogo_partidas_hoje(PDO $db, int $clienteId, string $remetenteId): int
{
    $st = $db->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'jogo_partidas
        WHERE cliente_id=? AND remetente_id=? AND criado_em >= CURDATE()');
    $st->execute([$clienteId, $remetenteId]);
    return (int) $st->fetchColumn();
}

/* Ranking de vitórias dos últimos $dias dias. Retorna linhas com nome e vitórias. */
function jogo_ranking(PDO $db, int $clienteId, int $dias = 7, int $limite = 10): array
{
    $st = $db->prepare('SELECT j.remetente_id, COALESCE(NULLIF(c.nome, ""), "jogador") AS nome,
            SUM(j.status = "ganhou")  AS vitorias,
            SUM(j.status = "perdeu")  AS derrotas,
            COUNT(*)                  AS partidas
        FROM ' . DB_PREFIX . 'jogo_partidas j
        LEFT JOIN ' . DB_PREFIX . 'dm_conversas c
               ON c.cliente_id = j.cliente_id AND c.remetente_id = j.remetente_id
        WHERE j.cliente_id = ? AND j.criado_em >= (NOW() - INTERVAL ? DAY)
        GROUP BY j.remetente_id, nome
        HAVING vitorias > 0
        ORDER BY vitorias DESC, partidas ASC
        LIMIT ' . (int) $limite);
    $st->execute([$clienteId, max(1, $dias)]);
    return $st->fetchAll();
}

/* Texto do ranking pronto pro Direct. */
function jogo_ranking_texto(PDO $db, int $clienteId, int $dias = 7): string
{
    $r = jogo_ranking($db, $clienteId, $dias, 10);
    if (!$r) {
        return "🏆 Ranking da forca\n\nAinda não tem ninguém no pódio esta semana. Manda \"forca\" e seja o primeiro 🎮";
    }
    $medalha = ['🥇', '🥈', '🥉'];
    $txt = "🏆 RANKING DA FORCA (últimos {$dias} dias)\n";
    foreach ($r as $i => $l) {
        $txt .= "\n" . ($medalha[$i] ?? ($i + 1) . 'º') . ' @' . $l['nome'] . ' — ' . (int) $l['vitorias'] . ' vitória(s)';
    }
    return $txt . "\n\nManda \"forca\" pra entrar na disputa 🎮";
}

/* Palavra tirada de uma MANCHETE recente (liga com a Mesa de Redação). */
function jogo_palavra_noticia(PDO $db, int $clienteId, array $evitar = []): ?array
{
    if (OPENAI_API_KEY === '' || (string) pcfg_get($clienteId, 'dm_jogo_noticia', '0') !== '1') {
        return null;
    }
    $st = $db->query('SELECT titulo FROM ' . DB_PREFIX . 'noticias_descobertas
        WHERE descoberta_em >= (NOW() - INTERVAL 3 DAY) ORDER BY RAND() LIMIT 6');
    $titulos = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (!$titulos) {
        return null;
    }
    $modelo = (string) pcfg_get($clienteId, 'dm_jogo_ia_modelo', 'gpt-4o-mini');
    $sys = 'Você escolhe a palavra de um JOGO DA FORCA a partir de manchetes reais de Maceió/Alagoas. '
         . 'Responda SOMENTE {"palavra":"...","dica":"..."}. A palavra deve SER UMA ÚNICA palavra que aparece '
         . '(ou é o assunto direto) de uma das manchetes, com 5 a 12 letras, sem números. '
         . 'A dica é uma frase curta (até 60 caracteres) que situe o assunto SEM conter a palavra.';
    $usr = "Manchetes:\n- " . implode("\n- ", $titulos)
         . ($evitar ? "\nNão use: " . implode(', ', $evitar) . '.' : '');
    $r = openai_post('chat/completions', [
        'model'           => $modelo,
        'messages'        => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $usr]],
        'temperature'     => 0.9,
        'max_tokens'      => 80,
        'response_format' => ['type' => 'json_object'],
    ]);
    if (!$r['ok']) {
        return null;
    }
    $tin  = (int) ($r['dados']['usage']['prompt_tokens'] ?? 0);
    $tout = (int) ($r['dados']['usage']['completion_tokens'] ?? 0);
    $pr   = ia_precos_texto()[$modelo] ?? ['in' => 0.0, 'out' => 0.0];
    ia_registrar_custo($db, 'texto', $modelo, 'jogo', $tin, $tout, ($tin / 1000) * $pr['in'] + ($tout / 1000) * $pr['out']);

    $j = json_decode(trim((string) ($r['dados']['choices'][0]['message']['content'] ?? '')), true);
    $palavra = is_array($j) ? trim((string) ($j['palavra'] ?? '')) : '';
    $dica    = is_array($j) ? trim((string) ($j['dica'] ?? '')) : '';
    $chave   = jogo_norm($palavra);
    if ($chave === '' || !preg_match('/^[A-Z]{5,14}$/u', $chave) || in_array($chave, $evitar, true)
        || !jogo_palavra_comum($chave)) {
        return null;
    }
    if ($dica !== '' && mb_strpos(jogo_norm($dica), $chave) !== false) {
        $dica = '';
    }
    return ['palavra' => $palavra, 'dica' => mb_substr($dica !== '' ? $dica : '📰 Saiu nas notícias', 0, 190)];
}

/* Categorias curadas (dicionarios/categorias.json): animais, frutas, comidas, objetos,
   profissões, países, estados. Cada uma já tem a dica pronta — sorteio sem custo de IA. */
function jogo_categorias(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $arq = __DIR__ . '/dicionarios/categorias.json';
    $j = is_file($arq) ? json_decode((string) file_get_contents($arq), true) : null;
    $cache = is_array($j) ? $j : [];
    return $cache;
}

/* Sorteia uma palavra de uma categoria (ou de qualquer uma, se $cat = ''). */
function jogo_palavra_categoria(string $cat = '', array $evitar = []): ?array
{
    $cats = jogo_categorias();
    if (!$cats) {
        return null;
    }
    if ($cat === '' || !isset($cats[$cat])) {
        $chaves = array_keys($cats);
        $cat = $chaves[random_int(0, count($chaves) - 1)];
    }
    $pool = array_values(array_filter(array_diff($cats[$cat]['palavras'] ?? [], $evitar),
        static function (string $w): bool {
            return mb_strlen($w, 'UTF-8') >= 5; // palavra de 4 letras é fácil demais
        }));
    if (!$pool) {
        return null;
    }
    $p = $pool[random_int(0, count($pool) - 1)];
    return ['palavra' => $p, 'dica' => (string) ($cats[$cat]['dica'] ?? ''), 'categoria' => $cat];
}

/* A palavra está na faixa de dificuldade pedida? (nivel-facil|medio|dificil.idx) */
function jogo_palavra_do_nivel(string $chave, string $nivel): bool
{
    $nivel = in_array($nivel, ['facil', 'medio', 'dificil'], true) ? $nivel : 'medio';
    $arq = 'nivel-' . $nivel . '.idx';
    if (!is_file(__DIR__ . '/dicionarios/' . $arq)) {
        return true;
    }
    if (jogo_palavra_existe($chave, $arq)) {
        return true;
    }
    // no fácil/médio também vale palavra de nível mais fácil que o pedido
    if ($nivel === 'dificil') {
        return jogo_palavra_existe($chave, 'nivel-medio.idx') || jogo_palavra_existe($chave, 'nivel-facil.idx');
    }
    if ($nivel === 'medio') {
        return jogo_palavra_existe($chave, 'nivel-facil.idx');
    }
    return false;
}

/* Nível do jogador: sobe conforme as vitórias recentes (dificuldade progressiva). */
function jogo_nivel_do_jogador(PDO $db, int $clienteId, string $remetenteId): string
{
    $st = $db->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'jogo_partidas
        WHERE cliente_id=? AND remetente_id=? AND status="ganhou"');
    $st->execute([$clienteId, $remetenteId]);
    $v = (int) $st->fetchColumn();
    if ($v >= 8) {
        return 'dificil';
    }
    return $v >= 3 ? 'medio' : 'facil';
}

/* Sequência atual de vitórias (streak) e a melhor de todos os tempos. */
function jogo_sequencia(PDO $db, int $clienteId, string $remetenteId): array
{
    $st = $db->prepare('SELECT status FROM ' . DB_PREFIX . 'jogo_partidas
        WHERE cliente_id=? AND remetente_id=? AND status IN ("ganhou","perdeu","desistiu")
        ORDER BY id DESC LIMIT 200');
    $st->execute([$clienteId, $remetenteId]);
    $linhas = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $atual = 0;
    foreach ($linhas as $s) {
        if ($s !== 'ganhou') {
            break;
        }
        $atual++;
    }
    $melhor = 0;
    $corr = 0;
    foreach (array_reverse($linhas) as $s) {
        $corr = $s === 'ganhou' ? $corr + 1 : 0;
        $melhor = max($melhor, $corr);
    }
    return ['atual' => $atual, 'melhor' => $melhor];
}

/* Conquista desbloqueada com esta vitória (ou '' se nenhuma). */
function jogo_conquista(int $streak, int $vitorias, int $erros): string
{
    if ($erros === 0) {
        return '🎖️ PERFEITO! Zero erros nessa palavra.';
    }
    if ($streak === 10) {
        return '👑 10 VITÓRIAS SEGUIDAS! Você é lenda.';
    }
    if ($streak === 5) {
        return '🔥 5 seguidas! Tá pegando fogo.';
    }
    if ($streak === 3) {
        return '⚡ 3 seguidas! Sequência quente.';
    }
    if ($vitorias === 1) {
        return '🎉 Primeira vitória! Bem-vindo ao jogo.';
    }
    return '';
}

/* Palavra do dia do perfil: a MESMA para todos os jogadores no dia. */
function jogo_palavra_do_dia(PDO $db, int $clienteId): ?array
{
    $st = $db->prepare('SELECT * FROM ' . DB_PREFIX . 'jogo_dia WHERE cliente_id=? AND dia=CURDATE()');
    $st->execute([$clienteId]);
    $r = $st->fetch();
    if ($r) {
        return ['palavra' => (string) $r['palavra'], 'dica' => (string) $r['dica'], 'categoria' => (string) $r['categoria']];
    }
    // ainda não sorteada hoje -> sorteia e grava (categoria primeiro, IA como reforço)
    $ja = $db->prepare('SELECT chave FROM ' . DB_PREFIX . 'jogo_dia WHERE cliente_id=? ORDER BY dia DESC LIMIT 15');
    $ja->execute([$clienteId]);
    $evitar = array_map('strval', $ja->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $esc = jogo_palavra_categoria('', $evitar);
    if ($esc === null) {
        $esc = jogo_palavra_ia($db, $clienteId, $evitar);
    }
    if ($esc === null) {
        return null;
    }
    $ins = $db->prepare('INSERT IGNORE INTO ' . DB_PREFIX . 'jogo_dia
        (cliente_id, dia, palavra, chave, dica, categoria) VALUES (?, CURDATE(), ?, ?, ?, ?)');
    $ins->execute([$clienteId, $esc['palavra'], jogo_norm($esc['palavra']),
        mb_substr((string) $esc['dica'], 0, 190), (string) ($esc['categoria'] ?? '')]);
    return $esc;
}

/* Ranking de HOJE na palavra do dia: quem acertou com menos erros, mais rápido. */
function jogo_ranking_dia(PDO $db, int $clienteId, int $limite = 10): array
{
    $st = $db->prepare('SELECT COALESCE(NULLIF(c.nome,""), "jogador") nome, j.erros, j.max_erros,
            j.finalizada_em, j.palavra
        FROM ' . DB_PREFIX . 'jogo_partidas j
        LEFT JOIN ' . DB_PREFIX . 'dm_conversas c ON c.cliente_id=j.cliente_id AND c.remetente_id=j.remetente_id
        WHERE j.cliente_id=? AND j.do_dia=1 AND j.status="ganhou" AND j.criado_em >= CURDATE()
        ORDER BY j.erros ASC, j.finalizada_em ASC LIMIT ' . (int) $limite);
    $st->execute([$clienteId]);
    return $st->fetchAll();
}

/* Botões (quick replies) com as letras ainda não tentadas + comandos. Máx 13. */
function jogo_botoes(array $p, bool $temDica): array
{
    $ordem  = ['A', 'E', 'O', 'S', 'R', 'I', 'N', 'M', 'T', 'C', 'L', 'U', 'D', 'P', 'B', 'G', 'V', 'F'];
    $usadas = (string) $p['letras'];
    $ops = [];
    foreach ($ordem as $l) {
        if (mb_strpos($usadas, $l) === false) {
            $ops[] = ['content_type' => 'text', 'title' => $l, 'payload' => 'JOGO_L_' . $l];
        }
        if (count($ops) >= ($temDica ? 11 : 12)) {
            break;
        }
    }
    if ($temDica) {
        $ops[] = ['content_type' => 'text', 'title' => '🆘 dica', 'payload' => 'JOGO_DICA'];
    }
    $ops[] = ['content_type' => 'text', 'title' => '🏳️ parar', 'payload' => 'JOGO_PARAR'];
    return $ops;
}

/* Envia o painel: com botões de letra e, se ligado, a arte da forca. */
function jogo_responder(PDO $db, array $cliente, string $remetenteId, array $p, string $texto, bool $comArte = false, bool $revelar = false): bool
{
    $cid = (int) $cliente['id'];
    if ($comArte && (string) pcfg_get($cid, 'dm_jogo_arte', '1') === '1') {
        $url = jogo_arte_gerar($p, $revelar ? 'FIM DE JOGO' : 'JOGO DA FORCA', $revelar, (string) pcfg_get($cid, 'ia_handle', ''));
        if ($url !== '') {
            dm_enviar_midia($db, $cliente, $remetenteId, $url, 'image', 'auto_jogo');
        }
    }
    if (!$revelar && (string) pcfg_get($cid, 'dm_jogo_botoes', '1') === '1') {
        $env = dm_enviar_opcoes($db, $cliente, $remetenteId, $texto, jogo_botoes($p, true), 'auto_jogo');
        return (bool) ($env['ok'] ?? false);
    }
    $env = dm_enviar($db, $cliente, $remetenteId, $texto, 'auto_jogo');
    return (bool) ($env['ok'] ?? false);
}

/* Dicionário pt-BR para validar o que a IA inventa ("umbrela" não existe).
   dicionarios/pt-BR.idx = chaves normalizadas (A-Z), uma por linha, ORDENADAS.
   Busca binária no arquivo: não carrega os 2,4 MB na memória do webhook.
   Fonte da lista: github.com/pythonprobr/palavras (MPL-2.0) — ver dicionarios/FONTE.txt. */
function jogo_palavra_existe(string $chave, string $arquivo = 'pt-BR.idx'): bool
{
    $arq = __DIR__ . '/dicionarios/' . $arquivo;
    if ($chave === '' || !is_file($arq)) {
        return true; // sem dicionário instalado, não bloqueia nada
    }
    $fh = @fopen($arq, 'rb');
    if (!$fh) {
        return true;
    }
    $lo = 0;
    $hi = (int) filesize($arq);
    $achou = false;
    // busca binária por bytes: alinha no início da linha que contém o meio
    while ($lo < $hi) {
        $meio = (int) (($lo + $hi) / 2);
        fseek($fh, $meio);
        if ($meio > 0) {
            fgets($fh); // descarta a linha cortada
        }
        $pos = ftell($fh);
        if ($pos >= $hi) {
            break; // janela pequena demais -> termina no linear abaixo
        }
        $linha = fgets($fh);
        if ($linha === false) {
            break;
        }
        $cmp = strcmp(rtrim($linha, "\r\n"), $chave);
        if ($cmp === 0) {
            $achou = true;
            break;
        }
        if ($cmp < 0) {
            $lo = (int) ftell($fh); // continua depois desta linha
        } else {
            $hi = $pos;             // a resposta está antes desta linha
        }
    }
    // varredura linear da janela que sobrou (algumas linhas, no máximo)
    if (!$achou && $lo < $hi) {
        fseek($fh, $lo);
        while (($linha = fgets($fh)) !== false && ftell($fh) <= $hi + 64) {
            $cmp = strcmp(rtrim($linha, "\r\n"), $chave);
            if ($cmp === 0) {
                $achou = true;
                break;
            }
            if ($cmp > 0) {
                break;
            }
        }
    }
    fclose($fh);
    return $achou;
}

/* A palavra é COMUM (o brasileiro reconhece)? Usa a lista filtrada por frequência.
   Sem a lista instalada, cai na checagem do dicionário completo. */
function jogo_palavra_comum(string $chave): bool
{
    if (is_file(__DIR__ . '/dicionarios/pt-BR-comuns.idx')) {
        return jogo_palavra_existe($chave, 'pt-BR-comuns.idx');
    }
    return jogo_palavra_existe($chave);
}

/* Palavra + dica geradas pela OpenAI (opcional, dm_jogo_ia=1).
   Retorna ['palavra'=>..,'dica'=>..] ou null (aí cai na lista fixa). */
function jogo_palavra_ia(PDO $db, int $clienteId, array $evitar = [], string $nivel = ''): ?array
{
    if (OPENAI_API_KEY === '' || (string) pcfg_get($clienteId, 'dm_jogo_ia', '0') !== '1') {
        return null;
    }
    $tema   = trim((string) pcfg_get($clienteId, 'dm_jogo_tema', 'objetos, comida, lugares, animais e cotidiano brasileiro'));
    $modelo = (string) pcfg_get($clienteId, 'dm_jogo_ia_modelo', 'gpt-4o-mini');
    $sys = 'Você cria palavras para um JOGO DA FORCA jogado no Direct do Instagram. '
         . 'Responda SOMENTE um JSON {"palavra":"...","dica":"..."}. '
         . 'A palavra deve ser um substantivo comum ou nome próprio conhecido, em português do Brasil, '
         . 'com 5 a 12 letras, SEM espaços, SEM hífen e SEM acento no meio de expressões: precisa ser UMA PALAVRA só '
         . '(nada de "vai e vem", "bate papo", "guarda chuva"). '
         . 'A dica é uma frase curta (até 60 caracteres) que NÃO pode conter a palavra nem parte dela.';
    $dif = ['facil' => 'Use uma palavra MUITO conhecida, do dia a dia.',
            'medio' => 'Use uma palavra conhecida, nem óbvia nem rara.',
            'dificil' => 'Use uma palavra conhecida porém mais desafiadora (mais longa ou menos usada).'][$nivel] ?? '';
    $usr = 'Tema: ' . ($tema !== '' ? $tema : 'cotidiano brasileiro') . '. ' . $dif . ' '
         . ($evitar ? 'Não repita nenhuma destas: ' . implode(', ', $evitar) . '.' : '');
    $r = openai_post('chat/completions', [
        'model'           => $modelo,
        'messages'        => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $usr]],
        'temperature'     => 1.0,
        'max_tokens'      => 80,
        'response_format' => ['type' => 'json_object'],
    ]);
    if (!$r['ok']) {
        return null;
    }
    $tin  = (int) ($r['dados']['usage']['prompt_tokens'] ?? 0);
    $tout = (int) ($r['dados']['usage']['completion_tokens'] ?? 0);
    $pr   = ia_precos_texto()[$modelo] ?? ['in' => 0.0, 'out' => 0.0];
    ia_registrar_custo($db, 'texto', $modelo, 'jogo', $tin, $tout, ($tin / 1000) * $pr['in'] + ($tout / 1000) * $pr['out']);

    $j = json_decode(trim((string) ($r['dados']['choices'][0]['message']['content'] ?? '')), true);
    $palavra = is_array($j) ? trim((string) ($j['palavra'] ?? '')) : '';
    $dica    = is_array($j) ? trim((string) ($j['dica'] ?? '')) : '';
    $chave   = jogo_norm($palavra);
    // validações: só letras, tamanho razoável, não repetida e a dica não entrega a palavra
    if ($chave === '' || !preg_match('/^[A-Z]{5,14}$/u', $chave)) {
        return null; // precisa ser UMA palavra só (sem espaço/hífen) e de tamanho jogável
    }
    if (!jogo_palavra_comum($chave)) {
        return null; // não existe em pt-BR ou é palavra rara demais pra forca
    }
    if ($nivel !== '' && !jogo_palavra_do_nivel($chave, $nivel)) {
        return null; // fora da faixa de dificuldade pedida
    }
    if (in_array($chave, $evitar, true)) {
        return null;
    }
    if ($dica !== '' && mb_strpos(jogo_norm($dica), str_replace([' ', '-'], '', $chave)) !== false) {
        $dica = ''; // a dica entregava a palavra
    }
    if ($dica === '') {
        return null; // toda palavra precisa de dica -> tenta a próxima fonte
    }
    return ['palavra' => $palavra, 'dica' => mb_substr($dica, 0, 190)];
}

/* Módulo ligado para este perfil? */
function jogo_ativo(int $clienteId): bool
{
    return (string) pcfg_get($clienteId, 'dm_jogo_ativo', '0') === '1';
}

/* Normaliza: maiúsculas sem acento (Á->A, Ç->C). Mantém espaço e hífen. */
function jogo_norm(string $t): string
{
    $t = mb_strtoupper(trim($t), 'UTF-8');
    $de = ['Á','À','Â','Ã','Ä','É','È','Ê','Ë','Í','Ì','Î','Ï','Ó','Ò','Ô','Õ','Ö','Ú','Ù','Û','Ü','Ç','Ñ'];
    $pa = ['A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','C','N'];
    $t  = str_replace($de, $pa, $t);
    $t  = preg_replace('/[^A-Z0-9 \-]/u', '', $t) ?? '';
    return trim(preg_replace('/\s+/', ' ', $t) ?? '');
}

/* Lista de palavras do perfil: [['palavra'=>..,'dica'=>..], ...] */
function jogo_palavras(int $clienteId): array
{
    $bruto = trim((string) pcfg_get($clienteId, 'dm_jogo_palavras', ''));
    $lista = [];
    if ($bruto !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $bruto) ?: [] as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }
            $partes  = explode('|', $linha, 2);
            $palavra = trim($partes[0]);
            if (jogo_norm($palavra) === '') {
                continue;
            }
            $lista[] = ['palavra' => $palavra, 'dica' => trim($partes[1] ?? '')];
        }
    }
    if ($lista) {
        return $lista;
    }
    // padrão: palavras gerais do português (o jogo é independente do perfil)
    return [
        ['palavra' => 'ABACAXI',    'dica' => '🍍 Fruta de casca espinhosa'],
        ['palavra' => 'BICICLETA',  'dica' => '🚲 Tem duas rodas e pedal'],
        ['palavra' => 'CACHOEIRA',  'dica' => '💦 Água caindo lá do alto'],
        ['palavra' => 'DINHEIRO',   'dica' => '💸 Todo mundo quer mais'],
        ['palavra' => 'ESTRELA',    'dica' => '⭐ Brilha lá no céu'],
        ['palavra' => 'FEIJOADA',   'dica' => '🍲 Prato pesado de sábado'],
        ['palavra' => 'GUITARRA',   'dica' => '🎸 Instrumento de seis cordas'],
        ['palavra' => 'HOSPITAL',   'dica' => '🏥 Lugar de quem precisa de médico'],
        ['palavra' => 'JANELA',     'dica' => '🪟 Por onde entra a luz do quarto'],
        ['palavra' => 'MOCHILA',    'dica' => '🎒 Vai nas costas do estudante'],
        ['palavra' => 'NAMORADO',   'dica' => '❤️ O par de alguém'],
        ['palavra' => 'OCULOS',     'dica' => '👓 Ajuda quem não enxerga bem'],
        ['palavra' => 'PIPOCA',     'dica' => '🍿 Companheira de cinema'],
        ['palavra' => 'RELOGIO',    'dica' => '⏰ Marca as horas'],
        ['palavra' => 'SANDALIA',   'dica' => '👡 Calçado de verão'],
        ['palavra' => 'TELEFONE',   'dica' => '📞 Serve pra falar de longe'],
        ['palavra' => 'UNIVERSO',   'dica' => '🌌 Tudo o que existe'],
        ['palavra' => 'VIOLAO',     'dica' => '🎶 Primo acústico da guitarra'],
        ['palavra' => 'CHOCOLATE',  'dica' => '🍫 Derrete na mão'],
        ['palavra' => 'FUTEBOL',    'dica' => '⚽ Onze de cada lado'],
        ['palavra' => 'GELADEIRA',  'dica' => '🧊 Guarda a comida no frio'],
        ['palavra' => 'AVENTURA',   'dica' => '🧭 História cheia de emoção'],
        ['palavra' => 'CARNAVAL',   'dica' => '🎭 Festa de fevereiro'],
        ['palavra' => 'MERGULHO',   'dica' => '🤿 Ir fundo na água'],
        ['palavra' => 'SORVETE',    'dica' => '🍦 Gelado que derrete rápido'],
    ];
}

/* Desenho da forca por número de erros (0..6). */
function jogo_desenho(int $erros, int $max): string
{
    $q = [
        "┌───┐\n│   \n│   \n│   \n┴",
        "┌───┐\n│   ○\n│   \n│   \n┴",
        "┌───┐\n│   ○\n│   │\n│   \n┴",
        "┌───┐\n│   ○\n│  /│\n│   \n┴",
        "┌───┐\n│   ○\n│  /│\\\n│   \n┴",
        "┌───┐\n│   ○\n│  /│\\\n│  / \n┴",
        "┌───┐\n│   ○\n│  /│\\\n│  / \\\n┴",
    ];
    if ($max !== 6) { // máximo customizado: escala o desenho proporcionalmente
        $erros = (int) round(($erros / max(1, $max)) * 6);
    }
    return $q[max(0, min(6, $erros))];
}

/* Máscara da palavra, na POSIÇÃO certa: ⬜ ⬜ T ⬜ ⬜.
   $emoji=false usa "_" (para a arte em imagem, que não desenha emoji). */
function jogo_mascara(string $chave, string $letras, bool $emoji = false): string
{
    $vazio = $emoji ? '⬜' : '_';
    $out = [];
    $n   = mb_strlen($chave, 'UTF-8');
    for ($i = 0; $i < $n; $i++) {
        $c = mb_substr($chave, $i, 1, 'UTF-8');
        if ($c === ' ') {
            $out[] = $emoji ? '➖' : '/';
        } elseif ($c === '-') {
            $out[] = '-';
        } elseif (mb_strpos($letras, $c) !== false) {
            $out[] = $emoji ? ' ' . $c . ' ' : $c;
        } else {
            $out[] = $vazio;
        }
    }
    return implode($emoji ? '' : ' ', $out);
}

/* Já adivinhou tudo? */
function jogo_completou(string $chave, string $letras): bool
{
    $n = mb_strlen($chave, 'UTF-8');
    for ($i = 0; $i < $n; $i++) {
        $c = mb_substr($chave, $i, 1, 'UTF-8');
        if ($c === ' ' || $c === '-') {
            continue;
        }
        if (mb_strpos($letras, $c) === false) {
            return false;
        }
    }
    return true;
}

/* Painel da partida (desenho + tracinhos + letras usadas + vidas). */
function jogo_painel(array $p, string $cabecalho = ''): string
{
    $erros = (int) $p['erros'];
    $max   = (int) $p['max_erros'];
    $usadas = trim(implode(' ', preg_split('//u', (string) $p['letras'], -1, PREG_SPLIT_NO_EMPTY) ?: []));
    $txt  = ($cabecalho !== '' ? $cabecalho . "\n\n" : '');
    $txt .= jogo_desenho($erros, $max) . "\n\n";
    $txt .= jogo_mascara((string) $p['chave'], (string) $p['letras'], true) . "\n\n";
    $txt .= '❤️ Vidas: ' . max(0, $max - $erros) . '/' . $max;
    if ($usadas !== '') {
        $txt .= "\n🔤 Letras: " . $usadas;
    }
    return $txt;
}

/* Partida ativa (e não expirada) desta pessoa. Expira automaticamente. */
function jogo_partida_ativa(PDO $db, int $clienteId, string $remetenteId, int $timeoutMin): ?array
{
    $st = $db->prepare('SELECT * FROM ' . DB_PREFIX . 'jogo_partidas
        WHERE cliente_id=? AND remetente_id=? AND status=\'ativa\' ORDER BY id DESC LIMIT 1');
    $st->execute([$clienteId, $remetenteId]);
    $p = $st->fetch();
    if (!$p) {
        return null;
    }
    if ($timeoutMin > 0 && strtotime((string) $p['atualizado_em']) < time() - ($timeoutMin * 60)) {
        $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_partidas SET status=\'expirada\' WHERE id=?')
           ->execute([(int) $p['id']]);
        return null;
    }
    return $p;
}

/* Placar acumulado da pessoa neste perfil. */
function jogo_placar(PDO $db, int $clienteId, string $remetenteId): array
{
    $st = $db->prepare('SELECT status, COUNT(*) n FROM ' . DB_PREFIX . 'jogo_partidas
        WHERE cliente_id=? AND remetente_id=? GROUP BY status');
    $st->execute([$clienteId, $remetenteId]);
    $r = ['ganhou' => 0, 'perdeu' => 0, 'desistiu' => 0];
    foreach ($st->fetchAll() as $l) {
        $r[(string) $l['status']] = (int) $l['n'];
    }
    return $r;
}

/* Escolhe a palavra da partida. Ordem:
   1) palavra do dia (se ligada e a pessoa ainda não jogou a de hoje)
   2) OpenAI, no nível do jogador (se ligada)
   3) categoria curada (animais, frutas, países…)
   4) manchete da Mesa de Redação (se ligada)
   5) lista fixa do perfil / lista padrão
   Retorna ['palavra','dica','categoria','nivel','do_dia']. */
function jogo_sortear(PDO $db, int $clienteId, string $remetenteId, string $forcarNivel = '', string $forcarCat = ''): array
{
    $st = $db->prepare('SELECT chave FROM ' . DB_PREFIX . 'jogo_partidas
        WHERE cliente_id=? AND remetente_id=? ORDER BY id DESC LIMIT 8');
    $st->execute([$clienteId, $remetenteId]);
    $recentes = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $nivel = $forcarNivel !== '' ? $forcarNivel : jogo_nivel_do_jogador($db, $clienteId, $remetenteId);

    // pedido explícito de categoria ("animais", "frutas"...) -> vai direto nela
    if ($forcarCat !== '') {
        $c = jogo_palavra_categoria($forcarCat, $recentes);
        if ($c !== null) {
            return $c + ['nivel' => $nivel, 'do_dia' => 0];
        }
    }

    // 1) palavra do dia — a mesma pra todo mundo, uma vez por pessoa por dia
    if ($forcarNivel === '' && $forcarCat === '' && (string) pcfg_get($clienteId, 'dm_jogo_palavra_dia', '1') === '1') {
        $jaJogou = $db->prepare('SELECT COUNT(*) FROM ' . DB_PREFIX . 'jogo_partidas
            WHERE cliente_id=? AND remetente_id=? AND do_dia=1 AND criado_em >= CURDATE()');
        $jaJogou->execute([$clienteId, $remetenteId]);
        if ((int) $jaJogou->fetchColumn() === 0) {
            $pd = jogo_palavra_do_dia($db, $clienteId);
            if ($pd !== null) {
                return $pd + ['nivel' => $nivel, 'do_dia' => 1];
            }
        }
    }

    // 2) OpenAI no nível do jogador
    $ia = jogo_palavra_ia($db, $clienteId, $recentes, $nivel);
    if ($ia !== null) {
        return $ia + ['categoria' => '', 'nivel' => $nivel, 'do_dia' => 0];
    }

    // 3) categoria curada (sempre tem dica, custo zero)
    $cat = jogo_palavra_categoria('', $recentes);
    if ($cat !== null) {
        return $cat + ['nivel' => $nivel, 'do_dia' => 0];
    }

    // 4) manchete recente
    $nt = jogo_palavra_noticia($db, $clienteId, $recentes);
    if ($nt !== null) {
        return $nt + ['categoria' => 'noticia', 'nivel' => $nivel, 'do_dia' => 0];
    }

    // 5) lista fixa
    $lista = jogo_palavras($clienteId);
    $livres = array_values(array_filter($lista, static function (array $p) use ($recentes): bool {
        return !in_array(jogo_norm($p['palavra']), $recentes, true);
    }));
    $pool = $livres ?: $lista;
    return $pool[random_int(0, count($pool) - 1)] + ['categoria' => '', 'nivel' => $nivel, 'do_dia' => 0];
}

/* Inicia uma partida e envia o painel. */
function jogo_iniciar(PDO $db, array $cliente, string $remetenteId, string $nivel = '', string $categoria = ''): array
{
    $cid = (int) $cliente['id'];
    // anti-abuso: teto de partidas por pessoa por dia (segura custo de IA e spam)
    $maxDia = max(0, (int) pcfg_get($cid, 'dm_jogo_max_dia', '10'));
    if ($maxDia > 0 && jogo_partidas_hoje($db, $cid, $remetenteId) >= $maxDia) {
        $env = dm_enviar($db, $cliente, $remetenteId,
            "😅 Por hoje chega! Você já jogou {$maxDia} partidas.\n\nVolta amanhã que tem palavra nova 🎮\n(manda \"ranking\" pra ver como você está)", 'auto_jogo');
        return ['enviou' => (bool) ($env['ok'] ?? false), 'motivo' => 'limite diário'];
    }
    $esc  = jogo_sortear($db, $cid, $remetenteId, $nivel, $categoria);
    $max  = max(3, min(10, (int) pcfg_get($cid, 'dm_jogo_erros', (string) JOGO_MAX_ERROS_PADRAO)));
    $chave = jogo_norm($esc['palavra']);

    $ins = $db->prepare('INSERT INTO ' . DB_PREFIX . 'jogo_partidas
        (cliente_id, remetente_id, palavra, chave, dica, categoria, nivel, do_dia, max_erros)
        VALUES (?,?,?,?,?,?,?,?,?)');
    $ins->execute([$cid, $remetenteId, $esc['palavra'], $chave, mb_substr((string) $esc['dica'], 0, 190),
        (string) ($esc['categoria'] ?? ''), (string) ($esc['nivel'] ?? 'medio'), (int) ($esc['do_dia'] ?? 0), $max]);
    $p = ['chave' => $chave, 'letras' => '', 'erros' => 0, 'max_erros' => $max,
          'dica' => (string) $esc['dica'], 'dica_usada' => 0];

    $rotNivel = ['facil' => '🟢 fácil', 'medio' => '🟡 médio', 'dificil' => '🔴 difícil'][(string) ($esc['nivel'] ?? '')] ?? '';
    $cab  = (int) ($esc['do_dia'] ?? 0) === 1 ? "🗓️ PALAVRA DO DIA — todo mundo joga a mesma hoje!\n" : "🎮 JOGO DA FORCA\n";
    if ($rotNivel !== '') {
        $cab .= 'Nível: ' . $rotNivel . "\n";
    }
    $cab .= 'A palavra tem ' . mb_strlen(str_replace([' ', '-'], '', $chave), 'UTF-8') . ' letras.';
    if (trim((string) $esc['dica']) !== '') {
        $cab .= "\n💡 Dica: " . $esc['dica'];
    }
    $txt  = jogo_painel($p, $cab);
    $txt .= "\n\n✍️ Mande UMA LETRA por mensagem (ou toque nos botões). Se souber, pode chutar a palavra inteira!";
    $txt .= "\n🆘 \"dica\" revela uma letra e custa 1 vida · 🏆 \"ranking\" · 🗂️ \"categorias\" · 🏳️ \"parar\"";
    $ok = jogo_responder($db, $cliente, $remetenteId, $p, $txt, true);
    return ['enviou' => $ok, 'motivo' => 'partida iniciada'];
}

/* Fecha a partida com um status final e monta a mensagem de encerramento. */
function jogo_encerrar(PDO $db, array $cliente, string $remetenteId, array $p, string $status, string $cabecalho): array
{
    $cid = (int) $cliente['id'];
    $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_partidas SET status=?, letras=?, erros=?, finalizada_em=NOW() WHERE id=?')
       ->execute([$status, (string) $p['letras'], (int) $p['erros'], (int) $p['id']]);
    $pl  = jogo_placar($db, $cid, $remetenteId);
    $seq = jogo_sequencia($db, $cid, $remetenteId);
    $txt = $cabecalho . "\n\nA palavra era: " . mb_strtoupper((string) $p['palavra'], 'UTF-8');
    if ($status === 'ganhou') {
        $conq = jogo_conquista($seq['atual'], (int) $pl['ganhou'], (int) $p['erros']);
        if ($conq !== '') {
            $txt .= "\n\n" . $conq;
        }
    }
    $txt .= "\n\n🏆 Seu placar: " . $pl['ganhou'] . ' vitória(s) · ' . $pl['perdeu'] . ' derrota(s)';
    if ($seq['atual'] > 1) {
        $txt .= "\n🔥 Sequência atual: " . $seq['atual'] . ' (melhor: ' . $seq['melhor'] . ')';
    }
    if ((int) ($p['do_dia'] ?? 0) === 1 && $status === 'ganhou') {
        $pos = 0;
        foreach (jogo_ranking_dia($db, $cid, 20) as $i => $l) {
            if ((int) $l['erros'] === (int) $p['erros']) {
                $pos = $i + 1;
                break;
            }
        }
        $txt .= "\n🗓️ Palavra do dia: você fechou com " . (int) $p['erros'] . ' erro(s)'
              . ($pos > 0 ? ' — ' . $pos . 'º de hoje' : '');
    }
    $txt .= "\n🔁 Manda \"forca\" pra jogar de novo · 🏆 \"ranking\" pro pódio";
    $ok = jogo_responder($db, $cliente, $remetenteId, $p, $txt, true, true);
    // imagem de resultado (pra pessoa postar no story) — só em partida terminada de verdade
    if (in_array($status, ['ganhou', 'perdeu'], true)
        && (string) pcfg_get($cid, 'dm_jogo_resultado', '1') === '1') {
        $handle = trim((string) pcfg_get($cid, 'ia_handle', '')) ?: ('@' . (string) ($cliente['nome'] ?? ''));
        $url = jogo_arte_resultado($p, $status === 'ganhou', $handle, (int) $seq['atual']);
        if ($url !== '') {
            dm_enviar_midia($db, $cliente, $remetenteId, $url, 'image', 'auto_jogo');
        }
    }
    return ['enviou' => $ok, 'motivo' => 'partida ' . $status];
}

/**
 * Ponto de entrada — chamado pelo webhook a cada mensagem recebida.
 * Retorna ['enviou'=>bool, 'motivo'=>string, 'tratou'=>bool].
 * 'tratou' = a mensagem pertencia ao jogo (o webhook não deve seguir p/ outras respostas).
 */
function jogo_tratar_direct(PDO $db, array $cliente, string $remetenteId, string $texto): array
{
    $nao = ['enviou' => false, 'motivo' => '', 'tratou' => false];
    $cid = (int) $cliente['id'];
    if (!jogo_ativo($cid) || trim($texto) === '') {
        return $nao;
    }

    $q       = jogo_norm($texto);
    $timeout = max(0, (int) pcfg_get($cid, 'dm_jogo_timeout', '60'));
    $partida = jogo_partida_ativa($db, $cid, $remetenteId, $timeout);

    // gatilhos que começam o jogo
    $gat = [];
    foreach (preg_split('/\s*,\s*/', (string) pcfg_get($cid, 'dm_jogo_gatilho', 'forca, jogo da forca')) ?: [] as $g) {
        $g = jogo_norm($g);
        if ($g !== '') {
            $gat[] = $g;
        }
    }
    // ANTI-LOOP: o gatilho só vale em mensagem CURTA. Um texto longo que por acaso
    // contenha "jogo" (aviso, notícia, resposta de outro bot) nunca abre partida.
    $ehGatilho = false;
    if (mb_strlen($q, 'UTF-8') > 40) {
        $gat = [];
    }
    foreach ($gat as $g) {
        if (preg_match('/(^|\s)' . preg_quote($g, '/') . '(\s|$)/u', $q)) {
            $ehGatilho = true;
            break;
        }
    }

    /* ---- 1) Sem partida em andamento ---- */
    if (!$partida) {
        if ($ehGatilho) {
            return jogo_iniciar($db, $cliente, $remetenteId) + ['tratou' => true];
        }
        // "facil" / "medio" / "dificil" -> começa já naquele nível
        $niveis = ['FACIL' => 'facil', 'MEDIO' => 'medio', 'DIFICIL' => 'dificil'];
        if (isset($niveis[$q])) {
            return jogo_iniciar($db, $cliente, $remetenteId, $niveis[$q]) + ['tratou' => true];
        }
        // nome de categoria ("animais", "frutas"...) -> começa naquela categoria
        foreach (array_keys(jogo_categorias()) as $catNome) {
            if ($q === jogo_norm($catNome)) {
                return jogo_iniciar($db, $cliente, $remetenteId, '', $catNome) + ['tratou' => true];
            }
        }
        if ($q === 'CATEGORIAS' || $q === 'TEMAS') {
            $rot = [];
            foreach (jogo_categorias() as $k => $v) {
                $rot[] = (string) ($v['rotulo'] ?? $k) . ' — escreva "' . $k . '"';
            }
            $txt = "🗂️ Categorias do jogo:\n\n" . implode("\n", $rot)
                 . "\n\nOu escolha a dificuldade: facil · medio · dificil\n🎮 \"forca\" sorteia qualquer uma.";
            $env = dm_enviar($db, $cliente, $remetenteId, $txt, 'auto_jogo');
            return ['enviou' => (bool) ($env['ok'] ?? false), 'motivo' => 'categorias', 'tratou' => true];
        }
        if ($q === 'RANKING' || $q === 'PODIO') {
            $env = dm_enviar($db, $cliente, $remetenteId, jogo_ranking_texto($db, $cid, 7), 'auto_jogo');
            return ['enviou' => (bool) ($env['ok'] ?? false), 'motivo' => 'ranking', 'tratou' => true];
        }
        if ($q === 'PLACAR') {
            $pl  = jogo_placar($db, $cid, $remetenteId);
            $txt = "🏆 Seu placar na forca\n\n✅ Vitórias: " . $pl['ganhou']
                 . "\n❌ Derrotas: " . $pl['perdeu']
                 . "\n\n🎮 Manda \"forca\" pra começar uma partida";
            $env = dm_enviar($db, $cliente, $remetenteId, $txt, 'auto_jogo');
            return ['enviou' => (bool) ($env['ok'] ?? false), 'motivo' => 'placar', 'tratou' => true];
        }
        return $nao;
    }

    /* ---- 2) Partida em andamento ---- */
    $letras = (string) $partida['letras'];
    $chave  = (string) $partida['chave'];
    $erros  = (int) $partida['erros'];
    $max    = (int) $partida['max_erros'];
    $limpa  = str_replace([' ', '-'], '', $chave);

    // desistir
    if (preg_match('/^(PARAR|PARA|PARE|DESISTO|DESISTIR|CANCELAR|CANCELA|SAIR|CHEGA|STOP)$/u', $q)) {
        return jogo_encerrar($db, $cliente, $remetenteId, $partida, 'desistiu', '🏳️ Tudo bem, partida encerrada.') + ['tratou' => true];
    }

    // ranking
    if ($q === 'RANKING' || $q === 'PODIO') {
        $env = dm_enviar($db, $cliente, $remetenteId, jogo_ranking_texto($db, $cid, 7), 'auto_jogo');
        return ['enviou' => (bool) ($env['ok'] ?? false), 'motivo' => 'ranking', 'tratou' => true];
    }

    // placar
    if ($q === 'PLACAR') {
        $pl  = jogo_placar($db, $cid, $remetenteId);
        $txt = "🏆 Placar: " . $pl['ganhou'] . ' vitória(s) · ' . $pl['perdeu'] . " derrota(s)\n\n"
             . jogo_painel($partida, 'Partida em andamento:');
        $env = dm_enviar($db, $cliente, $remetenteId, $txt, 'auto_jogo');
        return ['enviou' => (bool) ($env['ok'] ?? false), 'motivo' => 'placar', 'tratou' => true];
    }

    // "dica": REVELA uma letra ainda escondida e custa 1 vida (a dica em texto já veio na abertura)
    if ($q === 'DICA' || $q === 'AJUDA' || $q === 'SOCORRO') {
        $faltando = [];
        $n = mb_strlen($chave, 'UTF-8');
        for ($i = 0; $i < $n; $i++) {
            $c = mb_substr($chave, $i, 1, 'UTF-8');
            if ($c !== ' ' && $c !== '-' && mb_strpos($letras, $c) === false) {
                $faltando[$c] = true;
            }
        }
        $faltando = array_keys($faltando);
        if (!$faltando) {
            $ok = jogo_responder($db, $cliente, $remetenteId, $partida, jogo_painel($partida, '😄 Já está tudo revelado! Chuta a palavra.'));
            return ['enviou' => $ok, 'motivo' => 'dica sem letra', 'tratou' => true];
        }
        $rev    = $faltando[random_int(0, count($faltando) - 1)];
        $letras .= $rev;
        $erros   = min($max, $erros + 1);
        $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_partidas SET letras=?, erros=?, dica_usada=dica_usada+1 WHERE id=?')
           ->execute([$letras, $erros, (int) $partida['id']]);
        $partida['letras']     = $letras;
        $partida['erros']      = $erros;
        $partida['dica_usada'] = (int) $partida['dica_usada'] + 1;

        if (jogo_completou($chave, $letras)) {
            return jogo_encerrar($db, $cliente, $remetenteId, $partida, 'ganhou', '🎉 A dica fechou a palavra! Você escapou.') + ['tratou' => true];
        }
        if ($erros >= $max) {
            return jogo_encerrar($db, $cliente, $remetenteId, $partida, 'perdeu', '💀 A dica custou sua última vida! Enforcou.') + ['tratou' => true];
        }
        $cab = '🆘 Revelei a letra "' . $rev . '" (custou 1 vida ❤️)';
        if (trim((string) $partida['dica']) !== '') {
            $cab .= "\n💡 Lembrando a dica: " . $partida['dica'];
        }
        $ok = jogo_responder($db, $cliente, $remetenteId, $partida, jogo_painel($partida, $cab));
        return ['enviou' => $ok, 'motivo' => 'dica revelou ' . $rev, 'tratou' => true];
    }

    // recomeçar no meio da partida -> mostra o painel atual
    if ($ehGatilho) {
        $ok = jogo_responder($db, $cliente, $remetenteId, $partida, jogo_painel($partida, '🎮 Você já tem uma partida rolando!') . "\n\n👇 Toque numa letra (ou digite).");
        return ['enviou' => $ok, 'motivo' => 'painel', 'tratou' => true];
    }

    /* ---- 2a) Chute de UMA LETRA ---- */
    if (preg_match('/^[A-Z]$/u', $q)) {
        if (mb_strpos($letras, $q) !== false) {
            $ok = jogo_responder($db, $cliente, $remetenteId, $partida, jogo_painel($partida, '🔁 Você já tentou a letra ' . $q . '.'));
            return ['enviou' => $ok, 'motivo' => 'letra repetida', 'tratou' => true];
        }
        $letras .= $q;
        $acertou = mb_strpos($chave, $q) !== false;
        if (!$acertou) {
            $erros++;
        }
        $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_partidas SET letras=?, erros=? WHERE id=?')
           ->execute([$letras, $erros, (int) $partida['id']]);
        $partida['letras'] = $letras;
        $partida['erros']  = $erros;

        if ($acertou && jogo_completou($chave, $letras)) {
            return jogo_encerrar($db, $cliente, $remetenteId, $partida, 'ganhou', '🎉 ACERTOU! Você salvou o bonequinho!') + ['tratou' => true];
        }
        if (!$acertou && $erros >= $max) {
            return jogo_encerrar($db, $cliente, $remetenteId, $partida, 'perdeu', '💀 Enforcou! Acabaram as vidas.') + ['tratou' => true];
        }
        $qtd = mb_substr_count($chave, $q);
        $cab = $acertou
            ? '✅ Boa! Tem ' . $qtd . ' "' . $q . '" na palavra.'
            : '❌ Não tem "' . $q . '".';
        $ok = jogo_responder($db, $cliente, $remetenteId, $partida, jogo_painel($partida, $cab));
        return ['enviou' => $ok, 'motivo' => 'letra ' . $q, 'tratou' => true];
    }

    /* ---- 2b) Chute da PALAVRA inteira (mesmo tamanho) ---- */
    $qLimpa = str_replace([' ', '-'], '', $q);
    if (preg_match('/^[A-Z \-]+$/u', $q) && mb_strlen($qLimpa, 'UTF-8') === mb_strlen($limpa, 'UTF-8')) {
        if ($qLimpa === $limpa) {
            $partida['letras'] = $limpa; // revela tudo
            return jogo_encerrar($db, $cliente, $remetenteId, $partida, 'ganhou', '🎯 MATOU DE PRIMEIRA! Palavra certa!') + ['tratou' => true];
        }
        $erros++;
        $db->prepare('UPDATE ' . DB_PREFIX . 'jogo_partidas SET erros=? WHERE id=?')
           ->execute([$erros, (int) $partida['id']]);
        $partida['erros'] = $erros;
        if ($erros >= $max) {
            return jogo_encerrar($db, $cliente, $remetenteId, $partida, 'perdeu', '💀 Errou o chute e enforcou!') + ['tratou' => true];
        }
        $ok = jogo_responder($db, $cliente, $remetenteId, $partida, jogo_painel($partida, '❌ Não é "' . $q . '". Perdeu 1 vida! 💔'));
        return ['enviou' => $ok, 'motivo' => 'chute errado', 'tratou' => true];
    }

    /* ---- 2c) Mensagem curta que não é jogada -> lembrete (as outras automações
             continuam funcionando para textos longos, tipo pergunta de ônibus) ---- */
    if (mb_strlen($q, 'UTF-8') <= 20) {
        $ok = jogo_responder($db, $cliente, $remetenteId, $partida, jogo_painel($partida, '🤔 Manda só UMA LETRA (ou a palavra inteira).'));
        return ['enviou' => $ok, 'motivo' => 'lembrete', 'tratou' => true];
    }
    return $nao;
}
