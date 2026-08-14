<?php
declare(strict_types=1);

/**
 * migracao_curadoria.php — Mesa de Redação / modo redator-chefe.
 * Idempotente (MySQL 5.7): adiciona colunas de classificação editorial em
 * `noticias_descobertas` e o estado "aprovada" no fluxo, sem quebrar dados.
 *
 * Rodar uma vez (CLI ou web logado):  php migracao_curadoria.php
 */

require __DIR__ . '/init.php';

$db = db();
$out = [];

/* Helper: a coluna existe? */
$temCol = function (string $tabela, string $coluna) use ($db): bool {
    $st = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$tabela, $coluna]);
    return (int) $st->fetchColumn() > 0;
};

/* 1) Colunas de classificação em noticias_descobertas */
$colunas = [
    'tema'            => "VARCHAR(24) NULL AFTER status",            // crime|csa_crb|onibus|evento|social|''
    'veredito'        => "VARCHAR(16) NULL AFTER tema",              // na_linha|fora|duvida
    'motivo'          => "VARCHAR(180) NULL AFTER veredito",         // explicação do filtro
    'auto'            => "TINYINT(1) NOT NULL DEFAULT 0 AFTER motivo", // 1 = posta sozinha (ônibus)
    'fonte_filtro'    => "VARCHAR(12) NULL AFTER auto",              // palavras|ia
    'classificado_em' => "DATETIME NULL AFTER fonte_filtro",
];
foreach ($colunas as $col => $def) {
    if (!$temCol('noticias_descobertas', $col)) {
        $db->exec('ALTER TABLE ' . DB_PREFIX . 'noticias_descobertas ADD COLUMN ' . $col . ' ' . $def);
        $out[] = "coluna noticias_descobertas.$col criada";
    } else {
        $out[] = "coluna noticias_descobertas.$col já existe";
    }
}

/* 2) Estado "aprovada" no enum de status (nova|gerada|ignorada|aprovada) */
$st = $db->query("SHOW COLUMNS FROM " . DB_PREFIX . "noticias_descobertas LIKE 'status'");
$tipoStatus = (string) ($st->fetch()['Type'] ?? '');
if (stripos($tipoStatus, "'aprovada'") === false) {
    $db->exec("ALTER TABLE " . DB_PREFIX . "noticias_descobertas
        MODIFY COLUMN status ENUM('nova','gerada','ignorada','aprovada') NOT NULL DEFAULT 'nova'");
    $out[] = "status enum estendido com 'aprovada'";
} else {
    $out[] = "status enum já tem 'aprovada'";
}

/* 3) Índice para consultas da Mesa de Redação (idempotente) */
$idx = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
$idx->execute(['noticias_descobertas', 'idx_curadoria']);
if ((int) $idx->fetchColumn() === 0) {
    $db->exec('ALTER TABLE ' . DB_PREFIX . 'noticias_descobertas
        ADD INDEX idx_curadoria (cliente_id, status, veredito)');
    $out[] = "índice idx_curadoria criado";
} else {
    $out[] = "índice idx_curadoria já existe";
}

/* 3b) custos_ia.tipo aceita 'filtro' (custo do "filtro do filtro" por IA) */
$ct = (string) ($db->query("SHOW COLUMNS FROM " . DB_PREFIX . "custos_ia LIKE 'tipo'")->fetch()['Type'] ?? '');
if ($ct !== '' && stripos($ct, "'filtro'") === false) {
    $db->exec("ALTER TABLE " . DB_PREFIX . "custos_ia
        MODIFY COLUMN tipo ENUM('imagem','texto','filtro') NOT NULL DEFAULT 'texto'");
    $out[] = "custos_ia.tipo agora aceita 'filtro'";
} else {
    $out[] = "custos_ia.tipo já aceita 'filtro' (ou ausente)";
}

/* 4) Defaults globais de segurança */
if (cfg_get('pub_cap_dia', null) === null) {
    cfg_set('pub_cap_dia', '40');
    $out[] = "pub_cap_dia=40 (trava dura diária) definido";
}

foreach ($out as $l) {
    echo " - $l\n";
}
echo "Migração de curadoria concluída.\n";
