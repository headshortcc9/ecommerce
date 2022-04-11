<?php

namespace Drupal\eton_api\Plugin\views\style;

use Drupal\commerce_shipping\Entity\Shipment;
use Drupal\rest\Plugin\views\style\Serializer;

/**
 * The style plugin for serialized output formats.
 *
 * @ingroup views_style_plugins
 *
 * @ViewsStyle(
 *   id = "orders_serializer",
 *   title = @Translation("Orders Serializer"),
 *   help = @Translation("Orders Serializes views row data using the Serializer component."),
 *   display_types = {"data"}
 * )
 */
class OrdersSerializer extends Serializer {

  /**
   * {@inheritdoc}
   */
  public function render() {
    $rows = [];
    // If the Data Entity row plugin is used, this will be an array of entities
    // which will pass through Serializer to one of the registered Normalizers,
    // which will transform it to arrays/scalars. If the Data field row plugin
    // is used, $rows will not contain objects and will pass directly to the
    // Encoder.
    foreach ($this->view->result as $row_index => $row) {
      $this->view->row_index = $row_index;
      $entity = $row->_entity;
      $row = $this->view->rowPlugin->render($row);
      foreach ($entity->order_items->referencedEntities() as $orderItem) {
        $variant = $orderItem->purchased_entity ? $orderItem->purchased_entity->entity : NULL;
        $product = $variant->product_id ? $variant->product_id->entity : NULL;

        $row['order_items'][] = [
          'sku' => $variant ? $variant->sku->value : '',
          'barcode' => $product && $product->hasField('field_barcode') ? $product->field_barcode->value : '',
          'title' => $orderItem->title->value,
          'unit_price' => $orderItem->unit_price->currency_code . number_format($orderItem->unit_price->number, 2, '.'),
          'quantity' => $orderItem->quantity->value,
          'total_price' => $orderItem->total_price->currency_code . number_format($orderItem->total_price->number, 2, '.'),
        ];
      }
      $shipment = \Drupal::entityQuery('commerce_shipment')
        ->condition('order_id', $entity->id())
        ->execute();
      if (!empty($shipment)) {
        $shipment = reset($shipment);
        $shipment = Shipment::load($shipment);
        if (!empty($shipment->shipping_profile->entity)) {
          $profile = $shipment->shipping_profile->entity;
          $address = $profile->get('address')->getValue();
          $row['shipping_address'] = $address;
        }
      }
      $rows[] = $row;
    }
    unset($this->view->row_index);

    // Get the content type configured in the display or fallback to the
    // default.
    if ((empty($this->view->live_preview))) {
      $content_type = $this->displayHandler->getContentType();
    }
    else {
      $content_type = !empty($this->options['formats']) ? reset($this->options['formats']) : 'json';
    }
    return $this->serializer->serialize($rows, $content_type, ['views_style_plugin' => $this]);
  }


}
