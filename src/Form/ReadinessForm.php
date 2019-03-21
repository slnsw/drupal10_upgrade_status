<?php

namespace Drupal\upgrade_status\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\upgrade_status\DeprecationAnalyser;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ReadinessForm extends FormBase {

  /**
   * @var \Drupal\upgrade_status\DeprecationAnalyser
   */
  protected $deprecationAnalyser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('upgrade_status.deprecation_analyser')
    );
  }

  /**
   * ReadinessForm constructor.
   *
   * @param \Drupal\upgrade_status\DeprecationAnalyser $deprecationAnalyser
   */
  public function __construct(DeprecationAnalyser $deprecationAnalyser) {
    $this->deprecationAnalyser = $deprecationAnalyser;
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
    $this->deprecationAnalyser->analyse();
  }

}
