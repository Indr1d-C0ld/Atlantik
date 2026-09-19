<?php

declare(strict_types=1);

namespace App\Sim;

/**
 * Nomi di navi mercantili, per bandiera.
 *
 * I britannici battezzavano i piroscafi di guerra con i prefissi "Empire" e
 * "Fort" (questi ultimi costruiti in Canada), oltre ai nomi di citta', fiumi e
 * contee delle compagnie private. Gli americani davano alle Liberty nomi di
 * personaggi storici. Norvegesi e greci portavano nomi di casa. Sono
 * combinazioni generate: nessuna nave reale e' riprodotta di proposito.
 */
final class ShipNames
{
    // --- Britanniche: navi di serie ------------------------------------------
    //
    // Il programma "Empire" battezzava i piroscafi requisiti o costruiti per il
    // Ministry of War Transport con un secondo elemento preso da un vocabolario
    // larghissimo: uccelli, mestieri, virtu', luoghi. Qui si fa lo stesso, ed e'
    // il motivo per cui lo spazio dei nomi basta a tenere duemila navi in mare
    // senza che due si chiamino uguale.

    private const EMPIRE = [
        'Dawn', 'Ridge', 'Falcon', 'Harrier', 'Bison', 'Lancer', 'Warrior', 'Trader', 'Mariner', 'Beacon',
        'Comet', 'Crusader', 'Gallant', 'Heron', 'Kestrel', 'Lightning', 'Mistral', 'Panther', 'Rambler', 'Sailor',
        'Standard', 'Thunder', 'Valour', 'Voyager', 'Wanderer', 'Yeoman', 'Chapman', 'Drury', 'Merlin', 'Norseman',
        'Archer', 'Baron', 'Bittern', 'Bowman', 'Brigade', 'Buckler', 'Camp', 'Capulet', 'Chieftain', 'Cormorant',
        'Council', 'Cutlass', 'Dabchick', 'Darwin', 'Diamond', 'Dolphin', 'Drake', 'Eagle', 'Endurance', 'Ensign',
        'Faith', 'Fletcher', 'Forester', 'Fortune', 'Galahad', 'Garrison', 'Gauntlet', 'Glade', 'Grebe', 'Halberd',
        'Hawk', 'Herald', 'Highway', 'Hope', 'Hurst', 'Javelin', 'Kingfisher', 'Knight', 'Lapwing', 'Lea',
        'Liberty', 'Linnet', 'Mallard', 'Marshal', 'Meteor', 'Miner', 'Moon', 'Moorhen', 'Nightingale', 'Osprey',
        'Otter', 'Paladin', 'Pathfinder', 'Peregrine', 'Pilgrim', 'Pintail', 'Plover', 'Puma', 'Quiver', 'Ranger',
        'Raven', 'Redshank', 'Regent', 'Rook', 'Rowan', 'Sentinel', 'Shearwater', 'Skylark', 'Spearhead', 'Squire',
        'Starling', 'Steelhead', 'Stronghold', 'Swan', 'Talisman', 'Tern', 'Trident', 'Tulip', 'Unity', 'Vanguard',
        'Venture', 'Vigilance', 'Wagtail', 'Warden', 'Whimbrel', 'Widgeon', 'Yarn', 'Zephyr',
    ];

    private const FORT = [
        'Albany', 'Beaver', 'Cadotte', 'Douglas', 'Erie', 'Frontenac', 'Garry', 'Hudson', 'Kaskaskia', 'La Reine',
        'Maisonneuve', 'Nashwaak', 'Ontario', 'Pelly', 'Qu\'Appelle', 'Richelieu', 'Steele', 'Tremblant', 'Vermilion', 'Yale',
        'Assiniboine', 'Babine', 'Battleford', 'Brunswick', 'Caribou', 'Carillon', 'Chambly', 'Charnisay', 'Chilcotin',
        'Churchill', 'Confidence', 'Crevecoeur', 'Cumberland', 'Dearborn', 'Drew', 'Fairford', 'Gibraltar', 'Good Hope',
        'Highfield', 'Howe', 'Kootenay', 'Lajoie', 'Livingstone', 'Longueuil', 'Massac', 'McMurray', 'Mumford',
        'Nipigon', 'Norfolk', 'Paskoyac', 'Rouille', 'Saint James', 'Selkirk', 'Simcoe', 'Stikine', 'Ticonderoga',
        'Vercheres', 'Wallace', 'Yukon',
    ];

    private const PARK = [
        'Algonquin', 'Banff', 'Beaton', 'Cape Breton', 'Cedar', 'Champlain', 'Dentonia', 'Dundurn', 'Eglinton',
        'Elgin', 'Gatineau', 'Green Hill', 'Hastings', 'Jasper', 'Kildonan', 'Laurentide', 'Mohawk', 'Nemiskam',
        'Outremont', 'Prince Rupert', 'Riding Mountain', 'Rockcliffe', 'Strathcona', 'Taber', 'Wellington', 'Westmount',
    ];

    private const OCEAN = [
        'Angel', 'Athlete', 'Courage', 'Courier', 'Faith', 'Freedom', 'Gallant', 'Gypsy', 'Honour', 'Hunter',
        'Justice', 'Liberty', 'Might', 'Pilgrim', 'Rider', 'Seaman', 'Splendour', 'Strength', 'Trader', 'Traveller',
        'Valour', 'Vanguard', 'Venture', 'Verity', 'Vesper', 'Viceroy', 'Vigil', 'Viking', 'Virtue', 'Voyager',
    ];

    // --- Britanniche: compagnie private ---------------------------------------
    //
    // Le compagnie usavano schemi riconoscibili: la Clan Line metteva "Clan" e un
    // clan scozzese, la Ellerman "City of" e una citta', la Hogarth "Baron" e un
    // titolo. Altre chiudevano tutti i loro piroscafi con la stessa desinenza —
    // -pool, -dale, -hall, -moor — su un ceppo di luogo.

    private const CLAN = [
        'Alpine', 'Buchanan', 'Cameron', 'Campbell', 'Chattan', 'Colquhoun', 'Cumming', 'Douglas', 'Farquhar',
        'Ferguson', 'Forbes', 'Fraser', 'Gordon', 'Graham', 'Grant', 'Kennedy', 'Lamont', 'Leslie', 'Macaulay',
        'Macarthur', 'Macbean', 'Macdonald', 'Macdougall', 'Macfadyen', 'Macinnes', 'Mackay', 'Mackenzie',
        'Maclean', 'Macnab', 'Macpherson', 'Macquarrie', 'Matheson', 'Menzies', 'Murdoch', 'Ogilvy', 'Ramsay',
        'Robertson', 'Ross', 'Shaw', 'Skene', 'Stuart', 'Sutherland', 'Urquhart',
    ];

    private const CITTA = [
        'Athens', 'Bath', 'Benares', 'Birmingham', 'Bombay', 'Bradford', 'Cairo', 'Calcutta', 'Canterbury',
        'Cardiff', 'Christchurch', 'Corinth', 'Dundee', 'Durban', 'Edinburgh', 'Exeter', 'Florence', 'Hankow',
        'Karachi', 'Keelung', 'Lancaster', 'Lincoln', 'Manchester', 'Marseilles', 'Nagpur', 'Oran', 'Oxford',
        'Perth', 'Pretoria', 'Rangoon', 'Roubaix', 'Shanghai', 'Simla', 'Singapore', 'Stafford', 'Swansea',
        'Sydney', 'Venice', 'Windsor', 'Winchester', 'York',
    ];

    private const BARONE = [
        'Ailsa', 'Belhaven', 'Blythswood', 'Cawdor', 'Carnegie', 'Dechmont', 'Elgin', 'Erskine', 'Forbes',
        'Graham', 'Haig', 'Herries', 'Inchcape', 'Jedburgh', 'Kelvin', 'Kinnaird', 'Loudoun', 'Minto',
        'Nairn', 'Newlands', 'Ogilvy', 'Pentland', 'Ramsay', 'Renfrew', 'Ruthven', 'Saltoun', 'Semple',
        'Tweedsmuir', 'Vernon', 'Yarborough',
    ];

    /** Ceppi di luogo per i nomi a desinenza fissa. */
    private const CEPPO = [
        'Alder', 'Ash', 'Barn', 'Beech', 'Birk', 'Black', 'Bram', 'Bridge', 'Brook', 'Clift', 'Cold', 'Cran',
        'Dal', 'Dun', 'Elm', 'Fair', 'Farn', 'Fern', 'Ford', 'Glen', 'Green', 'Grey', 'Hart', 'Hazel', 'High',
        'Holm', 'Kirk', 'Lang', 'Lea', 'Linden', 'Marl', 'Moss', 'Nether', 'New', 'Oak', 'Old', 'Pen', 'Raven',
        'Red', 'Rock', 'Rose', 'Sand', 'Shaw', 'Stan', 'Stone', 'Thorn', 'Thurl', 'Wake', 'West', 'Wind', 'Wood',
    ];
    private const DESINENZA = ['pool', 'dale', 'hall', 'moor', 'wood', 'gate', 'bank', 'field', 'ton', 'by', 'mere', 'stone'];

    // --- Statunitensi: le Liberty portavano nomi di persone --------------------
    private const USA_NOME = [
        'Nathaniel', 'Silas', 'Thomas', 'Robert', 'Amos', 'Daniel', 'Elias', 'George', 'Henry', 'James',
        'John', 'Lewis', 'Oliver', 'Peter', 'Roger', 'Samuel', 'Timothy', 'William', 'Abraham', 'Benjamin',
        'Caleb', 'Charles', 'Ebenezer', 'Edward', 'Francis', 'Gideon', 'Horace', 'Isaac', 'Jared', 'Jonathan',
        'Josiah', 'Matthew', 'Nathan', 'Nicholas', 'Paul', 'Rufus', 'Stephen', 'Theodore', 'Walter', 'Zebulon',
    ];
    private const USA_COGNOME = [
        'Bacon', 'Weir', 'Nelson', 'Lawrence', 'Morgan', 'Howe', 'Calvert', 'Knox', 'Iredell', 'Winthrop',
        'Cass', 'Wolcott', 'Minuit', 'Williams', 'Chase', 'Pickering', 'Hooper', 'Adams', 'Bartlett', 'Boone',
        'Cabot', 'Carroll', 'Clayton', 'Crockett', 'Dickinson', 'Eliot', 'Fulton', 'Gallatin', 'Greenleaf',
        'Hancock', 'Harlan', 'Hopkins', 'Ingersoll', 'Jefferson', 'Latimer', 'Ludlow', 'Marshall', 'Mason',
        'Paine', 'Peabody', 'Pinckney', 'Randolph', 'Rutledge', 'Sherman', 'Stockton', 'Thayer', 'Trumbull',
        'Vanderbilt', 'Whittier', 'Woodbury',
    ];

    // --- Norvegesi: ceppo piu' desinenza ---------------------------------------
    private const NO_CEPPO = [
        'Bris', 'Fjord', 'Gran', 'Hav', 'Inger', 'Koll', 'Lyse', 'Nord', 'Ran', 'Slem', 'Sol', 'Stor',
        'Vest', 'Vin', 'Bra', 'Egd', 'Hall', 'Berg', 'Dal', 'Eik', 'Fager', 'Gaut', 'Hauk', 'Kvit',
        'Lang', 'Mos', 'Nyk', 'Ost', 'Sand', 'Tind', 'Ulv', 'Vard',
    ];
    private const NO_DESINENZA = ['aas', 'heim', 'vik', 'nes', 'dal', 'borg', 'land', 'fjell', 'havn', 'sund', 'oy', 'strand'];

    // --- Greche, olandesi, neutrali --------------------------------------------
    private const GRECIA = [
        'Aghios Georgios', 'Andromeda', 'Kalliopi', 'Kyriakoula', 'Mount Kitheron', 'Nikolaos', 'Pandias',
        'Pontoporos', 'Themoni', 'Vassilios', 'Zephyros', 'Eleni', 'Ioannis', 'Marika', 'Stamatios',
        'Aghia Marina', 'Anastasia', 'Antonios', 'Aristeides', 'Athina', 'Chrysanthi', 'Despina', 'Dimitrios',
        'Evangelia', 'Georgios', 'Kassandra', 'Katina', 'Konstantinos', 'Leonidas', 'Maria', 'Mount Helmos',
        'Mount Olympos', 'Mount Parnes', 'Mount Pelion', 'Nicolaou', 'Panaghia', 'Petrakis', 'Sofia',
        'Spyros', 'Thalia', 'Theodoros', 'Vasilissa', 'Xenia', 'Yannis',
    ];
    private const OLANDA = [
        'Alkmaar', 'Breedijk', 'Delfland', 'Gaasterkerk', 'Hoogkerk', 'Maasdam', 'Noordam', 'Oranjestad',
        'Rotterdam', 'Stad Alkmaar', 'Tjisalak', 'Winterswijk', 'Zaandam', 'Aagtekerk', 'Alphard', 'Amstelland',
        'Arendskerk', 'Bennekom', 'Blijdendijk', 'Boschdijk', 'Damsterdijk', 'Edam', 'Groote Beer', 'Hoogland',
        'Kinderdijk', 'Leerdam', 'Moerdijk', 'Nieuwland', 'Poeldijk', 'Sommelsdijk', 'Tiberius', 'Veendam',
        'Waterland', 'Westerdam', 'Zuiderkerk',
    ];
    private const NEUTRALI = [
        'Vasaland', 'Suecia', 'Kalmar', 'Ingerto', 'Valparaiso', 'San Martin', 'Lisboa', 'Sagres',
        'Guadalupe', 'Cabo de Hornos', 'Tarragona', 'Eire', 'Irish Oak', 'Irish Pine', 'Aracaju',
        'Bahia Blanca', 'Cabo Frio', 'Cabo Santa Maria', 'Cidade do Porto', 'Corcovado', 'Estoril',
        'Ganda', 'Goa', 'Ilha do Sal', 'Lobito', 'Madeirense', 'Mormugao', 'Nyassa', 'Oporto',
        'Quanza', 'Santa Maria', 'Serpa Pinto', 'Setubal', 'Vera Cruz', 'Alborga', 'Botnia',
        'Dalaro', 'Gotaland', 'Malmoe', 'Nordstjernan', 'Skandia', 'Uppland',
    ];

    /** @var array<string,int> bandiere e loro peso relativo nel traffico atlantico */
    private const BANDIERE = [
        'britannica'   => 42,
        'statunitense' => 18,
        'norvegese'    => 12,
        'greca'        => 7,
        'olandese'     => 6,
        'panamense'    => 5,
        'canadese'     => 4,
        'neutrale'     => 6,
    ];

    /**
     * Nome e bandiera di una nave nuova.
     *
     * Se $bandiera arriva gia' decisa, non si ri-estrae: serve a chi deve
     * riprovare perche' il nome era occupato. Ri-estrarre anche la bandiera
     * sembra innocuo e non lo e' — i repertori nazionali hanno taglie molto
     * diverse, e quelli piccoli (greco, olandese, neutrale) si esauriscono
     * subito: ogni ritentativo li scartava a favore dei due grandi, e la
     * composizione del traffico finiva per essere tutta un'altra cosa rispetto
     * a quella configurata. Misurato: il greco passava dal 7% all'1,4%.
     *
     * @return array{0:string,1:string} [nome, bandiera]
     */
    public static function genera(Rng $rng, string $classe = 'cargo_medio', ?string $bandiera = null): array
    {
        $bandiera ??= self::bandiera($rng);

        $nome = match ($bandiera) {
            'statunitense' => self::pick($rng, self::USA_NOME) . ' ' . self::pick($rng, self::USA_COGNOME),
            'norvegese'    => self::pick($rng, self::NO_CEPPO) . self::pick($rng, self::NO_DESINENZA),
            'greca'        => self::greca($rng),
            'olandese'     => self::olandese($rng),
            'neutrale'     => self::neutrale($rng),
            'panamense'    => $rng->chance(0.5) ? self::greca($rng) : self::neutrale($rng),
            'canadese'     => $rng->chance(0.6)
                ? 'Fort ' . self::pick($rng, self::FORT)
                : self::pick($rng, self::PARK) . ' Park',
            default        => self::britannica($rng),
        };

        return [$nome, $bandiera];
    }

    /**
     * Britanniche: serie di stato oppure compagnia privata, ciascuna col suo
     * schema. E' il gruppo piu' numeroso del traffico, quindi e' anche quello
     * che deve avere lo spazio di nomi piu' largo.
     */
    private static function britannica(Rng $rng): string
    {
        $tiro = $rng->int(1, 100);
        return match (true) {
            $tiro <= 30 => 'Empire ' . self::pick($rng, self::EMPIRE),
            $tiro <= 40 => 'Ocean ' . self::pick($rng, self::OCEAN),
            $tiro <= 55 => 'Clan ' . self::pick($rng, self::CLAN),
            $tiro <= 68 => 'City of ' . self::pick($rng, self::CITTA),
            $tiro <= 78 => 'Baron ' . self::pick($rng, self::BARONE),
            default     => self::pick($rng, self::CEPPO) . self::pick($rng, self::DESINENZA),
        };
    }

    /** Nomi delle scorte: navi da guerra britanniche e canadesi. */
    public static function scorta(Rng $rng, string $classe): string
    {
        // La Royal Navy vare' oltre duecento corvette Flower: i nomi di fiore
        // bastavano, e qui devono bastare allo stesso modo.
        $fiori = ['Anemone', 'Bluebell', 'Campanula', 'Clematis', 'Dianthus', 'Gentian', 'Heather', 'Jonquil',
                  'Lobelia', 'Mallow', 'Narcissus', 'Pimpernel', 'Snowflake', 'Sunflower', 'Verbena', 'Violet',
                  'Abelia', 'Acanthus', 'Aconite', 'Alisma', 'Amaranthus', 'Arabis', 'Arbutus', 'Armeria',
                  'Asphodel', 'Aster', 'Aubretia', 'Azalea', 'Balsam', 'Begonia', 'Bergamot', 'Betony',
                  'Borage', 'Bryony', 'Burdock', 'Buttercup', 'Camellia', 'Candytuft', 'Carnation', 'Celandine',
                  'Chrysanthemum', 'Cineraria', 'Clarkia', 'Clover', 'Columbine', 'Coltsfoot', 'Coreopsis',
                  'Cowslip', 'Crocus', 'Cyclamen', 'Daffodil', 'Dahlia', 'Delphinium', 'Dittany', 'Eglantine',
                  'Erica', 'Fennel', 'Freesia', 'Fritillary', 'Gardenia', 'Geranium', 'Gladiolus', 'Godetia',
                  'Hibiscus', 'Hollyhock', 'Honesty', 'Hyacinth', 'Hydrangea', 'Jasmine', 'Larkspur', 'Lavender',
                  'Lotus', 'Lupin', 'Marguerite', 'Marigold', 'Mignonette', 'Mimosa', 'Monkshood', 'Myosotis',
                  'Nasturtium', 'Nigella', 'Oxlip', 'Pennywort', 'Peony', 'Periwinkle', 'Petunia', 'Polyanthus',
                  'Poppy', 'Primrose', 'Primula', 'Rockrose', 'Rosebay', 'Salvia', 'Saxifrage', 'Snapdragon',
                  'Spiraea', 'Starwort', 'Stonecrop', 'Sweetbriar', 'Tamarisk', 'Thyme', 'Trillium', 'Valerian',
                  'Vervain', 'Wallflower', 'Windflower', 'Zinnia'];
        $fiumi = ['Exe', 'Jed', 'Kale', 'Ness', 'Rother', 'Spey', 'Swale', 'Tay', 'Test', 'Trent', 'Tweed', 'Wear',
                  'Aire', 'Annan', 'Avon', 'Awe', 'Ballinderry', 'Bann', 'Barle', 'Braid', 'Chelmer', 'Cam',
                  'Dart', 'Deveron', 'Dovey', 'Ettrick', 'Evenlode', 'Findhorn', 'Fowey', 'Glenarm', 'Halladale',
                  'Helford', 'Helmsdale', 'Inver', 'Itchen', 'Kenilworth', 'Lagan', 'Lochy', 'Loyal', 'Meon',
                  'Mourne', 'Nadder', 'Nene', 'Nith', 'Odzani', 'Plym', 'Ribble', 'Rupert', 'Shiel', 'Strule',
                  'Taff', 'Tavy', 'Teme', 'Teviot', 'Torridge', 'Towy', 'Trentonian', 'Ullswater', 'Usk', 'Waveney',
                  'Windrush', 'Wye'];
        $uccelli = ['Black Swan', 'Erne', 'Kite', 'Starling', 'Wild Goose', 'Woodpecker', 'Wren', 'Magpie',
                    'Actaeon', 'Alacrity', 'Amethyst', 'Chanticleer', 'Cygnet', 'Crane', 'Cuckoo', 'Curlew',
                    'Flamingo', 'Hart', 'Hind', 'Ibis', 'Lapwing', 'Lark', 'Mermaid', 'Modeste', 'Nereide',
                    'Nightingale', 'Opossum', 'Peacock', 'Pelican', 'Pheasant', 'Redpole', 'Sparrow', 'Whimbrel'];
        $ct = ['Broadway', 'Bulldog', 'Churchill', 'Duncan', 'Hesperus', 'Highlander', 'Malcolm', 'Newark',
               'Rockingham', 'Sardonyx', 'Vanoc', 'Venomous', 'Verity', 'Vidette', 'Walker', 'Watchman', 'Whitehall',
               'Active', 'Anthony', 'Beagle', 'Belmont', 'Beverley', 'Boadicea', 'Brighton', 'Burnham', 'Burwell',
               'Caldwell', 'Cameron', 'Campbeltown', 'Castleton', 'Charlestown', 'Chelsea', 'Chesterfield',
               'Clare', 'Georgetown', 'Hamilton', 'Harvester', 'Havelock', 'Keppel', 'Lancaster', 'Leamington',
               'Leeds', 'Lincoln', 'Ludlow', 'Mansfield', 'Montgomery', 'Ramsey', 'Reading', 'Richmond',
               'Ripley', 'Roxborough', 'Salisbury', 'Sherwood', 'Skate', 'Stanley', 'Vanessa', 'Vanquisher',
               'Velox', 'Verdun', 'Versatile', 'Vesper', 'Veteran', 'Vimy', 'Viscount', 'Vivien', 'Volunteer',
               'Wanderer', 'Warwick', 'Wells', 'Westcott', 'Whirlwind', 'Wild Swan', 'Winchelsea', 'Wivern',
               'Wolverine', 'Worcester', 'Wrestler'];
        $pesca = ['Northern Gem', 'Lady Elsa', 'St. Zeno', 'Vizalma', 'Cape Warwick', 'Arab', 'Aston Villa',
                  'Ayrshire', 'Bedfordshire', 'Cape Argona', 'Cape Mariato', 'Coventry City', 'Daneman',
                  'Grimsby Town', 'Hugh Walpole', 'Kingston Agate', 'Lady Madeleine', 'Lord Austin',
                  'Lord Middleton', 'Lord Nuffield', 'Man o\' War', 'Northern Pride', 'Notts County',
                  'Paynter', 'St. Elstan', 'St. Kenan', 'Stella Capella', 'Vascama', 'Visenda', 'Wastwater'];

        return 'HMS ' . match ($classe) {
            'corvetta_flower'    => self::pick($rng, $fiori),
            'fregata_river'      => self::pick($rng, $fiumi),
            'sloop_black_swan'   => self::pick($rng, $uccelli),
            'trawler_armato'     => self::pick($rng, $pesca),
            'peschereccio'       => self::pick($rng, $pesca),
            default              => self::pick($rng, $ct),
        };
    }

    /** Carico plausibile per tipo di nave: conta per il valore e per come affonda. */
    public static function carico(Rng $rng, string $kind): string
    {
        return match ($kind) {
            'petroliera' => (string) $rng->pick(['greggio', 'nafta', 'benzina avio', 'in zavorra']),
            'trasporto'  => (string) $rng->pick(['truppe', 'truppe e materiali', 'materiale bellico']),
            default      => (string) $rng->pick([
                'materiale bellico', 'cereali', 'minerale di ferro', 'carbone', 'legname', 'cotone',
                'carne congelata', 'zucchero', 'bauxite', 'acciaio', 'automezzi', 'munizioni', 'in zavorra',
            ]),
        };
    }

    /**
     * Greci: quasi sempre il nome di un santo, o quello della famiglia
     * armatrice. I due schemi si combinano, ed e' cosi' che funzionava
     * davvero — le flotte greche erano familiari e le navi portavano i nomi
     * di casa.
     */
    private const GR_SANTI = [
        'Aghios Georgios', 'Aghios Nikolaos', 'Aghios Spyridon', 'Aghia Marina',
        'Aghios Dionysios', 'Aghia Paraskevi', 'Aghios Ioannis', 'Aghios Panteleimon',
    ];
    private const GR_FAMIGLIE = [
        'Kyriakoula', 'Kalliopi', 'Eleni', 'Despina', 'Georgios', 'Maria', 'Anastasia',
        'Evgenia', 'Ioanna', 'Katingo', 'Marika', 'Nicolaou', 'Panaghia', 'Stamatios',
        'Themistocles', 'Vassilios', 'Xenia', 'Zephyros', 'Aikaterini', 'Dimitrios',
        'Konstantinos', 'Leonidas', 'Michalis', 'Nikolas', 'Olympia', 'Pantelis',
    ];
    private const GR_SUFFISSI = ['', '', '', ' II', ' Chandris', ' Goulandris', ' Kulukundis', ' Livanos'];

    /**
     * Olandesi: le compagnie avevano ciascuna la sua desinenza, e dalla
     * desinenza si capiva l'armatore. Holland-Amerika usava -dam per i
     * passeggeri e -dijk per i carichi, il Rotterdamsche Lloyd -kerk.
     */
    private const NL_CEPPO = [
        'Alk', 'Breed', 'Delf', 'Gaaster', 'Hoog', 'Kin', 'Leer', 'Maas', 'Noorder',
        'Ooster', 'Peper', 'Rijn', 'Sloter', 'Texel', 'Veen', 'Waal', 'Zaan', 'Blijden',
        'Boschen', 'Drecht', 'Eemster', 'Gelder', 'Hille', 'Kerk', 'Loos', 'Meer',
    ];
    private const NL_DESINENZA = ['dam', 'dijk', 'kerk', 'veld', 'berg', 'haven', 'land', 'stroom'];

    /** Neutrali: svedesi, spagnoli, portoghesi, irlandesi, svizzeri. */
    private const NEUTRALI_CEPPO = [
        'Vasa', 'Suec', 'Kalm', 'Inger', 'Valpar', 'Marga', 'Corun', 'Estrel', 'Lisbo',
        'Ponta', 'Sant', 'Erin', 'Kerry', 'Shann', 'Bosto', 'Upsal', 'Goteb', 'Malmo',
        'Bilba', 'Vigo', 'Porto', 'Faro', 'Aveir', 'Cadiz',
    ];
    private const NEUTRALI_DESINENZA = ['land', 'holm', 'borg', 'a', 'o', 'nia', 'ia', 'sund'];

    private static function greca(Rng $rng): string
    {
        $base = $rng->chance(0.3)
            ? self::pick($rng, self::GR_SANTI)
            : self::pick($rng, self::GR_FAMIGLIE);
        return $base . self::pick($rng, self::GR_SUFFISSI);
    }

    private static function olandese(Rng $rng): string
    {
        return $rng->chance(0.18)
            ? self::pick($rng, self::OLANDA)
            : self::pick($rng, self::NL_CEPPO) . self::pick($rng, self::NL_DESINENZA);
    }

    private static function neutrale(Rng $rng): string
    {
        return $rng->chance(0.2)
            ? self::pick($rng, self::NEUTRALI)
            : self::pick($rng, self::NEUTRALI_CEPPO) . self::pick($rng, self::NEUTRALI_DESINENZA);
    }

    private static function bandiera(Rng $rng): string
    {
        $totale = array_sum(self::BANDIERE);
        $tiro = $rng->range(0, $totale);
        $acc = 0.0;
        foreach (self::BANDIERE as $b => $peso) {
            $acc += $peso;
            if ($tiro <= $acc) {
                return $b;
            }
        }
        return 'britannica';
    }

    /** @param list<string> $lista */
    private static function pick(Rng $rng, array $lista): string
    {
        return $lista[$rng->int(0, count($lista) - 1)];
    }
}
