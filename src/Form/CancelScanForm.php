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
   * @param \Drupal\upgrade_status\ProjectCollector $projectCollector
   * @param \Drupal\Core\Queue\QueueFactory $queue
   * @param \Drupal\Core\State\StateInterface $state
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   */
  public function __construct(
    QueueFactory $queue,
    StateInterface $state
  ) {
    $this->queue = $queue->get('upgrade_status_deprecation_worker');
    $this->state = $state;
  }

  /**
   * Returns a unique string identifying the form.
   *
   * The returned ID should be a unique string that can be a valid PHP function
   * name, since it's used in hook implementation names such as
   * hook_form_FORM_ID_alter().
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'upgrade_status_cancel_form';
  }

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['upgrade_status_cancel_form']['description'] = [
      '#type' => 'markup',
      '#markup' => t('This action will cancel the scan and clear the current data. Are you sure you want to cancel this scan?'),
    ];

    $form['upgrade_status_cancel_form']['action']['submit'] = [
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
    $this->state->delete('upgrade_status.number_of_jobs');  }
}
