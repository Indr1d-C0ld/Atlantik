<?php /** @var string $attiva */ ?>
<nav class="nav-plancia">
  <a href="<?= e(url('/admin')) ?>"<?= ($attiva ?? '') === 'pannello' ? ' class="attivo"' : '' ?>>Pannello</a>
  <a href="<?= e(url('/admin/utenti')) ?>"<?= ($attiva ?? '') === 'utenti' ? ' class="attivo"' : '' ?>>Utenti</a>
  <a href="<?= e(url('/admin/accessi')) ?>"<?= ($attiva ?? '') === 'accessi' ? ' class="attivo"' : '' ?>>Accessi</a>
  <a href="<?= e(url('/admin/comunicazioni')) ?>"<?= ($attiva ?? '') === 'comunicazioni' ? ' class="attivo"' : '' ?>>Comunicazioni</a>
  <a href="<?= e(url('/admin/classifica')) ?>"<?= ($attiva ?? '') === 'classifica' ? ' class="attivo"' : '' ?>>Classifica</a>
  <a href="<?= e(url('/admin/registro')) ?>"<?= ($attiva ?? '') === 'registro' ? ' class="attivo"' : '' ?>>Registro</a>
  <a href="<?= e(url('/admin/mondo')) ?>"<?= ($attiva ?? '') === 'mondo' ? ' class="attivo"' : '' ?>>Mondo</a>
  <a href="<?= e(url('/admin/carta')) ?>"<?= ($attiva ?? '') === 'carta_admin' ? ' class="attivo"' : '' ?>>Carta ammiraglia</a>
</nav>
