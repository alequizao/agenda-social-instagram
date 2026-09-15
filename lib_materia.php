<?php
declare(strict_types=1);

/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
/**
 * lib_materia.php
 * Scraper SEM IA: abre o link da matéria e extrai foto(s), título, lead e fonte.
 * Usado pela geração de arte por código (alternativa à imagem da OpenAI).
 *
 * Depende de noticias_baixar() (lib_noticias.php) para o GET com cURL.
 */

require_once __DIR__ . '/lib_noticias.php';

/* Resolve uma URL possivelmente relativa contra a base da página. */
function materia_url_abs(string $url, string $base): string
{
    $url = trim($url);
    if ($url === '' || preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $p = parse_url($base);
    if (!$p || empty($p['scheme']) || empty($p['host'])) {
        return $url;
    }
    $raiz = $p['scheme'] . '://' . $p['host'];
    if (strpos($url, '//') === 0) {
        return $p['scheme'] . ':' . $url;
    }
    if (strpos($url, '/') === 0) {
        return $raiz . $url;
    }
    $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
    return $raiz . $dir . $url;
}

/* Pega o conteúdo de uma meta tag (property OU name), sem depender de ordem dos atributos. */
function materia_meta(string $html, string $chave): string
{
    foreach (['property', 'name', 'itemprop'] as $attr) {
        if (preg_match('#<meta[^>]+' . $attr . '=["\']' . preg_quote($chave, '#') . '["\'][^>]*>#i', $html, $tag)) {
            if (preg_match('#content=["\']([^"\']*)["\']#i', $tag[0], $m)) {
                return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }
        }
    }
    return '';
}

/**
 * Extrai dados da matéria a partir do link.
 * @return array ['ok'=>bool,'titulo'=>,'subtitulo'=>,'imagem'=>,'imagens'=>[],'fonte'=>,'data'=>,'erro'=>]
 */
function materia_extrair(string $url, string $fonteFallback = '', string $tituloFallback = ''): array
{
    $url = trim($url);
    if ($url === '') {
        return ['ok' => false, 'erro' => 'Link da matéria vazio.'];
    }
    $html = noticias_baixar($url);
    if ($html === null || $html === '') {
        return ['ok' => false, 'erro' => 'Não consegui baixar a página da matéria.'];
    }

    // ---- título ----
    $titulo = materia_meta($html, 'og:title');
    if ($titulo === '' && preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) {
        $titulo = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
    if ($titulo === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
        $titulo = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
    }
    if ($titulo === '') {
        $titulo = $tituloFallback;
    }

    // ---- subtítulo / lead ----
    $sub = materia_meta($html, 'og:description');
    if ($sub === '') {
        $sub = materia_meta($html, 'description');
    }

    // ---- data ----
    $data = materia_meta($html, 'article:published_time');
    if ($data === '') {
        $data = materia_meta($html, 'og:updated_time');
    }

    // ---- imagens (og:image + secundárias do corpo) ----
    $imgs = [];
    foreach (['og:image:secure_url', 'og:image', 'twitter:image', 'twitter:image:src'] as $k) {
        $v = materia_meta($html, $k);
        if ($v !== '') {
            $imgs[] = materia_url_abs($v, $url);
        }
    }
    // <link rel="image_src">
    if (preg_match('#<link[^>]+rel=["\']image_src["\'][^>]*href=["\']([^"\']+)["\']#i', $html, $m)) {
        $imgs[] = materia_url_abs($m[1], $url);
    }
    // imagens grandes no corpo do artigo
    if (preg_match('#<article[\s\S]*?</article>#i', $html, $art)) {
        if (preg_match_all('#<img[^>]+src=["\']([^"\']+)["\']#i', $art[0], $mm)) {
            foreach ($mm[1] as $src) {
                $abs = materia_url_abs($src, $url);
                if ($abs !== '' && !preg_match('#\.svg(\?|$)#i', $abs)) {
                    $imgs[] = $abs;
                }
            }
        }
    }
    // dedup mantendo ordem
    $imgs = array_values(array_unique(array_filter($imgs)));

    $fonteHost = $fonteFallback;
    if ($fonteHost === '') {
        $fonteHost = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        $fonteHost = preg_replace('#^www\.#i', '', $fonteHost);
    }

    return [
        'ok'        => $titulo !== '' || !empty($imgs),
        'titulo'    => $titulo,
        'subtitulo' => $sub,
        'imagem'    => $imgs[0] ?? '',
        'imagens'   => $imgs,
        'fonte'     => $fonteHost,
        'data'      => $data,
        'erro'      => ($titulo === '' && empty($imgs)) ? 'Página sem título/imagem reconhecíveis.' : '',
    ];
}

/* Baixa a 1a imagem que abrir como GD válida, na ordem dada. Retorna recurso GD ou null. */
function materia_baixar_imagem(array $urls)
{
    foreach ($urls as $u) {
        if (!is_string($u) || $u === '') {
            continue;
        }
        $bin = noticias_baixar($u);
        if (!is_string($bin) || $bin === '') {
            continue;
        }
        $img = @imagecreatefromstring($bin);
        if ($img !== false) {
            return $img;
        }
    }
    return null;
}
