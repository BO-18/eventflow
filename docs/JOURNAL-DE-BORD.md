# Journal de bord — incidents et résolutions

Documenter les problèmes rencontrés, pas seulement le résultat final : c'est souvent ce qu'un recruteur technique veut entendre plutôt qu'une liste de fonctionnalités qui marchent du premier coup.

---

### 1. Mock server Postman : la réponse ne correspondait pas à l'exemple enregistré

**Symptôme** — Le mock server renvoyait le bon code HTTP (201) mais un corps de réponse générique (l'écho brut de postman-echo.com) au lieu du JSON personnalisé attendu.

**Diagnostic** — Comparaison ligne à ligne des deux panneaux "Body" de l'écran d'édition d'un exemple Postman : celui de la **requête** d'origine, et celui de la **réponse** enregistrée. Le JSON personnalisé avait été saisi dans le premier au lieu du second — une confusion facilitée par le fait que les deux panneaux portent le même nom.

**Correction** — Vider le panneau réponse, y saisir le JSON attendu, sauvegarder.

**Ce que ça montre** — Isoler une hypothèse et la vérifier visuellement plutôt que d'empiler des tentatives ; lire une interface avec précision plutôt que par habitude.

---

### 2. Vue Drupal sans adresse accessible

**Symptôme** — Après configuration complète d'une vue (champs, filtres), aucune URL ne permettait d'y accéder.

**Diagnostic** — L'affichage **Default** d'une vue Drupal n'est jamais directement accessible : c'est un socle de réglages partagés réutilisé par de vrais affichages (Page, Bloc...), pas une page en soi.

**Correction** — Ajout d'un affichage **Page**, qui hérite automatiquement des réglages du Default et expose un chemin (`/activites`, `/prestataires`).

**Ce que ça montre** — Compréhension du modèle d'héritage de Views, pas seulement de son interface.

---

### 3. Filtres exposés affichant leur nom technique

**Symptôme** — Les filtres affichaient `Catégorie (field_categorie)` plutôt que `Catégorie`.

**Diagnostic** — Le champ **Label** du filtre était resté vide ; Drupal retombe alors sur le nom technique complet.

**Correction** — Remplissage manuel du Label pour chaque filtre concerné (6 filtres au total, sur deux vues).

---

### 4. Un module PHP redondant proposé par erreur

**Contexte** — Lors d'une reprise en parallèle du projet avec un autre assistant IA, celui-ci a conclu, à partir d'une recherche dans `web/modules/custom`, que le Supplier API n'existait pas, et a proposé d'en recréer un entièrement en PHP (route + contrôleur Drupal).

**Diagnostic** — Le Supplier API n'a jamais été un module Drupal : c'est un mock server Postman, hébergé en dehors du projet. Chercher dans le code du projet ne pouvait donc rien trouver — la méthode de recherche ciblait le mauvais endroit, pas une absence réelle.

**Décision** — Ne pas construire ce module redondant ; réutiliser le mock server déjà existant et fonctionnel.

**Ce que ça montre** — Vérifier une conclusion technique en remontant à la source plutôt que de l'accepter parce qu'elle est bien argumentée ; savoir distinguer "absent du code" et "absent du projet" quand une brique est volontairement externe.

---

### 5. Une valeur de configuration cassée dans l'Event Subscriber

**Symptôme** — Une variable clé du code (`$successStatus`) contenait la chaîne `'valeurs_autorisees_succes_avertissement_erreur'` — visiblement un fragment de texte descriptif collé à la place d'une vraie valeur, introduit lors d'une session de travail parallèle.

**Impact potentiel** — Cette variable est utilisée à deux endroits : l'enregistrement du statut de succès dans le journal, et la vérification d'idempotence (a-t-on déjà traité cette commande ?). Une valeur incorrecte aurait silencieusement désactivé cette seconde protection.

**Correction** — Remplacement par la vraie valeur autorisée du champ Statut (`'Succès'`), vérifiée directement dans l'interface d'administration avant modification du code.

---

### 6. Une correction inversée par un diagnostic erroné, sur la base d'un seul indice

**Symptôme** — Après le correctif précédent, un test d'idempotence a échoué : une commande déjà synchronisée avec succès a été re-synchronisée, créant un troisième journal au lieu de zéro.

**Fausse piste** — En observant qu'une URL de filtre affichait `field_statut__value=erreur` en minuscule alors que l'interface affichait "Erreur", une session de travail parallèle a conclu que les valeurs *techniques* attendues par Drupal étaient en réalité les anciennes valeurs cassées, et a exécuté un script rétablissant ces valeurs sur l'ensemble des journaux existants — écrasant des données qui étaient en fait correctes.

**Preuve retenue pour trancher** — Un test antérieur, déjà observé : juste après le premier correctif, une commande venait d'être synchronisée avec la valeur `'Succès'`, puis immédiatement reconnue comme "déjà synchronisée" lors d'un second appel. Cette preuve directe (le code écrit une valeur, puis la retrouve) l'emportait sur un indice indirect (une valeur affichée dans une URL) mal interprété.

**Correction** — Script inverse, restaurant les valeurs correctes (`Succès`, `Erreur`, `Supplier API`) sur tous les journaux concernés. Nouveau test d'idempotence : le nombre de journaux reste strictement identique après un appel sur une commande déjà traitée — validation définitive.

**Ce que ça montre** — En cas de diagnostics contradictoires entre deux sources, retenir la preuve la plus directe (une exécution du système observée) plutôt que l'indice le plus récent ; ne pas exécuter une correction en masse sur des données de production sans un test de non-régression derrière.

---

## Pourquoi documenter ça plutôt que le cacher

Ces six incidents ne sont pas des échecs à minimiser : ils illustrent une vraie méthode de diagnostic (isoler une hypothèse, chercher une preuve directe plutôt qu'un indice, tester avant et après correction) — précisément ce qu'un entretien technique cherche à évaluer au-delà de la simple lecture d'un CV.
