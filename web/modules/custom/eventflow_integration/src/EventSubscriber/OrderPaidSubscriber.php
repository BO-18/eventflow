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

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * The logger factory.
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The configuration factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Successful synchronization status.
   */
  protected string $successStatus = 'Succès';

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      OrderEvents::ORDER_PAID => 'onOrderPaid',
    ];
  }

  /**
   * Sends a paid order to the supplier API.
   */
  public function onOrderPaid(OrderEvent $event): void {
    $logger = $this->loggerFactory->get('eventflow_integration');

    $order_id = 0;

    try {
      $order = $event->getOrder();
      $order_id = (int) $order->id();

      if (!$order_id) {
        throw new \RuntimeException('Unable to determine the order ID.');
      }

      // Idempotence: do not synchronize an order that already succeeded.
      if ($this->hasSuccessfulSynchronization($order_id)) {
        $logger->info(
          'Commande @order_id déjà synchronisée. Aucun nouvel appel fournisseur effectué.',
          [
            '@order_id' => $order_id,
          ]
        );

        return;
      }

      $supplier_api_url = trim(
        (string) $this->configFactory
          ->get('eventflow_integration.settings')
          ->get('supplier_api_url')
      );

      if ($supplier_api_url === '') {
        throw new \RuntimeException(
          'Supplier API URL is not configured.'
        );
      }

      $order_number = $order->getOrderNumber();
      $email = $order->getEmail();

      $items = [];

      foreach ($order->getItems() as $order_item) {
        $purchased_entity = $order_item->getPurchasedEntity();
        $unit_price = $order_item->getUnitPrice();

        $items[] = [
          'product' => $purchased_entity
            ? $purchased_entity->label()
            : $order_item->label(),
          'quantity' => (int) $order_item->getQuantity(),
          'unit_price' => $unit_price->getNumber(),
          'currency' => $unit_price->getCurrencyCode(),
        ];
      }

      $total_price = $order->getTotalPrice();

      if (!$total_price) {
        throw new \RuntimeException(
          'Unable to determine the order total.'
        );
      }

      $payload = [
        'order_id' => $order_id,
        'order_number' => $order_number ?: (string) $order_id,
        'customer_email' => $email,
        'items' => $items,
        'total' => $total_price->getNumber(),
        'currency' => $total_price->getCurrencyCode(),
      ];

      $response = $this->httpClient->post(
        $supplier_api_url,
        [
          'json' => $payload,
          'timeout' => 10,
          'http_errors' => FALSE,
          'headers' => [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'order-' . $order_id,
          ],
        ]
      );

      $status_code = $response->getStatusCode();
      $response_body = $response->getBody()->getContents();
      $response_data = json_decode($response_body, TRUE);

      if ($status_code < 200 || $status_code >= 300) {
        $message = sprintf(
          'Le fournisseur a retourné HTTP %d.',
          $status_code
        );

        $this->createSyncLog(
          $order_id,
          'Erreur',
          $message,
          NULL
        );

        $logger->error(
          'Erreur fournisseur pour la commande @order_id : HTTP @status.',
          [
            '@order_id' => $order_id,
            '@status' => $status_code,
          ]
        );

        return;
      }

      if (
        !is_array($response_data) ||
        empty($response_data['reservation_id']) ||
        empty($response_data['status'])
      ) {
        $message = 'Réponse fournisseur invalide : reservation_id ou status manquant.';

        $this->createSyncLog(
          $order_id,
          'Erreur',
          $message,
          NULL
        );

        $logger->error(
          'Réponse invalide du fournisseur pour la commande @order_id.',
          [
            '@order_id' => $order_id,
          ]
        );

        return;
      }

      $reservation_id = $response_data['reservation_id'];
      $supplier_status = $response_data['status'];

      $message = sprintf(
        'Commande #%d synchronisée avec succès. Réservation : %s. Statut fournisseur : %s.',
        $order_id,
        $reservation_id,
        $supplier_status
      );

      $this->createSyncLog(
        $order_id,
        $this->successStatus,
        $message,
        $reservation_id
      );

      $logger->info(
        'Commande @order_id synchronisée avec succès. Réservation : @reservation_id. Statut : @status.',
        [
          '@order_id' => $order_id,
          '@reservation_id' => $reservation_id,
          '@status' => $supplier_status,
        ]
      );
    }
    catch (\Throwable $e) {
      $message = sprintf(
        'Erreur lors de la synchronisation fournisseur : %s',
        $e->getMessage()
      );

      if ($order_id > 0) {
        $this->createSyncLog(
          $order_id,
          'Erreur',
          $message,
          NULL
        );
      }

      $logger->error(
        'Erreur lors de la synchronisation fournisseur de la commande @order_id : @message',
        [
          '@order_id' => $order_id ?: 'inconnu',
          '@message' => $e->getMessage(),
        ]
      );
    }
  }

  /**
   * Checks whether the order already has a successful synchronization.
   */
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

  /**
   * Creates a synchronization log node.
   */
  protected function createSyncLog(
    int $order_id,
    string $status,
    string $message,
    ?string $reservation_id = NULL,
  ): void {
    $logger = $this->loggerFactory->get('eventflow_integration');

    try {
      $storage = $this->entityTypeManager->getStorage('node');

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

      $log = $storage->create($values);
      $log->save();
    }
    catch (\Throwable $e) {
      $logger->error(
        'Impossible de créer le journal de synchronisation pour la commande @order_id : @message',
        [
          '@order_id' => $order_id,
          '@message' => $e->getMessage(),
        ]
      );
    }
  }

}
