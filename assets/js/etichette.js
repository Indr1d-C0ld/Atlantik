/* Nomi sulla carta — scritti a mano, non generati.
 *
 * Natural Earth porta le geometrie, non i nomi: una carta senza nomi non e'
 * una carta. Le posizioni sono scelte dove il nome ci sta senza coprire
 * niente, come si fa in tipografia cartografica.
 *
 * grande: true  → nome di continente, spaziato e in grassetto
 * mare:   true  → nome di mare o di braccio di mare: corsivo, inchiostro dell'acqua
 */
/* Nomi delle terre: Natural Earth non li porta, e su una carta servono.
 * Posizione scelta a mano, dove il nome ci sta senza coprire niente. */
window.ATL_ETICHETTE = [
  { nome: 'NORD AMERICA', lat: 44.0, lon: -95.0, grande: true },
  { nome: 'SUD AMERICA',  lat: -8.0, lon: -60.0, grande: true },
  { nome: 'EUROPA',       lat: 48.5, lon:  15.0, grande: true },
  { nome: 'AFRICA',       lat: 18.0, lon:   8.0, grande: true },
  { nome: 'GROENLANDIA',  lat: 72.0, lon: -42.0, grande: true },
  { nome: 'Islanda',      lat: 64.9, lon: -18.6 },
  { nome: 'Irlanda',      lat: 53.4, lon:  -8.4 },
  { nome: 'Gran Bretagna',lat: 54.4, lon:  -2.2 },
  { nome: 'Terranova',    lat: 48.7, lon: -56.2 },
  { nome: 'Labrador',     lat: 54.5, lon: -62.0 },
  { nome: 'Spagna',       lat: 40.3, lon:  -4.0 },
  { nome: 'Portogallo',   lat: 39.6, lon:  -8.2 },
  { nome: 'Francia',      lat: 46.5, lon:   2.5 },
  { nome: 'Marocco',      lat: 31.5, lon:  -6.5 },
  { nome: 'Mar dei Caraibi',    lat:  15.0, lon: -73.0, mare: true },
  { nome: 'Mare del Nord',      lat:  56.5, lon:   3.5, mare: true },
  { nome: 'Golfo di Biscaglia', lat:  45.3, lon:  -5.5, mare: true },
  { nome: 'Canale di San Giorgio', lat: 51.8, lon: -6.2, mare: true },
  { nome: 'Approcci occidentali',  lat: 50.5, lon: -14.0, mare: true },
  { nome: 'Stretto di Danimarca',  lat: 66.5, lon: -27.0, mare: true }
];

/* Isole minori: al largo sono punti di riferimento anche quando sulla carta
 * sono grandi come una capocchia di spillo. Il segno resta, il nome pure. */
window.ATL_ISOLE = [
  { nome: 'Azzorre',   lat: 37.8, lon: -25.5 },
  { nome: 'Madera',    lat: 32.7, lon: -16.9 },
  { nome: 'Canarie',   lat: 28.3, lon: -16.5 },
  { nome: 'Capo Verde',lat: 15.1, lon: -23.6 },
  { nome: 'Faer Oer',  lat: 62.0, lon:  -6.8 },
  { nome: 'Bermuda',   lat: 32.3, lon: -64.8 },
  { nome: 'Shetland',  lat: 60.3, lon:  -1.3 },
  { nome: 'Ascension', lat: -7.9, lon: -14.4 },
  { nome: "Sant'Elena",lat: -15.9,lon:  -5.7 }
];
