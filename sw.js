/* Service worker di Atlantik.
 *
 * Non tenta di far funzionare il gioco senza rete — un mondo persistente senza
 * server non e' un gioco, e' una bugia. Mette in cache solo il guscio (fogli di
 * stile, script, icone) perche' l'applicazione si apra in fretta e perche'
 * installata sul telefono si comporti da applicazione; quando manca la rete
 * mostra una pagina che lo dice chiaramente.
 */
const CACHE = 'atlantik-guscio-v9';
const GUSCIO = [
  'assets/css/atlantik.css',
  'assets/js/plancia.js',
  'assets/js/carta.js',
  'assets/js/coste.js',
  'assets/js/etichette.js',
  'assets/img/carta-atlantico-piccola.webp',
  'assets/js/rosa.js',
  'assets/js/attacco.js',
  'assets/js/bordo.js',
  'assets/img/icona.svg',
  'offline.html',
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(GUSCIO)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((chiavi) => Promise.all(chiavi.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') { return; }

  const url = new URL(req.url);
  const statico = /\.(css|js|svg|png|webmanifest)$/.test(url.pathname);

  if (statico) {
    // Guscio: prima la RETE, poi la cache.
    //
    // Il contrario sarebbe piu' svelto, ed e' come stava prima. Ma questo e' un
    // progetto che cambia tutti i giorni, e un foglio di stile o un file di
    // coste vecchio di una settimana non e' "veloce": e' sbagliato. Gli
    // indirizzi portano gia' la versione (asset() ci mette il filemtime),
    // quindi la rete risponde comunque 304 quando non e' cambiato niente.
    //
    // La cache resta la rete di salvataggio: senza collegamento si serve quello
    // che c'e', ignorando la parte di versione dell'indirizzo — offline e'
    // meglio una carta di ieri che nessuna carta.
    e.respondWith(
      fetch(req)
        .then((res) => {
          const copia = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copia));
          return res;
        })
        .catch(() => caches.match(req).then((hit) => hit || caches.match(req, { ignoreSearch: true })))
    );
    return;
  }

  // Tutto il resto viene dal server: il mondo e' li'.
  e.respondWith(
    fetch(req).catch(() => caches.match('offline.html').then((hit) => hit || new Response(
      'Atlantik richiede la rete: il mondo gira sul server.',
      { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } }
    )))
  );
});
