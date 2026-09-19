-- Due colonne che nessuno ha mai scritto, e una che adesso si scrive.
--
-- 1. boats.periscopio. Ce ne sono due: questa, e periscopio_alzato. Il codice
--    usa la seconda — insieme a periscopio_gts, che dice da quanto e' fuori —
--    e la prima non l'ha mai toccata nessuno. Due colonne con lo stesso nome e
--    significati diversi sono una trappola per chi legge: prima o poi qualcuno
--    guarda quella sbagliata e trova sempre zero.
--
-- 2. encounters.log. Un LONGTEXT previsto per la cronaca dell'incontro, mai
--    scritto: la cronaca sta nel giornale di bordo (patrol_events), che e'
--    dove un comandante la cerca, ed e' giusto che stia li' e basta.
--
-- Resta invece torpedo_runs.entity_id, che era nella stessa condizione — la
-- colonna c'era e non la scriveva nessuno — ma non si toglie: si riempie. Il
-- conto dei siluri spesi per affondare una nave si fa contando le corse finite
-- addosso a quella nave, e trovava sempre zero. Nel registro degli
-- affondamenti risultavano zero siluri per ogni nave, comprese quelle
-- affondate a siluri.

ALTER TABLE boats DROP COLUMN IF EXISTS periscopio;

ALTER TABLE encounters DROP COLUMN IF EXISTS log;
