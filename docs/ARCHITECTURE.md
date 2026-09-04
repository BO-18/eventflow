# Architecture technique — EventFlow

## Vue d'ensemble

```
API Recherche d'entreprises → Migrate Plus → Prestataire → Vue /prestataires
                                                                  ↓
Client → Vue /activites → Drupal Commerce (panier, checkout) → Stripe (test)
                                                                  ↓
                                                      OrderEvents::ORDER_PAID
                                                                  ↓
                                                      OrderPaidSubscriber (PHP)
                                                                  ↓
                                              HTTP POST → Supplier API (mock Postman)
                                                                  ↓
                                          Journal de synchronisation → Dashboard admin
```

Schéma complet : [`architecture-finale.mermaid`](architecture-finale.mermaid).

## Stack

| Composant | Choix | Rôle |
|---|---|---|
| CMS | Drupal 11 | Cœur du site |
| E-commerce | Drupal Commerce 3.x | Catalogue, panier, commande |
| Paiement | Commerce Stripe (mode test) | Paiement par carte |
| Import de données | Migrate API + Migrate Plus + Migrate Tools | Import JSON sans PHP |
| Environnement local | DDEV sur WSL2 | Conteneurs Docker préconfigurés |
| Tests API | Postman | Collections + mock server |

## Modèle de données

### Types de contenu

**Activité** — Titre, Description, Catégorie (référence taxonomie), Prestataire (référence contenu), Ville, Date et heure, Prix, Nombre de places, Durée (minutes), Image.

**Prestataire** — Titre, Ville (`field_ville`), Département (`field_departement`), SIREN (`field_siren`), Statut (`field_statut`, liste : Actif/Fermé), Activité déclarée (`field_activite_declaree`).

**Journal de synchronisation** — Titre, Commande ID (`field_commande_id`), Réservation fournisseur (`field_reservation_fournisseur`), Source (`field_source`, liste : DummyJSON/Recherche entreprises/Supplier API), Statut (`field_statut_`, liste : Succès/Avertissement/Erreur), Message (`field_message`).

### Taxonomie

**Catégorie d'activité** : Escape game, Cours de cuisine, Visite guidée, Atelier photo, Cours de sport, Concert, Conférence, Atelier créatif.

## Modules custom

| Module | Rôle | Contenu |
|---|---|---|
| `eventflow_migrations` | Import Prestataire depuis l'API Recherche d'entreprises | Un fichier YAML, aucun PHP |
| `eventflow_integration` | Connecteur vers le Supplier API + configuration | Un Event Subscriber, un formulaire de configuration |

## L'import Prestataire (Migrate Plus)

`web/modules/custom/eventflow_migrations/config/install/migrate_plus.migration.prestataires.yml` :

```yaml
id: prestataires
label: "Import des prestataires via l'API Recherche d'entreprises"
migration_group: eventflow
source:
  plugin: url
  data_fetcher_plugin: http
  data_parser_plugin: json
  urls:
    - 'https://recherche-entreprises.api.gouv.fr/search?q=escape%20game&departement=75&per_page=10'
  item_selector: results
  fields:
    - name: siren
      selector: siren
    - name: nom
      selector: nom_complet
    - name: activite
      selector: activite_principale
    - name: etat
      selector: etat_administratif
    - name: departement
      selector: siege/departement
    - name: ville
      selector: siege/libelle_commune
  ids:
    siren:
      type: string
destination:
  plugin: 'entity:node'
  default_bundle: prestataire
process:
  title: nom
  field_siren: siren
  field_activite_declaree: activite
  field_departement: departement
  field_ville: ville
  field_statut:
    plugin: static_map
    source: etat
    map:
      A: Actif
      C: Fermé
      F: Fermé
    default_value: Fermé
  status:
    plugin: default_value
    default_value: 1
```

Exécution : `ddev drush migrate:import prestataires`. Ré-exécutable sans créer de doublons (Migrate suit les identifiants sources déjà traités).

## Le Supplier API fictif

Un mock server Postman, construit à partir d'une collection avec deux requêtes et leurs réponses pré-enregistrées :

- `GET /products` → `{"sku": "EVT-001", "name": "T-shirt EventFlow", "price": 19.90, "stock": 35}` (200)
- `POST /post` → `{"reservation_id": "RES-84521", "status": "confirmed"}` (201)

URL type : `https://xxxx.mock.pstmn.io/post`. Aucun serveur à héberger, aucun code à écrire pour le fournisseur — seule la collection Postman définit son comportement.

## Drupal Commerce

Store unique (EventFlow, EUR, France). Type de produit par défaut, enrichi d'un champ Catégorie produit (Billet / Carte cadeau / Produit dérivé). Panier et checkout fonctionnels nativement dès l'activation des modules `commerce_cart` et `commerce_checkout`, sans configuration additionnelle.

## Paiement Stripe (mode test)

Module `commerce_stripe`, passerelle configurée avec des clés `pk_test_...` / `sk_test_...`, mode Test actif. Le Payment Element de Stripe s'affiche directement dans le checkout Commerce. Carte de test universelle : `4242 4242 4242 4242`, toute date future, tout CVC.

## Le connecteur — OrderPaidSubscriber

Déclenché par l'événement Drupal Commerce `OrderEvents::ORDER_PAID` (`commerce_order.order.paid`), émis précisément quand une commande passe au statut payé — pas à la création de la commande.

**Déclaration du service** (`eventflow_integration.services.yml`) :

```yaml
services:
  eventflow_integration.order_paid_subscriber:
    class: Drupal\eventflow_integration\EventSubscriber\OrderPaidSubscriber
    arguments:
      - '@http_client'
      - '@logger.factory'
      - '@entity_type.manager'
      - '@config.factory'
    tags:
      - { name: event_subscriber }
```

**La classe** (`src/EventSubscriber/OrderPaidSubscriber.php`) :

```php
<?php

namespace Drupal\eventflow_integration\EventSubscriber;

use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderPaidSubscriber implements EventSubscriberInterface {

  protected string $successStatus = 'Succès';

  public function __construct(
    protected ClientInterface $httpClient,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  public static function getSubscribedEvents(): array {
    return [
      OrderEvents::ORDER_PAID => 'onOrderPaid',
    ];
  }

  public function onOrderPaid(OrderEvent $event): void {
    $logger = $this->loggerFactory->get('eventflow_integration');
    $order_id = 0;

    try {
      $order = $event->getOrder();
      $order_id = (int) $order->id();

      if (!$order_id) {
        throw new \RuntimeException('Unable to determine the order ID.');
      }

      // Idempotence : ne pas re-synchroniser une commande déjà réussie.
      if ($this->hasSuccessfulSynchronization($order_id)) {
        $logger->info('Commande @order_id déjà synchronisée. Aucun nouvel appel fournisseur effectué.', [
          '@order_id' => $order_id,
        ]);
        return;
      }

      $supplier_api_url = trim((string) $this->configFactory
        ->get('eventflow_integration.settings')
        ->get('supplier_api_url'));

      if ($supplier_api_url === '') {
        throw new \RuntimeException('Supplier API URL is not configured.');
      }

      $items = [];
      foreach ($order->getItems() as $order_item) {
        $purchased_entity = $order_item->getPurchasedEntity();
        $unit_price = $order_item->getUnitPrice();
        $items[] = [
          'product' => $purchased_entity ? $purchased_entity->label() : $order_item->label(),
          'quantity' => (int) $order_item->getQuantity(),
          'unit_price' => $unit_price->getNumber(),
          'currency' => $unit_price->getCurrencyCode(),
        ];
      }

      $total_price = $order->getTotalPrice();
      if (!$total_price) {
        throw new \RuntimeException('Unable to determine the order total.');
      }

      $payload = [
        'order_id' => $order_id,
        'order_number' => $order->getOrderNumber() ?: (string) $order_id,
        'customer_email' => $order->getEmail(),
        'items' => $items,
        'total' => $total_price->getNumber(),
        'currency' => $total_price->getCurrencyCode(),
      ];

      $response = $this->httpClient->post($supplier_api_url, [
        'json' => $payload,
        'timeout' => 10,
        'http_errors' => FALSE,
        'headers' => [
          'Accept' => 'application/json',
          'Idempotency-Key' => 'order-' . $order_id,
        ],
      ]);

      $status_code = $response->getStatusCode();
      $response_data = json_decode($response->getBody()->getContents(), TRUE);

      if ($status_code < 200 || $status_code >= 300) {
        $this->createSyncLog($order_id, 'Erreur', sprintf('Le fournisseur a retourné HTTP %d.', $status_code), NULL);
        $logger->error('Erreur fournisseur pour la commande @order_id : HTTP @status.', [
          '@order_id' => $order_id,
          '@status' => $status_code,
        ]);
        return;
      }

      if (!is_array($response_data) || empty($response_data['reservation_id']) || empty($response_data['status'])) {
        $this->createSyncLog($order_id, 'Erreur', 'Réponse fournisseur invalide : reservation_id ou status manquant.', NULL);
        $logger->error('Réponse invalide du fournisseur pour la commande @order_id.', ['@order_id' => $order_id]);
        return;
      }

      $reservation_id = $response_data['reservation_id'];
      $message = sprintf(
        'Commande #%d synchronisée avec succès. Réservation : %s. Statut fournisseur : %s.',
        $order_id,
        $reservation_id,
        $response_data['status']
      );

      $this->createSyncLog($order_id, $this->successStatus, $message, $reservation_id);
      $logger->info('Commande @order_id synchronisée avec succès. Réservation : @reservation_id.', [
        '@order_id' => $order_id,
        '@reservation_id' => $reservation_id,
      ]);
    }
    catch (\Throwable $e) {
      if ($order_id > 0) {
        $this->createSyncLog($order_id, 'Erreur', sprintf('Erreur lors de la synchronisation fournisseur : %s', $e->getMessage()), NULL);
      }
      $logger->error('Erreur lors de la synchronisation fournisseur de la commande @order_id : @message', [
        '@order_id' => $order_id ?: 'inconnu',
        '@message' => $e->getMessage(),
      ]);
    }
  }

  protected function hasSuccessfulSynchronization(int $order_id): bool {
    $query = $this->entityTypeManager
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'journal_de_synchronisation')
      ->condition('field_commande_id', $order_id)
      ->condition('field_statut_', $this->successStatus)
      ->range(0, 1);

    return (bool) $query->execute();
  }

  protected function createSyncLog(int $order_id, string $status, string $message, ?string $reservation_id): void {
    $logger = $this->loggerFactory->get('eventflow_integration');
    try {
      $values = [
        'type' => 'journal_de_synchronisation',
        'title' => sprintf('Synchronisation commande #%d', $order_id),
        'status' => 1,
        'field_commande_id' => $order_id,
        'field_source' => 'Supplier API',
        'field_statut_' => $status,
        'field_message' => $message,
      ];
      if ($reservation_id !== NULL) {
        $values['field_reservation_fournisseur'] = $reservation_id;
      }
      $this->entityTypeManager->getStorage('node')->create($values)->save();
    }
    catch (\Throwable $e) {
      $logger->error('Impossible de créer le journal de synchronisation pour la commande @order_id : @message', [
        '@order_id' => $order_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

}
```

**Points de conception à retenir** :
- L'échec de la création du journal ne fait jamais planter le flux principal (`try/catch` dédié dans `createSyncLog`) — un souci de journalisation ne doit pas cacher un paiement pourtant réussi.
- Un en-tête `Idempotency-Key` est envoyé au fournisseur, en plus de la vérification côté Drupal — double protection contre un double appel.
- `http_errors => FALSE` : Guzzle ne lève pas d'exception sur un 4xx/5xx, ce qui permet de traiter ces cas comme un résultat normal (branche Erreur) plutôt que par exception.

## Configuration externalisée

Plutôt qu'une URL codée en dur, un formulaire d'administration (`SettingsForm extends ConfigFormBase`) expose le réglage sur `/admin/config/services/eventflow-integration`, stocké dans `eventflow_integration.settings` (clé `supplier_api_url`), avec validation d'URL et vérification HTTPS côté serveur. Permet de changer de fournisseur (ou de simuler une panne en pointant vers une route inexistante) sans toucher au code PHP.

## Idempotence — pourquoi et comment

Sans protection, un même événement `ORDER_PAID` rejoué (bug, notification dupliquée, exécution manuelle de test) enverrait plusieurs réservations pour la même commande. `hasSuccessfulSynchronization()` interroge les nœuds Journal existants avant tout appel HTTP : si une synchronisation réussie existe déjà pour cette commande, le Subscriber s'arrête immédiatement, sans nouvel appel ni nouveau journal.

Validation : sur une commande déjà synchronisée avec succès, un nouvel appel au Subscriber laisse le nombre de journaux strictement inchangé, et le watchdog log affiche *"Commande déjà synchronisée. Aucun nouvel appel fournisseur effectué."*

## Dashboard de synchronisation

Vue Drupal sur `journal_de_synchronisation`, accessible sur `/dashboard/synchronisation`, filtres exposés (Statut, Source, Commande ID), accès restreint au rôle Administrateur.

## Ce qui n'est pas encore fait

L'import automatisé du catalogue e-commerce depuis DummyJSON n'a pas été construit — le catalogue de démonstration a été créé manuellement pour prioriser la validation du flux paiement → connecteur. L'API a été explorée et testée (paramètres de chemin/requête, pagination) via Postman. L'extension suivrait le même principe que la migration Prestataire : une migration Migrate Plus ciblant l'entité `commerce_product` plutôt qu'un simple nœud.
