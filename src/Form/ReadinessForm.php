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
    $data = $form_state->get('deprecation_data');

    $form['drupal_readiness_form'] = [
      'response' => [
        '#type' => 'details',
        '#title' => $this->t('Response'),
        '#tree' => TRUE,
        '#open' => TRUE,
        'data' => [
          '#type' => '#markup',
          '#markup' => $data,
        ],
      ],
    ];

    $form['drupal_readiness_form']['action']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Analyse'),
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
    $form_state->setRebuild(TRUE);

    $form_state->set('deprecation_data', $this->deprecationAnalyser->analyse());
  }

}
