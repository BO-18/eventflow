# EventFlow

Plateforme de découverte et réservation d'activités de loisir, avec catalogue e-commerce intégré, construite sous **Drupal 11**.

Projet personnel de montée en compétences technico-fonctionnelles (Drupal, API REST, e-commerce, intégration système), à l'appui d'une recherche d'emploi pour des postes de type chef de projet digital / Product Owner / Implementation / technico-fonctionnel.

## Ce que le projet démontre

- **Product management** : vision produit, personas, parcours utilisateur, cahier des charges avec backlog priorisé MoSCoW (voir [`docs/PRODUIT.md`](docs/PRODUIT.md))
- **CMS sans code** : types de contenu, taxonomies, Views, formulaires d'administration
- **Intégration API REST réelle, deux migrations Migrate Plus sans PHP** : prestataires importés depuis l'API Recherche d'entreprises (données légales françaises), catalogue produit importé depuis DummyJSON avec relation Produit et Variation propre à Drupal Commerce
- **E-commerce** : Drupal Commerce, panier, checkout, paiement Stripe (mode test)
- **Intégration système à système** : Event Subscriber PHP déclenché sur le paiement d'une commande (`OrderEvents::ORDER_PAID`), appel HTTP vers un fournisseur externe simulé, gestion d'erreurs HTTP, protection contre la resynchronisation, journalisation
- **Supervision** : dashboard de synchronisation filtrable

Détail technique complet : [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
Journal des incidents rencontrés et de leur résolution : [`docs/JOURNAL-DE-BORD.md`](docs/JOURNAL-DE-BORD.md)

## Stack technique

| Composant | Choix |
|---|---|
| CMS | Drupal 11 |
| E-commerce | Drupal Commerce 3.x |
| Paiement | Stripe (mode test) |
| Import de données | Migrate API + Migrate Plus + Drush |
| Environnement local | DDEV (WSL2 sous Windows) |
| Tests API | Postman (collections + mock server) |

## Fonctionnalités

- Catalogue d'activités avec recherche et filtres (catégorie, prix, date), navigation par catégorie
- Fiches prestataires alimentées par une vraie source officielle : l'[API Recherche d'entreprises](https://recherche-entreprises.api.gouv.fr) (data.gouv.fr), enrichies d'un site officiel vérifié manuellement
- Catalogue produit (billets, cartes cadeaux, accessoires sportifs) mêlant création manuelle et import automatisé depuis [DummyJSON](https://dummyjson.com) via une migration à deux entités liées (Produit et Variation Commerce)
- Panier, checkout, paiement carte bancaire (Stripe test)
- Réservation automatiquement transmise à un fournisseur externe simulé dès qu'une commande est payée
- Gestion des erreurs fournisseur (HTTP 4xx/5xx) et protection contre la resynchronisation d'une commande déjà traitée avec succès
- Dashboard de supervision des synchronisations, réservé aux administrateurs

## Schéma d'architecture

Voir [`docs/architecture-finale.mermaid`](docs/architecture-finale.mermaid).

## Ce qui n'est pas encore fait

Les images des produits importés depuis DummyJSON ne sont pas encore récupérées automatiquement (l'API les fournit, l'import ne les exploite pas encore). Les billets et cartes cadeaux, eux, restent créés manuellement : DummyJSON n'a pas vocation à fournir ce type de produit, propre à EventFlow.

## Note sur la reproductibilité

Ce dépôt contient le code (modules custom, dépendances déclarées via Composer) et la documentation du projet. La configuration Drupal construite via l'interface d'administration (types de contenu, champs, Views, produits Commerce, passerelle de paiement) n'est pas exportée sous forme de fichiers de configuration versionnés dans `config/sync/`. Cloner ce dépôt donne un Drupal 11 fonctionnel avec les modules custom actifs, mais sans ce contenu — celui-ci a été construit et validé en local. L'export de la configuration active vers `config/sync/` est une amélioration naturelle pour une reproductibilité intégrale.

## Installation locale

Prérequis : Windows + WSL2, DDEV, Composer (fournis par DDEV).

```bash
git clone <url-du-repo> eventflow
cd eventflow
ddev config --project-type=drupal11 --docroot=web
ddev start
ddev composer install
ddev drush site:install --account-name=admin --account-pass=admin -y
ddev drush cr
```

Configurer ensuite l'URL du Supplier API sur `/admin/config/services/eventflow-integration`, et les clés Stripe test sur `/admin/commerce/config/payment-gateways`.
