<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * lib_ia.php
 * Integracao com a OpenAI para o modulo de Noticias:
 *   - ia_legenda():       reescreve a manchete numa legenda (modelo de TEXTO)
 *   - ia_gerar_imagem():  gera a "cena no onibus" (gpt-image)
 * Toda chamada cobrada grava 1 linha em `custos_ia`.
 *
 * IMPORTANTE (confiabilidade): o modelo de texto so REESCREVE o que veio do RSS
 * (titulo/resumo). Nao inventa fato. A manchete real e a fonte sao gravadas na
 * arte por cima (ver lib_arte.php), entao a noticia fica correta mesmo com a arte gerada.
 */

/* ---- Tabela de precos por 1.000 tokens (USD) p/ estimar custo de TEXTO ---- */
function ia_precos_texto(): array
{
    return [
        'gpt-4o-mini' => ['in' => 0.00015, 'out' => 0.00060],
        'gpt-4o'      => ['in' => 0.00250, 'out' => 0.01000],
        'gpt-4.1-mini'=> ['in' => 0.00040, 'out' => 0.00160],
    ];
}

/* ---- POST JSON generico para a API da OpenAI ---- */
function openai_post(string $path, array $payload): array
{
    if (OPENAI_API_KEY === '') {
        return ['ok' => false, 'erro' => 'OPENAI_API_KEY nao configurada no .env.'];
    }
    $url = rtrim(OPENAI_BASE, '/') . '/' . ltrim($path, '/');
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
    ]);
    $resp  = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $resp === false) {
        return ['ok' => false, 'erro' => 'Falha de conexao com a OpenAI: ' . $err];
    }
    $json = json_decode((string) $resp, true);
    if (!is_array($json)) {
        return ['ok' => false, 'erro' => 'Resposta invalida da OpenAI (HTTP ' . $code . ').'];
    }
    if (isset($json['error'])) {
        return ['ok' => false, 'erro' => (string) ($json['error']['message'] ?? 'Erro da OpenAI.')];
    }
    if ($code < 200 || $code >= 300) {
        return ['ok' => false, 'erro' => 'OpenAI HTTP ' . $code . '.'];
    }
    return ['ok' => true, 'dados' => $json];
}

/* ---- Registra um custo ---- */
function ia_registrar_custo(PDO $db, string $tipo, string $modelo, string $descricao, int $tin, int $tout, float $usd, ?int $pubId = null): void
{
    $db->prepare('INSERT INTO ' . DB_PREFIX . 'custos_ia
        (publicacao_id, tipo, modelo, descricao, tokens_in, tokens_out, custo_usd)
        VALUES (?,?,?,?,?,?,?)')
       ->execute([$pubId, $tipo, $modelo, mb_substr($descricao, 0, 200), $tin, $tout, round($usd, 6)]);
}

/* ---- Reescreve a manchete numa legenda de Instagram (PT-BR) ----
   $clienteId > 0 usa a config DO PERFIL (config_ia salva por perfil); o valor
   global fica só de fallback — antes lia SÓ o global e ignorava o perfil (bug). */
function ia_legenda(PDO $db, array $noticia, int $clienteId = 0): array
{
    $modelo = $clienteId > 0
        ? (string) pcfg_get($clienteId, 'ia_modelo_texto', (string) cfg_get('ia_modelo_texto', 'gpt-4o-mini'))
        : (string) cfg_get('ia_modelo_texto', 'gpt-4o-mini');
    $fonte  = (string) ($noticia['fonte'] ?? '');
    $titulo = (string) ($noticia['titulo'] ?? '');
    $resumo = (string) ($noticia['resumo'] ?? '');

    $sys = "Voce e o social media do perfil @tevinobuzao, que mostra noticias de Maceio e Alagoas. "
         . "Reescreva a noticia recebida numa legenda curta e envolvente para o Instagram, em portugues do Brasil. "
         . "REGRAS: seja 100% fiel ao titulo/resumo fornecidos; NAO invente fatos, numeros, nomes ou datas; "
         . "se faltar informacao, seja generico em vez de inventar. 2 a 4 linhas curtas, tom jornalistico e direto, "
         . "1 ou 2 emojis no maximo, mencione o perfil @tevinobuzao ao final e use 3 a 5 hashtags locais (ex.: #Maceio #Alagoas). "
         . "NAO inclua links nem a palavra 'Fonte' (isso e adicionado depois).";
    $user = "TITULO: {$titulo}\nRESUMO: {$resumo}\nFONTE: {$fonte}";

    $r = openai_post('chat/completions', [
        'model'       => $modelo,
        'messages'    => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $user],
        ],
        'temperature' => 0.7,
        'max_tokens'  => 320,
    ]);

    if (!$r['ok']) {
        return ['ok' => false, 'erro' => $r['erro'], 'texto' => ''];
    }

    $texto = trim((string) ($r['dados']['choices'][0]['message']['content'] ?? ''));
    $tin   = (int) ($r['dados']['usage']['prompt_tokens'] ?? 0);
    $tout  = (int) ($r['dados']['usage']['completion_tokens'] ?? 0);

    $p = ia_precos_texto()[$modelo] ?? ['in' => 0.0, 'out' => 0.0];
    $usd = ($tin / 1000) * $p['in'] + ($tout / 1000) * $p['out'];

    ia_registrar_custo($db, 'texto', $modelo, 'legenda: ' . $titulo, $tin, $tout, $usd);

    if ($texto === '') {
        return ['ok' => false, 'erro' => 'Legenda vazia da OpenAI.', 'texto' => ''];
    }
    return ['ok' => true, 'texto' => $texto, 'usd' => $usd];
}

/* =====================================================================
   PROMPT DA CENA  (POV "lendo por cima do ombro" num onibus de Maceio)
   ---------------------------------------------------------------------
   O cenario e SEMPRE o mesmo (reproduz fielmente a foto de referencia):
   onibus urbano de Maceio, barras/corrimaos AMARELOS, bancos azuis
   estampados, passageiro a frente de costas, janelas grandes com a orla,
   adesivo azul "EMBARQUE SOMENTE COM CARTAO" e painel de LED ambar.
   A cada nova imagem mudam APENAS duas coisas:
     1) o HORARIO do dia (e a iluminacao correspondente);
     2) a PESSOA que segura o celular (genero / idade / aparencia).
   A materia (foto + manchete) aparece SEMPRE na tela do celular.
   ===================================================================== */
function ia_prompt_cena(array $noticia): string
{
    $titulo = trim((string) ($noticia['titulo'] ?? 'noticias de Maceio'));
    $titulo = mb_substr($titulo, 0, 140);
    // aspas tipograficas atrapalham o render do texto na tela -> normaliza
    $titulo = str_replace(['"', '"', '"', '“', '”'], '', $titulo);

    /* ---- VARIACAO 1: pessoa que segura o celular (genero / idade / aparencia) ---- */
    $pessoas = [
        'uma MULHER JOVEM de uns 22 anos, pele clara, unhas bem feitas e um smartwatch escuro no pulso',
        'um HOMEM JOVEM de uns 24 anos, pele morena, antebraco com leve pelo, sem acessorios',
        'uma MULHER ADULTA de uns 38 anos, pele parda, alianca dourada no anelar e uma pulseira fina',
        'um HOMEM ADULTO de uns 42 anos, pele clara levemente bronzeada, relogio analogico simples no pulso',
        'uma MULHER NEGRA de uns 30 anos, cabelo cacheado volumoso, unhas com esmalte colorido',
        'um HOMEM NEGRO de uns 28 anos, corte de cabelo baixo, pulseira de miçangas no pulso',
        'uma SENHORA IDOSA de uns 66 anos, maos com a pele marcada pelo tempo, aliancas e oculos de leitura',
        'um SENHOR IDOSO de uns 70 anos, maos enrugadas e firmes, relogio de pulseira de couro',
        'uma ESTUDANTE UNIVERSITARIA de uns 20 anos, cordao de cracha no pescoco e um fone de ouvido pendurado',
        'um ADOLESCENTE de uns 16 anos, pele jovem, capinha de celular colorida e chamativa',
        'uma TRABALHADORA de uniforme bege com cracha preso na blusa, maos cuidadas',
        'um TRABALHADOR de uniforme azul de servico, maos firmes de quem trabalha o dia inteiro',
    ];

    /* ---- VARIACAO 2: horario do dia + iluminacao correspondente ---- */
    $momentos = [
        [
            'hora' => 'no inicio da manha, por volta das 6h30',
            'luz'  => 'luz suave e dourada do amanhecer entrando de lado pelas janelas, ceu rosado e alaranjado sobre o mar, sombras longas, atmosfera tranquila e ar levemente enevoado',
        ],
        [
            'hora' => 'no meio da manha, por volta das 9h',
            'luz'  => 'luz natural clara e brilhante de dia, ceu azul limpo, cores vibrantes e nitidas, sombras curtas e bem definidas',
        ],
        [
            'hora' => 'ao meio-dia, por volta das 12h',
            'luz'  => 'sol forte e alto a pino, luz dura e contrastada entrando pelas janelas, reflexos intensos no vidro e no metal, cores saturadas e calor visivel',
        ],
        [
            'hora' => 'no comeco da tarde, por volta das 15h',
            'luz'  => 'luz de tarde quente e estavel, ceu azul com poucas nuvens, tons levemente amarelados, sombras medias',
        ],
        [
            'hora' => 'no fim da tarde (golden hour), por volta das 17h30',
            'luz'  => 'luz dourada e quente da hora magica entrando rasante pelas janelas, ceu alaranjado, brilho ambar batendo no rosto e nas maos da pessoa, clima nostalgico',
        ],
        [
            'hora' => 'no por do sol, por volta das 18h45',
            'luz'  => 'ceu em degrade laranja, rosa e roxo sobre o mar de Maceio, sol baixo no horizonte, interior do onibus em penumbra azulada e a tela do celular comecando a ser a luz mais forte da cena',
        ],
        [
            'hora' => 'a noite, por volta das 20h',
            'luz'  => 'interior do onibus iluminado pelas luzes brancas/frias do teto, janelas escuras refletindo o interior e mostrando ao fundo postes e luzes da cidade na orla, a TELA DO CELULAR e a fonte de luz mais brilhante e ilumina o rosto e as maos da pessoa com um brilho frio',
        ],
    ];

    $p = $pessoas[array_rand($pessoas)];
    $m = $momentos[array_rand($momentos)];

    /* =================== PROMPT (positivo, enorme e detalhado) =================== */
    $prompt =
        // --- Tipo de imagem / camera / ponto de vista ---
        "Fotografia REALISTA e amadora, estilo foto espontanea tirada com a camera de um smartphone (iPhone), "
        . "com leve grao, leve ruido de ISO e profundidade de campo natural. "
        . "Enquadramento VERTICAL 9:16, formato de Story do Instagram. "
        . "PONTO DE VISTA EM PRIMEIRA PESSOA (POV): a cena e vista como se VOCE estivesse sentado logo ATRAS e "
        . "olhando POR CIMA DO OMBRO de {$p}, dentro de um onibus urbano de Maceio/Alagoas (Brasil), em movimento. "
        . "No canto inferior e lateral do quadro aparece, levemente desfocado e em primeiro plano, "
        . "o OMBRO e o BRACO dessa pessoa, dando a sensacao real de espiar a tela dela. "

        // --- Horario / iluminacao (VARIA) ---
        . "A cena acontece {$m['hora']}. ILUMINACAO: {$m['luz']}. "

        // --- O celular e a TELA (foco principal, a materia aparece aqui) ---
        . "{$p} segura um smartphone moderno com as duas maos, na altura do peito, levemente inclinado, "
        . "com a TELA DE FRENTE para a camera (de chapa, bem visivel, formato retangular completo). "
        . "O CELULAR esta em FOCO NITIDO e bem iluminado no centro do quadro, ocupando boa parte da imagem. "
        . "MUITO IMPORTANTE: a TELA DO CELULAR esta COMPLETAMENTE VERDE, um verde chroma key solido e uniforme (#00e000), "
        . "totalmente em branco, SEM absolutamente nenhum texto, icone, foto, reflexo ou brilho na tela. "
        . "A unica tela acesa e visivel na cena e a desse celular (verde solido). "

        // --- Cenario FIXO do onibus (reproduz a referencia) ---
        . "AMBIENTE (mantenha sempre assim): interior de um onibus urbano de Maceio. "
        . "Barras, corrimaos e o pegador vertical do banco da frente sao AMARELOS. "
        . "Os bancos sao AZUIS com estampa colorida tipica de transporte publico. "
        . "Logo a frente, do outro lado do corredor estreito, ha OUTRO PASSAGEIRO sentado, visto DE COSTAS, "
        . "usando uma camiseta clara/cinza, a cabeca levemente baixa. "
        . "As janelas sao grandes e mostram, do lado de fora, a ORLA de Maceio: o mar azul-esverdeado, a faixa de areia, "
        . "coqueiros/palmeiras e predios da cidade ao fundo passando em movimento. "
        . "Em um dos vidros ha um ADESIVO/PLACA AZUL com a logo da prefeitura e os dizeres \"EMBARQUE SOMENTE COM CARTAO\". "
        . "No alto, proximo ao teto, ha um PAINEL DE LED de cor AMBAR/LARANJA exibindo, em letras de pontos (estilo matriz de LED) "
        . "e perfeitamente legivel, exatamente o texto: @tevinobuzao (com o arroba). "
        . "Esse painel ocupa o lugar onde normalmente apareceria o destino/linha do onibus; deve mostrar SO o @tevinobuzao, sem nome de avenida. "

        // --- Pessoa em primeiro plano (detalhe da roupa que aparece embaixo) ---
        . "No canto inferior do quadro, parte do corpo da pessoa que segura o celular aparece desfocada (ombro e antebraco), "
        . "vestindo uma blusa de mangas com detalhe esportivo em listras nas cores azul, branco e vermelho, reforcando o POV. "

        // --- Direcao de arte / acabamento ---
        . "Composicao autentica e documental, nada de estudio, nada de pose; parece um print real do dia a dia. "
        . "Cores naturais, contraste realista, leve reflexo do vidro do onibus. "

        // --- Bloco de "evitar" (a API de geracao nao tem campo negative_prompt,
        //     entao as restricoes ficam no proprio texto do prompt) ---
        . "EVITE: QUALQUER texto, icone, foto, app, reflexo ou brilho sobre a tela do celular (ela deve ficar 100% VERDE LISA e uniforme); "
        . "qualquer outra tela, monitor ou televisao alem do celular; marcas d'agua, logos da OpenAI ou assinaturas; "
        . "maos deformadas, dedos a mais ou a menos (mantenha 5 dedos por mao); rostos de pessoas reais reconheciveis; "
        . "aparencia de render 3D, ilustracao, desenho ou cartoon (a foto deve parecer 100% real).";

    return $prompt;
}

/* ---- Gera a imagem da cena. Retorna ['ok'=>,'png'=>binario,'erro'=>] ----
   $clienteId > 0 usa modelo/tamanho/qualidade DO PERFIL (com fallback no global). */
function ia_gerar_imagem(PDO $db, array $noticia, int $clienteId = 0): array
{
    if ($clienteId > 0) {
        $modelo    = (string) pcfg_get($clienteId, 'ia_modelo_imagem', (string) cfg_get('ia_modelo_imagem', 'gpt-image-1'));
        $tamanho   = (string) pcfg_get($clienteId, 'ia_tamanho_imagem', (string) cfg_get('ia_tamanho_imagem', '1024x1536'));
        $qualidade = (string) pcfg_get($clienteId, 'ia_qualidade_imagem', (string) cfg_get('ia_qualidade_imagem', 'medium'));
    } else {
        $modelo    = (string) cfg_get('ia_modelo_imagem', 'gpt-image-1');
        $tamanho   = (string) cfg_get('ia_tamanho_imagem', '1024x1536');
        $qualidade = (string) cfg_get('ia_qualidade_imagem', 'medium');
    }
    $prompt    = ia_prompt_cena($noticia);

    $r = openai_post('images/generations', [
        'model'   => $modelo,
        'prompt'  => $prompt,
        'size'    => $tamanho,
        'quality' => $qualidade,
        'n'       => 1,
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'erro' => $r['erro']];
    }

    $b64 = (string) ($r['dados']['data'][0]['b64_json'] ?? '');
    if ($b64 === '') {
        // alguns modelos retornam URL em vez de base64
        $imgUrl = (string) ($r['dados']['data'][0]['url'] ?? '');
        if ($imgUrl !== '') {
            $bin = noticias_baixar($imgUrl); // helper de lib_noticias.php (cURL)
            if ($bin !== null) {
                $custo = (float) cfg_get('ia_preco_imagem_usd', '0.04');
                $tin = (int) ($r['dados']['usage']['input_tokens'] ?? 0);
                $tout = (int) ($r['dados']['usage']['output_tokens'] ?? 0);
                ia_registrar_custo($db, 'imagem', $modelo, 'cena: ' . ($noticia['titulo'] ?? ''), $tin, $tout, $custo);
                return ['ok' => true, 'png' => $bin];
            }
        }
        return ['ok' => false, 'erro' => 'OpenAI nao retornou imagem.'];
    }

    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '') {
        return ['ok' => false, 'erro' => 'Imagem invalida (base64).'];
    }

    // custo: preco configurado por imagem (fallback fiel) + tokens reais se vierem
    $custo = (float) cfg_get('ia_preco_imagem_usd', '0.04');
    $tin   = (int) ($r['dados']['usage']['input_tokens'] ?? 0);
    $tout  = (int) ($r['dados']['usage']['output_tokens'] ?? 0);
    ia_registrar_custo($db, 'imagem', $modelo, 'cena: ' . ($noticia['titulo'] ?? ''), $tin, $tout, $custo);

    return ['ok' => true, 'png' => $bin];
}

/* ---- Total de custo em USD num intervalo (NULL = tudo) ---- */
function ia_custo_total(PDO $db, ?string $desde = null): float
{
    if ($desde) {
        $st = $db->prepare('SELECT COALESCE(SUM(custo_usd),0) FROM ' . DB_PREFIX . 'custos_ia WHERE criado_em >= ?');
        $st->execute([$desde]);
    } else {
        $st = $db->query('SELECT COALESCE(SUM(custo_usd),0) FROM ' . DB_PREFIX . 'custos_ia');
    }
    return (float) $st->fetchColumn();
}
