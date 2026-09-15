<?php
/*
 * Agenda Social · Desenvolvido por Alequizao <alequizao.dev@gmail.com>
 * https://github.com/alequizao · © 2026 Alequizao. Todos os direitos reservados.
 */
require __DIR__ . '/init.php';
exigir_login();

$id = (int) ($_GET['id'] ?? 0);
$editando = false;
$c = ['id' => 0, 'nome' => '', 'ig_username' => '', 'cor_marca' => '#168bf5', 'foto_perfil' => ''];

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM ' . DB_PREFIX . 'clientes WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        header('Location: ' . BASE_URL . '/dashboard.php');
        exit;
    }
    $c = $row;
    $editando = true;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $erro = 'Sessao expirada. Recarregue a pagina.';
    } else {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $igUser = ltrim(trim((string) ($_POST['ig_username'] ?? '')), '@');
        $cor = cor_hex($_POST['cor_marca'] ?? null);
        $c['nome'] = $nome;
        $c['ig_username'] = $igUser;
        $c['cor_marca'] = $cor;

        if ($nome === '') {
            $erro = 'Informe o nome do cliente.';
        } else {
            $slug = slugify($nome);

            // foto (opcional)
            $foto = $editando ? ($c['foto_perfil'] ?? null) : null;
            if (!empty($_FILES['foto_perfil']['name'])) {
                $nova = salvar_avatar($_FILES['foto_perfil'], $slug);
                if ($nova === null) {
                    $erro = 'A foto precisa ser JPG, PNG ou WEBP (ate 5 MB).';
                } else {
                    if (!empty($foto) && is_file(__DIR__ . '/' . $foto)) {
                        @unlink(__DIR__ . '/' . $foto);
                    }
                    $foto = $nova;
                }
            }

            if ($erro === '') {
                if ($editando) {
                    $up = db()->prepare('UPDATE ' . DB_PREFIX . 'clientes
                        SET nome=?, slug=?, ig_username=?, cor_marca=?, foto_perfil=? WHERE id=?');
                    $up->execute([$nome, $slug, $igUser ?: null, $cor, $foto, $id]);
                } else {
                    $ins = db()->prepare('INSERT INTO ' . DB_PREFIX . 'clientes
                        (nome, slug, ig_username, cor_marca, foto_perfil) VALUES (?,?,?,?,?)');
                    $ins->execute([$nome, $slug, $igUser ?: null, $cor, $foto]);
                    $id = (int) db()->lastInsertId();
                }
                header('Location: ' . BASE_URL . '/cliente_instagram.php?id=' . $id);
                exit;
            }
        }
    }
}

$page_title = $editando ? 'Editar cliente' : 'Novo cliente';
require __DIR__ . '/partials/head.php';
?>
<main class="wrap">
  <a class="back" href="dashboard.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>
    Voltar para clientes
  </a>

  <div class="page-head">
    <div>
      <h1><?= $editando ? 'Editar cliente' : 'Novo cliente' ?></h1>
      <p>Depois de salvar voce conecta o Instagram dele.</p>
    </div>
  </div>

  <?php if ($erro !== ''): ?>
    <div class="alert" role="alert" style="max-width:560px;"><?= e($erro) ?></div>
  <?php endif; ?>

  <form class="form-card" method="post" enctype="multipart/form-data"
        action="cliente_form.php<?= $editando ? '?id=' . (int) $id : '' ?>">
    <?= csrf_field() ?>

    <div class="field">
      <label>Foto de perfil (opcional)</label>
      <div class="avatar-pick">
        <div class="avatar-pick__preview" id="avPreview"
             style="background:linear-gradient(135deg,<?= e($c['cor_marca']) ?>,#000)">
          <?php if (!empty($c['foto_perfil']) && is_file(__DIR__ . '/' . $c['foto_perfil'])): ?>
            <img src="<?= e(asset_v($c['foto_perfil'])) ?>" alt="" id="avImg">
          <?php else: ?>
            <span id="avIni"><?= e(iniciais($c['nome'] ?: '?')) ?></span>
          <?php endif; ?>
        </div>
        <input type="file" name="foto_perfil" id="foto_perfil" accept="image/jpeg,image/png,image/webp">
      </div>
    </div>

    <div class="field">
      <label for="nome">Nome do cliente</label>
      <input type="text" id="nome" name="nome" required maxlength="120"
             value="<?= e($c['nome']) ?>" placeholder="Ex.: Padaria do Joao">
    </div>

    <div class="form-row cols-2">
      <div class="field">
        <label for="ig_username">@ do Instagram (opcional)</label>
        <input type="text" id="ig_username" name="ig_username"
               value="<?= e($c['ig_username'] ?? '') ?>" placeholder="padariadojoao">
      </div>
      <div class="field">
        <label for="cor_marca">Cor da marca</label>
        <div class="field-color">
          <input type="color" id="cor_marca" name="cor_marca" value="<?= e($c['cor_marca'] ?: '#168bf5') ?>">
          <span class="field-color__hex" id="corHex"><?= e($c['cor_marca'] ?: '#168bf5') ?></span>
        </div>
      </div>
    </div>

    <div class="form-actions" style="margin-top:8px;">
      <button type="submit" class="btn btn--auto"><?= $editando ? 'Salvar' : 'Criar e conectar Instagram' ?></button>
      <a class="btn-ghost" href="dashboard.php">Cancelar</a>
    </div>
  </form>
</main>

<script>
(function () {
  var cor = document.getElementById('cor_marca');
  var hex = document.getElementById('corHex');
  var prev = document.getElementById('avPreview');
  var file = document.getElementById('foto_perfil');
  if (cor) cor.addEventListener('input', function () {
    hex.textContent = cor.value;
    prev.style.background = 'linear-gradient(135deg,' + cor.value + ',#000)';
  });
  if (file) file.addEventListener('change', function () {
    var f = (file.files || [])[0];
    if (!f) return;
    var url = URL.createObjectURL(f);
    prev.innerHTML = '<img src="' + url + '" alt="">';
  });
})();
</script>
<?php require __DIR__ . '/partials/foot.php'; ?>
