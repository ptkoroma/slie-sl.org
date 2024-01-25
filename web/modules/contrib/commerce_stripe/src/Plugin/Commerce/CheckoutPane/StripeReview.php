<?php

namespace Drupal\commerce_stripe\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripePaymentElementInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripeInterface;
use Drupal\commerce_stripe\ErrorHelper;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;

/**
 * Adds payment intent confirmation for Stripe.
 *
 * This checkout pane is required. It ensures that the last step in the checkout
 * performs authentication and confirmation of the payment intent. If the
 * customer's card is not enrolled in 3DS then the form will submit as normal.
 * Otherwise a modal will appear for the customer to authenticate and approve
 * of the charge.
 *
 * @CommerceCheckoutPane(
 *   id = "stripe_review",
 *   label = @Translation("Stripe review"),
 *   default_step = "review",
 *   wrapper_element = "container",
 * )
 */
class StripeReview extends CheckoutPaneBase {

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'button_id' => 'edit-actions-next',
      'auto_submit_review_form' => FALSE,
      'setup_future_usage' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationSummary() {
    $summary[] = $this->t('Button id is @id', ['@id' => $this->configuration['button_id']]);

    if (empty($this->configuration['auto_submit_review_form'])) {
      $summary[] = $this->t('Auto submit: Off');
    }
    else {
      $summary[] = $this->t('Auto submit: On');
    }

    switch ($this->configuration['setup_future_usage']) {
      case 'on_session':
        $summary[] = $this->t('Setup future usage: On session');
        break;

      case 'off_session':
        $summary[] = $this->t('Setup future usage: Off session');
        break;

      case '':
        $summary[] = $this->t('Setup future usage: None');
    }

    return implode('<br>', $summary);
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['button_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button HTML ID'),
      '#description' => $this->t('The HTML ID of the button that submits the checkout form. Change this if you have customized the default submit button.'),
      '#default_value' => $this->configuration['button_id'],
    ];

    $form['auto_submit_review_form'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically submit the review form'),
      '#description' => $this->t('When this is checked, the form the Stripe Review pane is displayed in will automatically submit on load. This starts the SCA process without user intervention.'),
      '#default_value' => $this->configuration['auto_submit_review_form'],
    ];

    $form['setup_future_usage'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Setup future usage'),
      '#options'       => [
        'on_session'  => 'On session',
        'off_session' => 'Off session',
      ],
      '#empty_value'   => '',
      '#default_value' => $this->getConfiguration()['setup_future_usage'],
      '#description'   => 'The intended future usage of the provided payment method. See <a href="https://stripe.com/docs/payments/payment-intents#future-usage">Stripe documentation</a>.',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['button_id'] = $values['button_id'];
      $this->configuration['auto_submit_review_form'] = !empty($values['auto_submit_review_form']);
      $this->configuration['setup_future_usage'] = $values['setup_future_usage'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible() {
    $gateway = $this->order->get('payment_gateway');
    if ($gateway->isEmpty() || empty($gateway->entity)) {
      return FALSE;
    }
    if ($this->order->isPaid() || $this->order->getTotalPrice()->isZero()) {
      // The order total might have been changed to zero, do not add the review
      // pane as that might lead to an incorrect payment confirmation.
      return FALSE;
    }

    $plugin = $gateway->entity->getPlugin();
    return $plugin instanceof StripeInterface || $plugin instanceof StripePaymentElementInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    // The only point of this pane is passing the stripe payment intent ID (and
    // some other data) to js when first loading the page or during ajax
    // requests (and not when submitting the form).
    if ((!empty($form_state->getValues()) || !empty($form_state->getUserInput())) &&
      !isset($form_state->getUserInput()['_drupal_ajax'])) {
      return $pane_form;
    }

    $intent_id = $this->order->getData('stripe_intent');
    /** @var \Drupal\commerce_stripe\Plugin\Commerce\PaymentGateway\StripeInterface $stripe_plugin */
    $stripe_plugin = $this->order->get('payment_gateway')->entity->getPlugin();

    if ($intent_id !== NULL) {
      try {
        $intent = PaymentIntent::retrieve($intent_id);
      }
      catch (ApiErrorException $e) {
        ErrorHelper::handleException($e);
      }
    }
    else {
      $payment_process_pane = $this->checkoutFlow->getPane('payment_process');
      assert($payment_process_pane instanceof CheckoutPaneInterface);
      $intent_attributes = [
        'capture_method' => $payment_process_pane->getConfiguration()['capture'] ? 'automatic' : 'manual',
      ];
      // Set the setup_future_usage parameter for the Stripe Card Element.
      if (
        $stripe_plugin instanceof StripeInterface &&
        !empty($this->getConfiguration()['setup_future_usage'])
      ) {
        $intent_attributes['setup_future_usage'] = $this->getConfiguration()['setup_future_usage'];
      }
      // Set the setup_future_usage parameter for the Stripe Payment Element.
      if ($stripe_plugin instanceof StripePaymentElementInterface) {
        $intent_attributes['setup_future_usage'] = $stripe_plugin->getPaymentMethodUsage();
      }
      $intent = $stripe_plugin->createPaymentIntent($this->order, $intent_attributes);
    }
    if (
      !$this->order->get('payment_method')->isEmpty() &&
      $intent->status === PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD
    ) {
      $payment_method = $this->order->get('payment_method')->entity;
      assert($payment_method instanceof PaymentMethodInterface);
      $payment_method_remote_id = $payment_method->getRemoteId();
      $intent = PaymentIntent::update($intent->id, [
        'payment_method' => $payment_method_remote_id,
      ]);
    }

    // To display validation errors.
    $pane_form['payment_errors'] = [
      '#type' => 'markup',
      '#markup' => '<div id="payment-errors"></div>',
      '#weight' => -200,
    ];

    // Inject the Stripe Card Element form.
    if ($stripe_plugin instanceof StripeInterface) {
      $auto_submit = !empty($this->configuration['auto_submit_review_form']);
      if ($auto_submit) {
        // Add a class to the checkout pane and the form actions.
        $class = 'stripe-review-autosubmit';
        $pane_form['#attributes']['class'][] = $class;
        $complete_form['actions']['#attributes']['class'][] = $class;
        // Add spinner and overlay elements to the pane.
        $pane_form['autosubmit_loader'] = [
          '#markup' => '<div class="' . $class . '-loader"></div>',
        ];
        // Disable the form submit button.
        $complete_form['actions']['next']['#attributes']['disabled'] = 'disabled';
      }

      $pane_form['#attached']['library'][] = 'commerce_stripe/checkout_review';
      $pane_form['#attached']['drupalSettings']['commerceStripe'] = [
        'publishableKey' => $stripe_plugin->getPublishableKey(),
        'clientSecret' => $intent->client_secret,
        'buttonId' => $this->configuration['button_id'],
        'orderId' => $this->order->id(),
        'paymentMethod' => $intent->payment_method,
        'autoSubmitReviewForm' => $auto_submit,
      ];
      $profiles = $this->order->collectProfiles();
      if (isset($profiles['shipping']) && !$profiles['shipping']->get('address')->isEmpty()) {
        $pane_form['#attached']['drupalSettings']['commerceStripe']['shipping'] = $profiles['shipping']->get('address')->first()->toArray();
      }
    }

    // Inject the Stripe Payment Element form.
    if ($stripe_plugin instanceof StripePaymentElementInterface) {
      $config = $stripe_plugin->getConfiguration();
      $pane_form['#attributes']['class'][] = 'stripe-payment-element-form';
      $pane_form['#attached']['library'][] = 'commerce_stripe/payment_element';
      $element_id = Html::getUniqueId('stripe-payment-element');
      $pane_form['#attached']['drupalSettings']['commerceStripePaymentElement'] = [
        'publishableKey' => $stripe_plugin->getPublishableKey(),
        'clientSecret' => $intent->client_secret,
        'paymentMethod' => !empty($intent->payment_method) ? $intent->payment_method : NULL,
        'returnUrl' => Url::fromRoute('commerce_payment.checkout.return', [
          'commerce_order' => $this->order->id(),
          'step' => 'review',
        ], ['absolute' => TRUE])->toString(),
        'elementId' => $element_id,
        'showPaymentForm' => $this->order->get('payment_method')->isEmpty(),
        'createElementsOptions' => [
          'clientSecret' => $intent->client_secret,
          'appearance' => [
            'theme' => !empty($config['style']) ? $config['style']['theme'] : 'stripe',
          ],
        ],
        'paymentElementOptions' => [
          'layout' => !empty($config['style']) ? $config['style']['layout'] : 'tabs',
        ],
      ];
      $profiles = $this->order->collectProfiles();
      if (isset($profiles['billing']) && !$profiles['billing']->get('address')->isEmpty()) {
        $billing_address = $profiles['billing']->get('address')->first()->toArray();
        $pane_form['#attached']['drupalSettings']['commerceStripePaymentElement']['paymentElementOptions']['defaultValues']['billingDetails'] = [
          'name' => $billing_address['given_name'] . ' ' . $billing_address['family_name'],
          'address' => [
            'city' => $billing_address['locality'],
            'country' => $billing_address['country_code'],
            'line1' => $billing_address['address_line1'],
            'line2' => $billing_address['address_line2'],
            'postal_code' => $billing_address['postal_code'],
            'state' => $billing_address['administrative_area'],
          ],
        ];
      }

      $pane_form['stripe_payment_element'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => $element_id,
        ],
      ];
    }

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($this->order);
    $cacheability->setCacheMaxAge(0);
    $cacheability->applyTo($pane_form);

    return $pane_form;
  }

}
