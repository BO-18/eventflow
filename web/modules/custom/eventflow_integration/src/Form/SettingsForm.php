<?php

namespace Drupal\eventflow_integration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'eventflow_integration_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      'eventflow_integration.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $config = $this->config('eventflow_integration.settings');

    $form['supplier_api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Supplier API URL'),
      '#default_value' => $config->get('supplier_api_url') ?? '',
      '#required' => TRUE,
      '#description' => $this->t(
        'URL HTTP utilisée pour envoyer les commandes payées au fournisseur.'
      ),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $url = trim($form_state->getValue('supplier_api_url'));

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName(
        'supplier_api_url',
        $this->t('Veuillez saisir une URL valide.')
      );
    }

    if (!str_starts_with(strtolower($url), 'https://')) {
      $form_state->setErrorByName(
        'supplier_api_url',
        $this->t('L URL doit utiliser HTTPS.')
      );
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $this->config('eventflow_integration.settings')
      ->set(
        'supplier_api_url',
        trim($form_state->getValue('supplier_api_url'))
      )
      ->save();

    parent::submitForm($form, $form_state);
  }

}
