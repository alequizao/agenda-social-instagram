<?php
require __DIR__ . '/init.php';

if (usuario_logado()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $erro = 'Sessao expirada. Tente de novo.';
    } elseif (login_bloqueado()) {
        $erro = 'Muitas tentativas. Aguarde alguns minutos.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $senha = (string) ($_POST['senha'] ?? '');
        if (tentar_login($email, $senha)) {
            limpar_tentativas();
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
        registrar_tentativa_falha();
        $erro = 'E-mail ou senha invalidos.';
    }
}

$page_title = 'Entrar';
require __DIR__ . '/partials/head.php';
?>
<div class="auth">
  <div class="auth__left">
    <div class="auth__card">
      <div class="brand">
        <span class="brand__mark"><i class="fa-solid fa-calendar-days"></i></span>
        <span class="brand__txt">
          <span class="brand__name"><?= e(APP_NAME) ?></span>
          <span class="brand__sub">Mesa de Redação · Maceió</span>
        </span>
      </div>

      <h1 class="auth__title">Olá! Entre com a sua conta</h1>
      <p class="auth__hint">Acesse para gerenciar os agendamentos.</p>

      <?php if ($erro !== ''): ?>
        <div class="alert" role="alert"><?= e($erro) ?></div>
      <?php endif; ?>

      <form method="post" action="login.php" autocomplete="on">
        <?= csrf_field() ?>
        <div class="field">
          <label for="email">Usuário</label>
          <input type="text" id="email" name="email" required autofocus autocapitalize="none" spellcheck="false"
                 value="<?= e($_POST['email'] ?? '') ?>" placeholder="agendamentos">
        </div>
        <div class="field">
          <label for="senha">Senha</label>
          <div class="pw-field">
            <input type="password" id="senha" name="senha" required placeholder="Sua senha">
            <button type="button" class="pw-toggle" id="pwToggle" aria-label="Mostrar senha"><i class="fa-solid fa-eye"></i></button>
          </div>
        </div>
        <button type="submit" class="btn">Entrar</button>
      </form>

      <p class="auth__foot"><?= e(APP_NAME) ?> &middot; v<?= e(defined('APP_VERSAO') ? APP_VERSAO : '3.7.0') ?><br>
        <span style="opacity:.7">Desenvolvido por <?= e(DEV_NOME) ?> &middot; <?= e(DEV_FONE) ?></span></p>
    </div>
  </div>

  <div class="auth__right">
    <div class="auth__right-in">
      <i class="fa-brands fa-instagram"></i>
      <h2>Agende e publique no Instagram no piloto automático</h2>
      <p>Calendário editorial, prévia das publicações e envio automático pela API oficial da Meta.</p>
    </div>
  </div>
</div>

<script>
  (function () {
    var t = document.getElementById('pwToggle'),
        s = document.getElementById('senha');
    if (t && s) {
      t.addEventListener('click', function () {
        var show = s.type === 'password';
        s.type = show ? 'text' : 'password';
        t.innerHTML = show ? '<i class="fa-solid fa-eye-slash"></i>' : '<i class="fa-solid fa-eye"></i>';
      });
    }
  })();
</script>
<?php require __DIR__ . '/partials/foot.php'; ?>
