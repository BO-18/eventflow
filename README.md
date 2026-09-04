# EventFlow

Plateforme de découverte et réservation d'activités de loisir, avec catalogue e-commerce intégré, construite sous **Drupal 11**.

Projet personnel de montée en compétences technico-fonctionnelles (Drupal, API REST, e-commerce, intégration système), à l'appui d'une recherche d'emploi pour des postes de type chef de projet digital / Product Owner / Implementation / technico-fonctionnel.

## Ce que le projet démontre

- **Product management** : vision produit, personas, parcours utilisateur, cahier des charges avec backlog priorisé MoSCoW (voir [`docs/PRODUIT.md`](docs/PRODUIT.md))
- **CMS sans code** : types de contenu, taxonomies, Views, formulaires d'administration
- **Intégration API REST réelle** : import de données publiques françaises (API Recherche d'entreprises) via Migrate Plus, sans PHP
- **E-commerce** : Drupal Commerce, panier, checkout, paiement Stripe (mode test)
- **Intégration système à système** : Event Subscriber PHP déclenché sur le paiement d'une commande (`OrderEvents::ORDER_PAID`), appel HTTP vers un fournisseur externe simulé, gestion d'erreurs HTTP, idempotence, journalisation
- **Supervision** : dashboard de synchronisation filtrable

Détail technique complet : [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
Journal des incidents rencontrés et de leur résolution : [`docs/JOURNAL-DE-BORD.md`](docs/JOURNAL-DE-BORD.md)

## Stack technique

| Composant | Choix |
|---|---|
| CMS | Drupal 11 |
| E-commerce | Drupal Commerce 3.x |
| Paiement | Stripe (mode test) |
| Import de données | Migrate API + Migrate Plus + Migrate Tools |
| Environnement local | DDEV (WSL2 sous Windows) |
| Tests API | Postman (collections + mock server) |

## Fonctionnalités

- Catalogue d'activités avec recherche et filtres (catégorie, prix, date)
- Fiches prestataires alimentées par une vraie source officielle : l'[API Recherche d'entreprises](https://recherche-entreprises.api.gouv.fr) (data.gouv.fr)
- Panier, checkout, paiement carte bancaire (Stripe test)
- Réservation automatiquement transmise à un fournisseur externe simulé dès qu'une commande est payée
- Gestion des erreurs fournisseur (HTTP 4xx/5xx) et idempotence (une commande n'est jamais synchronisée deux fois)
- Dashboard de supervision des synchronisations, réservé aux administrateurs

## Schéma d'architecture

Voir [`docs/architecture-finale.mermaid`](docs/architecture-finale.mermaid).

## Ce qui n'est pas encore fait

Le catalogue produit e-commerce (billets, cartes cadeaux, goodies) a été peuplé manuellement pour cette démonstration plutôt qu'importé automatiquement. L'API [DummyJSON](https://dummyjson.com) a été explorée et testée via Postman (paramètres de chemin, de requête, pagination) dans cet objectif, mais l'import automatisé vers Drupal Commerce reste une extension naturelle non encore construite — même mécanisme que la migration Prestataire, appliqué à un produit Commerce plutôt qu'à un nœud simple.

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
