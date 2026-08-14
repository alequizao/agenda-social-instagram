<?php if (usuario_logado()): ?>
</div><!-- /.content -->
<script>
  (function () {
    var body = document.body,
        ham  = document.getElementById('hamburger'),
        ov   = document.getElementById('overlay');
    function toggle() { body.classList.toggle('sidebar-open'); }
    function close()  { body.classList.remove('sidebar-open'); }
    if (ham) { ham.addEventListener('click', toggle); }
    if (ov)  { ov.addEventListener('click', close); }

    var tb = document.getElementById('themeToggle');
    if (tb) {
      tb.addEventListener('click', function () {
        var atual = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        var novo  = atual === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', novo);
        try { localStorage.setItem('tema', novo); } catch (e) {}
      });
    }
  })();
</script>
<?php endif; ?>
</body>
</html>
