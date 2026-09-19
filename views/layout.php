<?php
/** @var string $content @var string $title */
$illum = $illum ?? '';
?>
<!doctype html>
<html lang="it"<?= $illum !== '' ? ' data-illum="' . e($illum) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<title><?= e($title ?? 'Atlantik') ?> · Atlantik</title>
<meta name="description" content="Atlantik — simulazione multigiocatore persistente della Battaglia dell'Atlantico.">
<link rel="stylesheet" href="<?= e(asset('css/atlantik.css')) ?>">
<link rel="manifest" href="<?= e(root_url('manifest.webmanifest')) ?>">
<link rel="icon" href="<?= e(asset('img/icona.svg')) ?>" type="image/svg+xml">
<meta name="theme-color" content="#151a1d">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Atlantik">
</head>
<body data-incontro-url="<?= e(url('/api/incontro')) ?>" data-base="<?= e((string) ($GLOBALS['__base_path'] ?? '')) ?>"<?= ($sfondo ?? '') !== '' ? ' data-sfondo="' . e($sfondo) . '"' : '' ?>>

<header class="plancia-top">
  <div class="top-wrap">
    <?php
    // L'etichetta di stato (p.es. "beta chiusa") sta in una chiave di
    // configurazione da sempre, e da sempre non la leggeva nessuno: si poteva
    // cambiarla dal pannello senza che comparisse da nessuna parte.
    $etichettaStato = trim((string) \App\Core\GameConfig::get('app.nome_beta', ''));
    ?>
    <a class="marchio" href="<?= e(url('/')) ?>" style="border:0">
      <b>Atlantik</b>
      <?php if ($etichettaStato !== ''): ?>
        <em class="marchio-stato"><?= e($etichettaStato) ?></em>
      <?php endif; ?>
      <span>Schlacht im Atlantik</span>
    </a>
    <nav class="nav-top">
      <?php if (auth_check()): ?>
        <span style="color:var(--testo-3)"><?= e(auth_user()['username'] ?? '') ?></span>
        <a href="<?= e(url('/base')) ?>">Base</a>
        <a href="#" id="interruttore-audio" title="Suoni di bordo">audio ○</a>
        <a href="#" id="interruttore-avvisi" title="Avvisi del browser, solo a pagina aperta">avvisi ○</a>
        <?php if (is_admin()): ?><a href="<?= e(url('/admin')) ?>">Admin</a><?php endif; ?>
        <form method="post" action="<?= e(url('/esci')) ?>" style="display:inline">
          <?= csrf_field() ?>
          <button class="bottone--fantasma" style="padding:.3rem .8rem;font-size:.72rem">Esci</button>
        </form>
      <?php else: ?>
        <a href="<?= e(url('/accesso')) ?>">Accesso</a>
        <a href="<?= e(url('/arruolamento')) ?>">Arruolamento</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main>
<?= partial('flash') ?>
<?= $content ?>
</main>

<footer>
  <div class="footer-wrap">
    <span>Atlantik — simulazione storica. Progetto personale, nessun fine commerciale.</span>
    <span><a href="<?= e(url('/statistiche')) ?>">Statistiche</a> · <a href="<?= e(url('/albo')) ?>">Albo d'oro</a></span>
    <span class="sep">Ora di Roma: <?= e(fmt_dt(time())) ?></span>
  </div>
</footer>

<script src="<?= e(asset('js/bordo.js')) ?>" defer></script>
<script src="<?= e(asset('js/emblemi.js')) ?>" defer></script>
</body>
</html>
