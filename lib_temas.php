<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * lib_temas.php — Classificador editorial (Mesa de Redação).
 *
 * A LINHA EDITORIAL do @tevinobuzao:
 *   crime   -> ocorrências policiais de Maceió/Alagoas
 *   csa_crb -> futebol LOCAL (CSA e CRB). Seleção/Copa/global fica de fora.
 *   onibus  -> transporte público / mobilidade de Maceió  (POSTA SOZINHO)
 *   evento  -> eventos na cidade de Maceió
 *   social  -> ações sociais / solidárias
 *
 * FORA DA LINHA (bloqueado): atriz/ator global, novela, reality, futebol
 *   nacional/internacional, loteria, assuntos aleatórios.
 *
 * Filtro em 2 camadas ("filtro do filtro"):
 *   1) palavras-chave (grátis) -> define tema + veredito (na_linha|fora|duvida)
 *   2) IA (OpenAI) só nas "dúvidas" -> confirma se é da linha e qual tema.
 *
 * Decisão final é SEMPRE do redator-chefe na tela noticias.php (Mesa de Redação).
 */

require_once __DIR__ . '/lib_ia.php';

/* ---- Normaliza: minúsculo, sem acento, só [a-z0-9 ] ---- */
function tema_norm(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
    ]);
    $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s) ?? '';
    return ' ' . preg_replace('/\s+/', ' ', trim($s)) . ' ';
}

/* ---- $texto já normalizado contém a palavra/frase $kw? (casa palavra inteira) ---- */
function tema_contem(string $textoNorm, string $kw): bool
{
    $kw = trim($kw);
    if ($kw === '') {
        return false;
    }
    // sempre exige limite de palavra (texto vem padronizado com espaços nas pontas)
    return mb_strpos($textoNorm, ' ' . $kw . ' ') !== false;
}

/* ---- $texto contém algum RADICAL (substring segura p/ conjugações)? ---- */
function tema_contem_raiz(string $textoNorm, string $raiz): bool
{
    $raiz = trim($raiz);
    return $raiz !== '' && mb_strpos($textoNorm, $raiz) !== false;
}

/* Pontua um tema: palavras inteiras (kw) + radicais (raiz). */
function tema_pontuar(string $textoNorm, array $def): int
{
    $n = 0;
    foreach (($def['kw'] ?? $def) as $kw) {
        if (tema_contem($textoNorm, $kw)) {
            $n++;
        }
    }
    foreach (($def['raiz'] ?? []) as $raiz) {
        if (tema_contem_raiz($textoNorm, $raiz)) {
            $n++;
        }
    }
    return $n;
}

/* ---- Dicionário dos TEMAS da linha editorial ---- */
function temas_definicoes(): array
{
    return [
        'onibus' => [
            'rotulo' => 'Ônibus / Mobilidade',
            'icone'  => 'fa-bus',
            'cor'    => '#0ea5e9',
            'kw' => [
                'onibus', 'transporte publico', 'transporte coletivo', 'transporte urbano',
                'tarifa', 'passagem de onibus', 'ponto de onibus', 'parada de onibus',
                'terminal de integracao', 'terminal rodoviario', 'rodoviaria', 'linha de onibus',
                'sttp', 'smtt', 'semob', 'scmt', 'superintendencia municipal de transporte',
                'brt', 'vlt', 'bilhete unico', 'cartao do onibus', 'cartao de transporte',
                'frota de onibus', 'mobilidade urbana', 'motorista de onibus', 'coletivo urbano',
                'integracao de onibus', 'gratuidade no transporte', 'meia passagem',
            ],
            'raiz' => ['onibus', 'rodoviari'],
        ],
        'crime' => [
            'rotulo' => 'Crimes / Polícia',
            'icone'  => 'fa-handcuffs',
            'cor'    => '#ef4444',
            'kw' => [
                'preso', 'presa', 'presos', 'prisao', 'detido', 'detida', 'assalto', 'assaltante',
                'roubo', 'roubou', 'roubada', 'furto', 'furtou', 'homicidio', 'assassinado',
                'assassinada', 'assassinato', 'morto a tiro', 'morto a tiros', 'baleado', 'baleada',
                'tiroteio', 'tiros', 'esfaqueado', 'esfaqueada', 'facada', 'latrocinio', 'estupro',
                'estuprador', 'feminicidio', 'agrediu', 'agressao', 'espancou', 'espancada',
                'trafico', 'traficante', 'drogas', 'maconha', 'crack', 'cocaina', 'apreendido',
                'apreensao', 'operacao policial', 'policia', 'policiais', 'policial', 'delegacia',
                'delegado', 'bandido', 'suspeito', 'criminoso', 'sequestro', 'refem', 'chacina',
                'corpo encontrado', 'cadaver', 'arma de fogo', 'foragido', 'pm', 'denuncia',
                'violencia domestica', 'ameaca de morte', 'golpe', 'estelionato', 'flagrante',
            ],
            // radicais seguros p/ conjugações (preso/presa, roubam/roubou, agride/agrediu...)
            'raiz' => [
                'assassin', 'homicid', 'feminicid', 'latrocin', 'tirote', 'esfaque', 'estupr',
                'sequestr', 'traficant', 'apreens', 'apreendi', 'espanc', 'assalt', 'roub',
                'balead', 'foragid', 'chacin', 'facada', 'agredi', 'agress', 'flagrant',
                'detid', 'homicida', 'criminos', 'narcotrafic',
            ],
        ],
        'csa_crb' => [
            'rotulo' => 'CSA / CRB',
            'icone'  => 'fa-futbol',
            'cor'    => '#16a34a',
            'kw' => [
                'csa', 'crb', 'azulao', 'galo da praca', 'centro sportivo alagoano',
                'clube de regatas brasil', 'regatas brasil', 'rei do estado',
                'estadio rei pele', 'estadio resende', 'mutange', 'pajucara fc',
            ],
        ],
        'evento' => [
            'rotulo' => 'Eventos em Maceió',
            'icone'  => 'fa-calendar-star',
            'cor'    => '#a855f7',
            'kw' => [
                'evento', 'eventos', 'festival', 'show', 'shows', 'feira', 'exposicao',
                'festa', 'programacao', 'agenda cultural', 'forro', 'sao joao', 'festejos juninos',
                'arraia', 'arraial', 'micareta', 'carnaval', 'reveillon', 'virada do ano',
                'espetaculo', 'congresso', 'seminario', 'workshop', 'festividade', 'comemoracao',
                'jornada', 'mostra', 'circuito', 'orla de maceio', 'parque', 'desfile',
            ],
        ],
        'social' => [
            'rotulo' => 'Ações sociais',
            'icone'  => 'fa-hand-holding-heart',
            'cor'    => '#f59e0b',
            'kw' => [
                'acao social', 'acoes sociais', 'doacao', 'doacoes', 'campanha solidaria',
                'mutirao', 'solidaria', 'solidariedade', 'voluntario', 'voluntaria', 'voluntarios',
                'arrecadacao', 'arrecadar', 'ong', 'asilo', 'abrigo', 'agasalho', 'cesta basica',
                'doacao de sangue', 'hemoal', 'bazar beneficente', 'projeto social', 'mutirao de',
                'pessoas em situacao de rua', 'inclusao social', 'amparo', 'acolhimento',
                'feira de adocao', 'adocao de animais', 'castracao gratuita',
            ],
        ],
    ];
}

/* ---- Termos FORA DA LINHA (bloqueio) por categoria ---- */
function temas_bloqueio(): array
{
    return [
        'celebridade/novela/reality' => [
            'novela', 'novelas', 'bbb', 'big brother', 'a fazenda', 'reality', 'realities',
            'ex bbb', 'famoso', 'famosa', 'famosos', 'celebridade', 'celebridades',
            'influencer', 'influenciadora', 'influenciador', 'ator', 'atriz', 'atores', 'atrizes',
            'rede globo', 'novela das', 'protagonista da novela', 'apresentadora', 'apresentador',
            'oscar', 'grammy', 'met gala', 'hollywood', 'paparazzi', 'affair', 'ex affair',
            'sertanejo', 'funkeiro', 'cantor revela', 'cantora revela', 'sua nova musica',
        ],
        'futebol nacional/global' => [
            'selecao brasileira', 'copa do mundo', 'copa 2026', 'neymar', 'fifa', 'uefa',
            'champions league', 'liga dos campeoes', 'premier league', 'la liga', 'nba', 'nfl',
            'formula 1', 'real madrid', 'barcelona', 'flamengo', 'corinthians', 'palmeiras',
            'sao paulo fc', 'brasileirao serie a', 'mundial de clubes', 'eliminatorias',
        ],
        'assunto aleatório/global' => [
            'mega sena', 'megasena', 'loteria', 'loterias', 'quina', 'lotofacil', 'horoscopo',
            'signos', 'signo', 'zodiaco', 'estados unidos', 'eua', 'holanda', 'ucrania', 'russia',
            'israel', 'palestina', 'gaza', 'china', 'franca', 'eutanasia', 'nasa', 'marte',
            'casa branca', 'donald trump', 'putin', 'papa', 'vaticano',
        ],
    ];
}

/* Sinais de contexto LOCAL (Maceió/Alagoas) — usados p/ decidir "dúvida". */
function tema_eh_local(string $textoNorm): bool
{
    foreach (['maceio', 'alagoas', 'arapiraca', ' al ', 'alagoano', 'alagoana', 'macioense',
              'litoral norte', 'sertao alagoano', 'agreste alagoano', 'jaragua', 'pajucara',
              'ponta verde', 'benedito bentes', 'tabuleiro', 'serraria'] as $loc) {
        if (mb_strpos($textoNorm, $loc) !== false) {
            return true;
        }
    }
    return false;
}

/* Quais temas estão LIGADOS na linha editorial deste perfil (default: todos). */
function temas_ativos(int $clienteId): array
{
    $ativos = [];
    foreach (array_keys(temas_definicoes()) as $t) {
        if ((string) pcfg_get($clienteId, 'ia_tema_' . $t, '1') === '1') {
            $ativos[] = $t;
        }
    }
    return $ativos;
}

function temas_rotulo(?string $tema): string
{
    if ($tema === null || $tema === '') {
        return '—';
    }
    return temas_definicoes()[$tema]['rotulo'] ?? $tema;
}

/**
 * CAMADA 1 — classificação por palavras-chave.
 * @return array ['tema','veredito','motivo','auto','confianca']
 *   veredito: na_linha | fora | duvida ; confianca: alta | baixa
 */
function tema_classificar_palavras(string $titulo, string $resumo, int $clienteId): array
{
    // a manchete decide; o resumo é só desempate fraco (vira "dúvida")
    $tituloN = tema_norm($titulo);
    $fullN   = tema_norm($titulo . ' ' . $resumo);
    $defs = temas_definicoes();
    $ativos = temas_ativos($clienteId);

    $melhorTema = ''; $ptsTit = 0;   // melhor tema pela MANCHETE
    $melhorTemaF = ''; $ptsFull = 0; // melhor tema pelo texto completo
    foreach ($ativos as $t) {
        $pt = tema_pontuar($tituloN, $defs[$t]);
        if ($pt > $ptsTit) { $ptsTit = $pt; $melhorTema = $t; }
        $pf = tema_pontuar($fullN, $defs[$t]);
        if ($pf > $ptsFull) { $ptsFull = $pf; $melhorTemaF = $t; }
    }

    $blocoMotivo = ''; $blocoTit = 0; $blocoFull = 0;
    foreach (temas_bloqueio() as $cat => $kws) {
        $bt = tema_pontuar($tituloN, ['kw' => $kws]);
        $bf = tema_pontuar($fullN, ['kw' => $kws]);
        if ($bt > $blocoTit) { $blocoTit = $bt; }
        if ($bf > $blocoFull) { $blocoFull = $bf; $blocoMotivo = $cat; }
    }

    $mk = fn(string $tema, string $ver, string $mot) => [
        'tema' => $tema, 'veredito' => $ver, 'motivo' => $mot,
        'auto' => ($tema === 'onibus' && $ver === 'na_linha') ? 1 : 0,
        'confianca' => $ver === 'duvida' ? 'baixa' : 'alta',
    ];

    // 1) Bloqueio NA MANCHETE manda (celebridade/novela/global na manchete = fora)
    if ($blocoTit > 0 && $blocoTit >= $ptsTit) {
        return $mk('', 'fora', 'Fora da linha: ' . $blocoMotivo);
    }
    // 2) Tema NA MANCHETE = entra na linha
    if ($ptsTit > 0) {
        return $mk($melhorTema, 'na_linha', 'Tema: ' . ($defs[$melhorTema]['rotulo'] ?? $melhorTema));
    }
    // 3) Manchete neutra: bloqueio no texto -> fora
    if ($blocoFull > 0) {
        return $mk('', 'fora', 'Fora da linha: ' . $blocoMotivo);
    }
    // 4) Tema só no resumo -> dúvida (IA/editor confirma o enquadramento)
    if ($ptsFull > 0) {
        return $mk($melhorTemaF, 'duvida', 'Possível ' . ($defs[$melhorTemaF]['rotulo'] ?? $melhorTemaF) . ' (revisar)');
    }
    // 5) Nada: se for local, dúvida; senão, fora
    if (tema_eh_local($fullN)) {
        return $mk('', 'duvida', 'Local, sem tema claro — revisar');
    }
    return $mk('', 'fora', 'Sem relação com a linha editorial');
}

/**
 * CAMADA 2 — IA confirma só as DÚVIDAS. Retorna tema/veredito refinados.
 * Barata: 1 chamada chat curtíssima. Loga custo. Falha = mantém 'duvida'.
 */
function tema_classificar_ia(PDO $db, array $noticia, int $clienteId): array
{
    $ativos = temas_ativos($clienteId);
    $defs = temas_definicoes();
    $listaTemas = [];
    foreach ($ativos as $t) {
        $listaTemas[] = $t . ' = ' . ($defs[$t]['rotulo'] ?? $t);
    }
    $modelo = (string) pcfg_get($clienteId, 'ia_modelo_texto', 'gpt-4o-mini');

    $sys = "Voce e o redator-chefe de um perfil de noticias de Maceio/Alagoas. "
         . "Classifique a noticia em UM tema da linha editorial OU marque como fora. "
         . "TEMAS DA LINHA: " . implode('; ', $listaTemas) . ". "
         . "FORA DA LINHA: atriz/ator/novela/reality/celebridade, futebol nacional ou internacional "
         . "(selecao, Copa, Neymar, clubes de fora de Alagoas), loteria e assuntos aleatorios/globais. "
         . "Futebol so entra se for CSA ou CRB. Responda APENAS um JSON: "
         . '{"tema":"<um dos temas ou vazio>","na_linha":true|false}.';
    $user = "TITULO: " . (string) ($noticia['titulo'] ?? '') . "\nRESUMO: " . (string) ($noticia['resumo'] ?? '');

    $r = openai_post('chat/completions', [
        'model' => $modelo,
        'messages' => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0,
        'max_tokens' => 40,
        'response_format' => ['type' => 'json_object'],
    ]);
    if (!$r['ok']) {
        return ['ok' => false];
    }
    $tin  = (int) ($r['dados']['usage']['prompt_tokens'] ?? 0);
    $tout = (int) ($r['dados']['usage']['completion_tokens'] ?? 0);
    $p = ia_precos_texto()[$modelo] ?? ['in' => 0.0, 'out' => 0.0];
    $usd = ($tin / 1000) * $p['in'] + ($tout / 1000) * $p['out'];
    ia_registrar_custo($db, 'filtro', $modelo, 'filtro tema: ' . (string) ($noticia['titulo'] ?? ''), $tin, $tout, $usd);

    $j = json_decode((string) ($r['dados']['choices'][0]['message']['content'] ?? ''), true);
    if (!is_array($j)) {
        return ['ok' => false];
    }
    $tema = (string) ($j['tema'] ?? '');
    $naLinha = !empty($j['na_linha']) && in_array($tema, $ativos, true);
    if ($naLinha) {
        return [
            'ok' => true,
            'tema' => $tema,
            'veredito' => 'na_linha',
            'motivo' => 'IA confirmou: ' . temas_rotulo($tema),
            'auto' => ($tema === 'onibus') ? 1 : 0,
        ];
    }
    return ['ok' => true, 'tema' => '', 'veredito' => 'fora', 'motivo' => 'IA: fora da linha editorial', 'auto' => 0];
}

/**
 * Classifica as notícias 'nova' ainda sem veredito (camada 1; IA nas dúvidas se ligado).
 * Chamado pelo cron e pela tela. Limita IA por execução p/ não estourar custo/tempo.
 * @return array ['classificadas'=>int,'ia'=>int]
 */
function temas_classificar_pendentes(PDO $db, int $clienteId, int $limite = 120): array
{
    $usarIA = (string) pcfg_get($clienteId, 'ia_filtro_ia', '1') === '1' && OPENAI_API_KEY !== '';
    $maxIA  = max(0, (int) pcfg_get($clienteId, 'ia_filtro_ia_max', '15'));

    $st = $db->prepare('SELECT id, titulo, resumo, fonte, link, imagem
        FROM ' . DB_PREFIX . 'noticias_descobertas
        WHERE cliente_id = ? AND status = "nova" AND veredito IS NULL
        ORDER BY id DESC LIMIT ' . (int) $limite);
    $st->execute([$clienteId]);
    $rows = $st->fetchAll();
    if (!$rows) {
        return ['classificadas' => 0, 'ia' => 0];
    }

    $up = $db->prepare('UPDATE ' . DB_PREFIX . 'noticias_descobertas
        SET tema = ?, veredito = ?, motivo = ?, auto = ?, fonte_filtro = ?, classificado_em = NOW()
        WHERE id = ?');

    $n = 0; $usadosIA = 0;
    foreach ($rows as $r) {
        $c = tema_classificar_palavras((string) $r['titulo'], (string) $r['resumo'], $clienteId);
        $fonteFiltro = 'palavras';

        if ($c['veredito'] === 'duvida' && $usarIA && $usadosIA < $maxIA) {
            $ia = tema_classificar_ia($db, [
                'titulo' => (string) $r['titulo'],
                'resumo' => (string) $r['resumo'],
            ], $clienteId);
            $usadosIA++;
            if (!empty($ia['ok'])) {
                $c['tema'] = $ia['tema'];
                $c['veredito'] = $ia['veredito'];
                $c['motivo'] = $ia['motivo'];
                $c['auto'] = $ia['auto'];
                $fonteFiltro = 'ia';
            }
        }

        $up->execute([
            $c['tema'] !== '' ? $c['tema'] : null,
            $c['veredito'],
            mb_substr((string) $c['motivo'], 0, 180),
            (int) $c['auto'],
            $fonteFiltro,
            (int) $r['id'],
        ]);
        $n++;
    }
    return ['classificadas' => $n, 'ia' => $usadosIA];
}
