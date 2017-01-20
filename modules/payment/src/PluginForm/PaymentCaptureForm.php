<?php

namespace Drupal\commerce_payment\PluginForm;

use Drupal\commerce_payment\Event\PaymentEvent;
use Drupal\commerce_payment\Event\PaymentEvents;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;

class PaymentCaptureForm extends PaymentGatewayFormBase {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    $form['#success_message'] = t('Payment captured.');
    $form['amount'] = [
      '#type' => 'commerce_price',
      '#title' => t('Amount'),
      '#default_value' => $payment->getAmount()->toArray(),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValue($form['#parents']);
    $amount = new Price($values['amount']['number'], $values['amount']['currency_code']);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $this->plugin;
    $payment_gateway_plugin->capturePayment($payment, $amount);

    /** @var \Drupal\commerce_payment\Event\PaymentEvent $event */
    $event = new PaymentEvent($payment);
    $event->setAmount($amount);
    /** @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher $event_dispatcher */
    $event_dispatcher = \Drupal::service('event_dispatcher');
    $event_name = ($payment->getOrder()->getTotalPrice()->greaterThan($payment->getAmount())) ? PaymentEvents::PAYMENT_PARTIALLY_CAPTURED : PaymentEvents::PAYMENT_CAPTURED;
    $event_dispatcher->dispatch($event_name, $event);
  }

}
