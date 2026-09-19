-- Le sessioni non si contano a orologio, si contano a generazioni.
--
-- La 0032 ha introdotto users.sessioni_da per buttare fuori chi era gia'
-- dentro quando si rifa' la password: una sessione nata prima di quell'istante
-- non vale piu'. E' la meta' che conta del recupero — la password si rifa'
-- proprio perche' qualcun altro e' entrato — ma il confronto fra due istanti
-- con la risoluzione del secondo non sa decidere il caso che capita di piu':
-- l'accesso che arriva NELLO STESSO SECONDO del cambio, cioe' il legittimo
-- proprietario che ha appena finito di scegliere la password nuova.
--
-- Col confronto stretto sopravviveva la sessione dell'intruso. Con quello
-- largo — messo per correggere il primo caso — cadeva la sessione appena
-- aperta dal proprietario, che si ritrovava buttato fuori subito dopo essere
-- entrato. Misurato: una prova su tre falliva proprio li', e falliva a caso,
-- perche' dipendeva da dove cascava il confine del secondo.
--
-- Non e' un difetto di soglia: e' che un orologio a gradini non puo' ordinare
-- due fatti dentro lo stesso gradino. Al posto dell'istante si tiene un
-- contatore: ogni cambio di password fa avanzare la generazione, e ogni
-- sessione si porta dietro la generazione con cui e' nata. Piu' vecchia della
-- corrente, fuori. Nessun secondo da spaccare.

ALTER TABLE users
    ADD COLUMN sessioni_gen INT UNSIGNED NOT NULL DEFAULT 0 AFTER sessioni_da;
