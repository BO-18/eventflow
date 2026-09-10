# Documentation produit — EventFlow

## Vision

Pour les actifs urbains qui veulent organiser une sortie — seuls, entre amis ou en couple — sans passer des heures à comparer des sites différents, EventFlow est une plateforme de découverte et de réservation d'activités de loisir qui centralise recherche, réservation et achat de produits complémentaires en un seul parcours.

**Différenciateurs** : prestataires vérifiables via une source officielle (API Recherche d'entreprises), réservation et boutique dans un même panier, dashboard de synchronisation pour piloter la fraîcheur des données.

**Hors périmètre, assumé** : vrai encaissement au-delà du mode test, gestion de stock physique, application mobile, système d'avis, multilingue, vraie intégration avec un système de réservation tiers.

## Personas

**Camille**, 32 ans, organisatrice de sorties de groupe — veut réserver pour plusieurs personnes en un seul parcours, sans gérer les paiements dispersés entre participants.

**Thomas**, 29 ans, chercheur d'expérience solo/duo — veut filtrer rapidement par catégorie/date/prix, réserver sans créer de compte.

**Sophie**, 38 ans, administratrice de la plateforme — veut un dashboard clair plutôt que des logs bruts, pour détecter une synchronisation en échec en un coup d'œil. Ce persona représente directement le rôle visé par ce projet (chef de projet / technico-fonctionnel).

## Parcours utilisateur

**Client** : Accueil → Recherche et filtres → Fiche activité (infos prestataire vérifiées) → Choix date/heure (+ participants pour une réservation groupe) → Panier → Boutique complémentaire (optionnelle) → Checkout → Paiement Stripe → Commande créée → Connecteur réservation vers le Supplier API → Confirmation.

**Admin (Sophie)** : Dashboard de synchronisation → Consultation des journaux (succès/erreurs) → Filtrage par statut, source, commande.

Schéma détaillé : voir `parcours-utilisateur-eventflow.mermaid` (livré en début de projet).

## Backlog produit

### Epic A — Découverte & recherche
| ID | User story | Priorité | Statut |
|---|---|---|---|
| US01 | Voir les activités mises en avant sur l'accueil | Must | Fait |
| US02 | Filtrer les activités par catégorie, date, prix, lieu | Must | Fait |
| US03 | Consulter la fiche détaillée d'une activité | Must | Fait |
| US04 | Voir des informations prestataire vérifiées | Should | Fait |
| US21 | Choisir la catégorie dans une liste plutôt que la taper | Could | Fait (autocomplétion ; liste déroulante = amélioration future) |

### Epic B — Réservation
| ID | User story | Priorité | Statut |
|---|---|---|---|
| US05 | Choisir une date et une heure disponibles | Must | Fait |
| US06 | Indiquer un nombre de participants (réservation groupe) | Must | Fait (champ modélisé) |
| US07 | Être averti si un créneau est complet | Should | Non fait |

### Epic C — E-commerce & paiement
| ID | User story | Priorité | Statut |
|---|---|---|---|
| US08 | Ajouter une activité au panier | Must | Fait |
| US09 | Ajouter des produits complémentaires | Should | Fait (catalogue manuel) |
| US10 | Récapitulatif avant paiement | Must | Fait |
| US11 | Payer via Stripe en mode test | Must | Fait |
| US12 | Recevoir une confirmation claire | Must | Fait |

### Epic D — Compte utilisateur
| ID | User story | Priorité | Statut |
|---|---|---|---|
| US13 | Réserver sans créer de compte | Should | Fait (comportement Commerce natif) |
| US14 | Créer un compte optionnel avec historique | Could | Non fait |

### Epic E — Administration & synchronisation
| ID | User story | Priorité | Statut |
|---|---|---|---|
| US15 | Dashboard de synchronisation | Must | Fait |
| US16 | Lancer une synchronisation manuelle | Must | Fait (`drush migrate:import`) |
| US17 | Mapping automatique des données vers Drupal | Must | Fait |
| US18 | Journal de synchronisation | Must | Fait |
| US19 | Suivre le statut des réservations envoyées au fournisseur | Should | Fait |
| US20 | Automatiser la synchronisation via CRON | Could | Non fait |
| US22 | Gérer les erreurs HTTP du fournisseur (4xx/5xx) sans casser le paiement | Must | Fait *(ajoutée en cours de développement)* |
| US23 | Ne pas resynchroniser une commande déjà traitée avec succès | Must | Fait *(ajoutée en cours de développement)* |
| US24 | Configurer l'URL du fournisseur sans modifier le code | Should | Fait *(ajoutée en cours de développement)* |

### Hors périmètre (Won't)
Système d'avis et de notation, application mobile native, support multilingue, vrai encaissement, import automatisé du catalogue e-commerce (DummyJSON exploré, non intégré).

## Règles de gestion

- La réservation fournisseur est considérée comme confirmée lorsque le paiement Stripe est effectué et que le Supplier API retourne une réponse positive avec un statut `confirmed`. Ce résultat est journalisé ; il ne modifie pas l'état de la commande dans Drupal Commerce, qui reste géré par le workflow standard de paiement.
- Si le Supplier API renvoie une erreur, la commande reste enregistrée normalement ; l'échec est journalisé, aucune tentative silencieuse n'est perdue.
- Une commande synchronisée avec succès n'est pas renvoyée une seconde fois au fournisseur lors d'une nouvelle exécution séquentielle (vérification systématique avant tout appel). Cette protection couvre le rejeu séquentiel de l'événement ; une architecture à fort trafic la compléterait par une contrainte d'unicité en base ou un verrou applicatif pour couvrir le cas de requêtes strictement simultanées.
- Les informations prestataire affichées proviennent exclusivement de l'API Recherche d'entreprises, sans saisie manuelle.

## Definition of Done

Une fonctionnalité est terminée quand : elle répond à sa user story, le parcours associé fonctionne de bout en bout sans erreur bloquante, les appels API ont été vérifiés indépendamment (Postman) avant intégration, et une trace (log, capture, ou entrée de ce journal produit) documente sa validation.
