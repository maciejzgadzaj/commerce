<?php

namespace Drupal\Tests\commerce_payment\Functional;

use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\StoreCreationTrait;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

/**
 * Tests the admin payment UI.
 *
 * @group commerce
 */
class PaymentAdminTest extends CommerceBrowserTestBase {

  use StoreCreationTrait;

  /**
   * An on-site payment gateway.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface
   */
  protected $paymentGateway;

  /**
   * Admin's payment method.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentMethodInterface
   */
  protected $paymentMethod;

  /**
   * The base admin payment uri.
   *
   * @var string
   */
  protected $paymentUri;

  /**
   * The admin's order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * The store entity.
   *
   * @var \Drupal\commerce_store\Entity\Store
   */
  protected $store;

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_order',
    'commerce_product',
    'commerce_payment',
    'commerce_payment_example',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge([
      'administer commerce_order',
      'administer commerce_payment_gateway',
      'administer commerce_payment',
    ], parent::getAdministratorPermissions());
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->store = $this->createStore();

    $this->paymentGateway = $this->createEntity('commerce_payment_gateway', [
      'id' => 'example',
      'label' => 'Example',
      'plugin' => 'example_onsite',
    ]);

    $this->paymentMethod = $this->createEntity('commerce_payment_method', [
      'uid' => $this->loggedInUser->id(),
      'type' => 'credit_card',
      'payment_gateway' => 'example',
    ]);

    $details = [
      'type' => 'visa',
      'number' => '4111111111111111',
      'expiration' => ['month' => '01', 'year' => date('Y') + 1],
    ];
    $this->paymentGateway->getPlugin()->createPaymentMethod($this->paymentMethod, $details);

    $variation = $this->createEntity('commerce_product_variation', [
      'type' => 'default',
      'sku' => 'test-product-01',
      'price' => new Price('10', 'USD'),
    ]);

    $order_item = $this->createEntity('commerce_order_item', [
      'type' => 'default',
      'quantity' => 1,
      'purchased_entity' => $variation,
      'unit_price' => new Price('10', 'USD'),
    ]);

    $this->order = $this->createEntity('commerce_order', [
      'uid' => $this->loggedInUser->id(),
      'type' => 'default',
      'state' => 'draft',
      'order_items' => [$order_item],
      'store_id' => $this->store,
    ]);

    $this->paymentUri = 'admin/commerce/orders/' . $this->order->id() . '/payments';
  }

  /**
   * Tests that a Payments tab is visible on the order page.
   */
  public function testPaymentTab() {
    $this->drupalGet('admin/commerce/orders/' . $this->order->id());
    $this->assertSession()->linkExists('Payments');
    $this->assertSession()->linkByHrefExists($this->paymentUri);
  }

  /**
   * Tests creating a payment for an order.
   */
  public function testPaymentCreation() {
    $this->drupalGet($this->paymentUri);
    $this->getSession()->getPage()->clickLink('Add payment');
    $this->assertSession()->addressEquals($this->paymentUri . '/add');
    $this->assertSession()->pageTextContains('Visa ending in 1111');
    $this->assertSession()->checkboxChecked('payment_method');

    $this->getSession()->getPage()->pressButton('Continue');
    $this->submitForm(['payment[amount][number]' => '100'], 'Add payment');
    $this->assertSession()->addressEquals($this->paymentUri);
    $this->assertSession()->pageTextContains('Capture (Completed)');

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = Payment::load(1);
    $this->assertEquals($payment->getOrderId(), $this->order->id());
    $this->assertEquals($payment->getAmount()->getNumber(), '100');
  }

  /**
   * Tests capturing a payment after creation.
   */
  public function testPaymentCapture() {
    $payment = $this->createEntity('commerce_payment', [
      'payment_gateway' => $this->paymentGateway->id(),
      'payment_method' => $this->paymentMethod->id(),
      'order_id' => $this->order->id(),
      'amount' => new Price('10', 'USD'),
    ]);

    $this->paymentGateway->getPlugin()->createPayment($payment, FALSE);

    $this->drupalGet($this->paymentUri);
    $this->assertSession()->pageTextContains('Authorization');

    $this->drupalGet($this->paymentUri . '/' . $payment->id() . '/operation/capture');
    $this->submitForm(['payment[amount][number]' => '10'], 'Capture');
    $this->assertSession()->addressEquals($this->paymentUri);
    $this->assertSession()->pageTextNotContains('Authorization');
    $this->assertSession()->pageTextContains('Capture (Completed)');

    $payment = Payment::load($payment->id());
    $this->assertEquals($payment->getState()->getLabel(), 'Capture (Completed)');
  }

  /**
   * Tests refunding a payment after capturing.
   */
  public function testPaymentRefund() {
    $payment = $this->createEntity('commerce_payment', [
      'payment_gateway' => $this->paymentGateway->id(),
      'payment_method' => $this->paymentMethod->id(),
      'order_id' => $this->order->id(),
      'amount' => new Price('10', 'USD'),
    ]);

    $this->paymentGateway->getPlugin()->createPayment($payment, TRUE);

    $this->drupalGet($this->paymentUri);
    $this->assertSession()->pageTextContains('Capture (Completed)');

    $this->drupalGet($this->paymentUri . '/' . $payment->id() . '/operation/refund');
    $this->submitForm(['payment[amount][number]' => '10'], 'Refund');
    $this->assertSession()->addressEquals($this->paymentUri);
    $this->assertSession()->pageTextNotContains('Capture (Completed)');
    $this->assertSession()->pageTextContains('Capture (Refunded)');

    $payment = Payment::load($payment->id());
    $this->assertEquals($payment->getState()->getLabel(), 'Capture (Refunded)');
  }

  /**
   * Tests voiding a payment after creation.
   */
  public function testPaymentVoid() {
    $payment = $this->createEntity('commerce_payment', [
      'payment_gateway' => $this->paymentGateway->id(),
      'payment_method' => $this->paymentMethod->id(),
      'order_id' => $this->order->id(),
      'amount' => new Price('10', 'USD'),
    ]);

    $this->paymentGateway->getPlugin()->createPayment($payment, FALSE);

    $this->drupalGet($this->paymentUri);
    $this->assertSession()->pageTextContains('Authorization');

    $this->drupalGet($this->paymentUri . '/' . $payment->id() . '/operation/void');
    $this->getSession()->getPage()->pressButton('Void');
    $this->assertSession()->addressEquals($this->paymentUri);
    $this->assertSession()->pageTextContains('Authorization (Voided)');

    $payment = Payment::load($payment->id());
    $this->assertEquals($payment->getState()->getLabel(), 'Authorization (Voided)');
  }

  /**
   * Tests deleting a payment after creation.
   */
  public function testPaymentDelete() {
    $payment = $this->createEntity('commerce_payment', [
      'payment_gateway' => $this->paymentGateway->id(),
      'payment_method' => $this->paymentMethod->id(),
      'order_id' => $this->order->id(),
      'amount' => new Price('10', 'USD'),
    ]);

    $this->paymentGateway->getPlugin()->createPayment($payment, FALSE);

    $this->drupalGet($this->paymentUri);
    $this->assertSession()->pageTextContains('Authorization');

    $this->drupalGet($this->paymentUri . '/' . $payment->id() . '/delete');
    $this->getSession()->getPage()->pressButton('Delete');
    $this->assertSession()->addressEquals($this->paymentUri);
    $this->assertSession()->pageTextNotContains('Authorization');

    $payment = Payment::load($payment->id());
    $this->assertNull($payment);
  }

}
