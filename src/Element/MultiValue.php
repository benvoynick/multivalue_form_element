<?php

declare(strict_types = 1);

namespace Drupal\multivalue_form_element\Element;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\FormElement;

/**
 * Provides a form element with cardinality.
 *
 * This form element wraps other form elements.
 *
 * @todo Remove 'array_parents'
 *
 * @FormElement("multivalue")
 */
class MultiValue extends FormElement {

  /**
   * Value indicating that an instance of this element accepts unlimited values.
   */
  const CARDINALITY_UNLIMITED = -1;

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      '#input' => TRUE,
      '#theme' => 'field_multiple_value_form',
      '#cardinality_multiple' => TRUE,
      '#description' => NULL,
      '#cardinality' => self::CARDINALITY_UNLIMITED,
      '#add_more_label' => $this->t('Add another item'),
      '#process' => [
        [$class, 'processMultiValueElement'],
        [$class, 'processAjaxForm'],
      ],
    ];
  }

  /**
   * Processes a multi-value form element.
   *
   * @param array $element
   *   The element being processed.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   * @param array $complete_form
   *   The complete form.
   *
   * @return array
   *   The processed element.
   */
  public static function processMultiValueElement(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    $element_name = end($element['#array_parents']);
    $parents = $element['#parents'];
    $cardinality = $element['#cardinality'];

    $element['#tree'] = TRUE;
    $element['#field_name'] = $element_name;

    $element_state = static::getElementState($parents, $element_name, $form_state);
    if ($element_state === NULL) {
      $element_state = [
        // The initial count is always based on the default value. The default
        // value should always have numeric keys.
        'items_count' => count($element['#default_value']),
        'array_parents' => [],
      ];
      static::setElementState($parents, $element_name, $form_state, $element_state);
    }

    // Determine the number of elements to display.
    switch ($cardinality) {
      case self::CARDINALITY_UNLIMITED:
        $max = $element_state['items_count'];
        break;

      default:
        $max = $cardinality - 1;
        break;
    }

    // Extract the elements that will have to be repeated for each delta.
    $children = [];
    foreach (Element::children($element) as $child) {
      $children[$child] = $element[$child];
      unset($element[$child]);
    }

    $value = is_array($element['#value']) ? $element['#value'] : [];

    for ($i = 0; $i <= $max; $i++) {
      $element[$i] = $children;

      if (isset($value[$i])) {
        static::setDefaultValue($element[$i], $value[$i]);
      }

      $element[$i]['_weight'] = [
        '#type' => 'weight',
        '#title' => t('Weight for row @number', ['@number' => $i + 1]),
        '#title_display' => 'invisible',
        '#default_value' => $i,
        '#weight' => 100,
      ];
    }

    if ($cardinality === self::CARDINALITY_UNLIMITED && !$form_state->isProgrammed()) {
      $id_prefix = implode('-', $parents);
      $wrapper_id = Html::getUniqueId($id_prefix . '-add-more-wrapper');
      $element['#prefix'] = '<div id="' . $wrapper_id . '">';
      $element['#suffix'] = '</div>';
      $element['add_more'] = [
        '#type' => 'submit',
        '#name' => strtr($id_prefix, '-', '_') . '_add_more',
        '#value' => $element['#add_more_label'],
        '#attributes' => ['class' => ['multivalue-add-more-submit']],
        '#limit_validation_errors' => [$element['#array_parents']],
        '#submit' => [[static::class, 'addMoreSubmit']],
        '#ajax' => [
          'callback' => [static::class, 'addMoreAjax'],
          'wrapper' => $wrapper_id,
          'effect' => 'fade',
        ],
      ];
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    if ($input !== FALSE) {
      return $input;
    }

    $value = [];
    $element += ['#default_value' => []];

    $children_keys = Element::children($element, FALSE);
    $first_child = reset($children_keys);
    $children_count = count($children_keys);

    foreach ($element['#default_value'] as $delta => $default_value) {
      // Allow to omit the child element name when one single child exists and
      // - the values are simple literals. This allows to pass
      //   [0 => 'value 1', 1 => 'value 2'] instead of
      //   [0 => ['element_name' => 'value 1', 1 => ['element_name' => ...]].
      // - the value is an array but the child key is not part of it. This is
      //   useful with children that can have multiple values, like checkboxes.
      //   This syntax should be used only if there is no possibility that a
      //   default value matches the element name.
      if ($children_count === 1 && (!is_array($default_value) || (!isset($default_value[$first_child])))) {
        $value[$delta] = [$first_child => $default_value];
      }
      else {
        $value[$delta] = $default_value;
      }
    }

    return $value;
  }

  /**
   * Handles the "Add another item" button AJAX request.
   *
   * @param array $form
   *   The build form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @see \Drupal\Core\Field\WidgetBase::addMoreSubmit()
   */
  public static function addMoreSubmit(array $form, FormStateInterface $form_state): void {
    $button = $form_state->getTriggeringElement();

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));
    $element_name = $element['#field_name'];
    $parents = $element['#parents'];

    // Increment the items count.
    $element_state = static::getElementState($parents, $element_name, $form_state);
    $element_state['items_count']++;
    static::setElementState($parents, $element_name, $form_state, $element_state);

    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the "Add another item" button.
   *
   * @param array $form
   *   The build form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array|null
   *   The element.
   *
   * @see \Drupal\Core\Field\WidgetBase::addMoreAjax()
   */
  public static function addMoreAjax(array $form, FormStateInterface $form_state): ?array {
    $button = $form_state->getTriggeringElement();

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -1));

    // Ensure the widget allows adding additional items.
    if ($element['#cardinality'] != FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
      return NULL;
    }

    return $element;
  }

  /**
   * Sets the default value for the children elements.
   *
   * @param array $elements
   *   The elements array.
   * @param array $value
   *   An array of values, keyed by the children element name.
   */
  public static function setDefaultValue(array &$elements, array $value): void {
    // @todo Handle nested elements.
    foreach (Element::children($elements, FALSE) as $child) {
      if (isset($value[$child])) {
        $elements[$child]['#default_value'] = $value[$child];
      }
    }
  }

  /**
   * Retrieves processing information about the element from $form_state.
   *
   * This method is static so that it can be used in static Form API callbacks.
   *
   * @param array $parents
   *   The array of #parents where the element lives in the form.
   * @param string $element_name
   *   The field name.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   An array with the following key/value pairs:
   *   - items_count: The number of sub-elements to display for the element.
   *   - array_parents: The location of the field's widgets within the $form
   *     structure. This entry is populated at '#after_build' time.
   *
   * @see \Drupal\Core\Field\WidgetBase::getWidgetState()
   */
  public static function getElementState(array $parents, string $element_name, FormStateInterface $form_state): ?array {
    return NestedArray::getValue($form_state->getStorage(), static::getElementStateParents($parents, $element_name));
  }

  /**
   * Stores processing information about the element in $form_state.
   *
   * This method is static so that it can be used in static Form API #callbacks.
   *
   * @param array $parents
   *   The array of #parents where the element lives in the form.
   * @param string $element_name
   *   The element name.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $field_state
   *   The array of data to store. See getElementState() for the structure and
   *   content of the array.
   *
   * @see \Drupal\Core\Field\WidgetBase::setWidgetState()
   */
  public static function setElementState(array $parents, string $element_name, FormStateInterface $form_state, array $field_state): void {
    NestedArray::setValue($form_state->getStorage(), static::getElementStateParents($parents, $element_name), $field_state);
  }

  /**
   * Returns the location of processing information within $form_state.
   *
   * @param array $parents
   *   The array of #parents where the element lives in the form.
   * @param string $element_name
   *   The element name.
   *
   * @return array
   *   The location of processing information within $form_state.
   *
   * @see \Drupal\Core\Field\WidgetBase::getWidgetStateParents()
   */
  protected static function getElementStateParents(array $parents, string $element_name): array {
    // phpcs:disable
    // Element processing data is placed at
    // $form_state->get(['multivalue_form_element_storage', '#parents', ...$parents..., '#elements', $element_name]),
    // to avoid clashes between field names and $parents parts.
    // phpcs:enable
    return array_merge(['multivalue_form_element_storage', '#parents'], $parents, ['#elements', $element_name]);
  }

}
