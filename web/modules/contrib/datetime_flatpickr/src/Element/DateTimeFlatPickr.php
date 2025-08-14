<?php

namespace Drupal\datetime_flatpickr\Element;

use Drupal\Core\Render\Attribute\FormElement;
use Drupal\Core\Form\FormStateInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\Core\Render\Element\Textfield;
use Drupal\datetime_flatpickr\Plugin\Field\FieldWidget\DateTimeFlatPickrWidgetTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\datetime_flatpickr\Constants\AvailableLanguages;

/**
 * DateTime FlatPickr
 */
#[FormElement('datetime_flatpickr')]
class DateTimeFlatPickr extends Textfield {

  use DateTimeFlatPickrWidgetTrait;

  /**
   * @return array
   */
  public function getInfo() {
    $class = static::class;
    $info = parent::getInfo();
    $info['#process'][] = [$class, 'processDateTimeFlatPicker'];
    return $info;
  }

  /**
   * @param $element
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return mixed
   */
  public static function processDateTimeFlatPicker(&$element, FormStateInterface $form_state) {
    $name = $element['#name'];
    $element['#attributes']['flatpickr-name'] = $name;
    return $element;
  }


  /**
   * @param array $element
   *
   * @return array
   */
  public static function preRenderTextfield($element) {
    $element = parent::preRenderTextfield($element);
    $element['#attached']['library'][] = 'datetime_flatpickr/flatpickr-init';
    $lang_code = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $settings = self::getElementSettings($element);
    if (in_array($lang_code, AvailableLanguages::LANGUAGES)) {
      $element['#attached']['library'][] = 'datetime_flatpickr/flatpickr_' . mb_strtolower($lang_code);
      $settings['locale'] = $lang_code;
    }

    $element['#attached']['drupalSettings']['datetimeFlatPickr'][$element['#name']] = [
      'settings' => $settings,
    ];
    return $element;
  }

}
