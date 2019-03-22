<?php

namespace Drupal\upgrade_status\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\upgrade_status\ProjectCollector;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ReadinessForm extends FormBase {

  /**
   * @var \Drupal\upgrade_status\ProjectCollector
   */
  protected $projectCollector;

  /**
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queue;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('upgrade_status.project_collector'),
      $container->get('queue')
    );
  }

  /**
   * ReadinessForm constructor.
   *
   * @param \Drupal\upgrade_status\ProjectCollector $projectCollector
   * @param \Drupal\Core\Queue\QueueFactory $queue
   */
  public function __construct(
    ProjectCollector $projectCollector,
    QueueFactory $queue
  ) {
    $this->projectCollector = $projectCollector;
    $this->queue = $queue->get('upgrade_status_deprecation_worker');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'drupal_readiness_form';
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
    $form['drupal_readiness_form']['action']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start full scan'),
      '#weight' => 0,
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
    $projects = $this->projectCollector->collectProjects();

    foreach ($projects['custom'] as $projectData) {
      $this->queue->createItem($projectData);
    }

    foreach ($projects['contrib'] as $projectData) {
      $this->queue->createItem($projectData);
    }
  }

}
