<?php

namespace Drupal\upgrade_status\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CancelScanForm extends FormBase {

  /**
   * The queue service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queue;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('state')
    );
  }

  /**
   * Constructs a Drupal\upgrade_status\Form\UpgradeStatusForm.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue
   * @param \Drupal\Core\State\StateInterface $state
   */
  public function __construct(
    QueueFactory $queue,
    StateInterface $state
  ) {
    $this->queue = $queue->get('upgrade_status_deprecation_worker');
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'upgrade_status_cancel_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['upgrade_status_cancel_form']['description'] = [
      '#type' => 'fieldgroup',
      'description_text' => [
        '#type' => 'markup',
        '#markup' => $this->t('This action will cancel the scan and clear the current data. Are you sure you want to cancel this scan?'),
      ],
    ];

    $form['upgrade_status_cancel_form']['actions'] = [
      '#type' => 'actions',
    ];

    $form['upgrade_status_cancel_form']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel scan'),
      '#weight' => 10,
      '#button_type' => 'primary',
      '#name' => 'cancel',
    ];

    return $form;
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->queue->deleteQueue();
    $this->state->delete('upgrade_status.number_of_jobs');

    $form_state->setRedirect('upgrade_status.report');
  }

}
