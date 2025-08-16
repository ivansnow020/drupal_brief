<?php

namespace Drupal\appointment_booking\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use DateTime;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\node\NodeInterface;


/**
 * Implements the appointment booking form.
 */
class AppointmentForm extends FormBase {

 
  protected EntityTypeManagerInterface $entityTypeManager;

  protected MessengerInterface $messenger;

  protected DateTimeZone $siteTimezone;

  protected DateFormatterInterface $dateFormatter;
  
  /**
   * Constructs an AppointmentForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * The messenger service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger, DateFormatterInterface $date_formatter,  ConfigFactoryInterface $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
    $this->dateFormatter = $date_formatter;
    $this->configFactory = $config_factory;
    $timezone_name = $this->configFactory->get('system.date')->get('timezone.default');
    $this->siteTimezone = new DateTimeZone($timezone_name);
  }

  /**
   * {@inheritdoc}
   *
   * Dependency injection to get services.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('messenger'),
      $container->get('date.formatter'),
      $container->get('config.factory') 
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'appointment_booking_form';
  }


  public function buildForm(array $form, FormStateInterface $form_state) {
    // Attach CSS library
    $form['#attached']['library'][] = 'appointment_booking/form-styling';

    // Handle pre-selection of a doctor from the URL query parameter.
    $doctor_id = $this->getRequest()->query->get('doctor');
    $doctor_storage = $this->entityTypeManager->getStorage('node');

    if ($doctor_id && is_numeric($doctor_id) && $doctor = $doctor_storage->load($doctor_id)) {
      // If a valid doctor is found, display their name and store the ID in a hidden field.
      $form['doctor_id'] = ['#type' => 'hidden', '#value' => $doctor_id];
      $form['doctor_name'] = [
        '#type' => 'item',
        '#markup' => $this->t('Booking an appointment with: <strong>@name</strong>', ['@name' => $doctor->getTitle()]),
      ];
    } else {
      // If no doctor is pre-selected, show a dropdown list.
      $query = $doctor_storage->getQuery()
        ->condition('type', 'doctor') 
        ->condition('status', 1)
        ->accessCheck(TRUE)
        ->sort('title', 'ASC');
      $nids = $query->execute();
      $doctors = $doctor_storage->loadMultiple($nids);
      $options = [];
      foreach ($doctors as $doctor) {
        $options[$doctor->id()] = $doctor->getTitle();
      }

      $form['doctor_id'] = [
        '#type' => 'select',
        '#title' => $this->t('Select a Doctor'),
        '#options' => $options,
        '#required' => TRUE,
        '#empty_option' => $this->t('- Please select -'),
        '#ajax' => [
          'callback' => '::updateTimeSlotsCallback',
          'wrapper' => 'appointment-time-wrapper',
        ],
      ];
    }

    // Datetime selection
    $form['appointment_date'] = [
        '#type' => 'date',
        '#title' => $this->t('Appointment Date'),
        '#required' => TRUE,
        // Prevent picking past dates
        '#attributes' => ['min' => (new DateTime())->format('Y-m-d')],
        '#ajax' => [
        'callback' => '::updateTimeSlotsCallback',
        'wrapper' => 'appointment-time-wrapper',
        ],
      ];

    $form['appointment_time_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'appointment-time-wrapper'],
    ];
    $form['appointment_time_wrapper']['appointment_time'] = $this->getTimeSlotsElement($form_state);


    // User detail fields.
    $form['your_name'] = ['#type' => 'textfield', '#title' => $this->t('Your Name'), '#required' => TRUE];
    $form['your_email'] = ['#type' => 'email', '#title' => $this->t('Your Email'), '#required' => TRUE];
    $form['appointment_reason'] = ['#type' => 'textarea', '#title' => $this->t('Reason for Appointment')];

    // Submit button.
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Request Appointment'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * AJAX callback to update the available time slots.
   */
  public function updateTimeSlotsCallback(array &$form, FormStateInterface $form_state): AjaxResponse {
    return (new AjaxResponse())->addCommand(
      new ReplaceCommand('#appointment-time-wrapper', $form['appointment_time_wrapper'])
    );
  }
  
  /**
   * Helper function to generate the time slots form element.
   */
  protected function getTimeSlotsElement(FormStateInterface $form_state): array {
    $doctor_id = $form_state->getValue('doctor_id');
    $date_str = $form_state->getValue('appointment_date');
    
    if (empty($doctor_id) || empty($date_str)) {
        return [
            '#type' => 'select',
            '#title' => $this->t('Appointment Time'),
            '#options' => [],
            '#disabled' => TRUE,
            '#required' => TRUE,
            '#description' => $this->t('Please select a doctor and a date first.'),
        ];
    }
    
    $booked_slots = $this->getBookedTimeSlots($doctor_id, $date_str);
    $now = new DateTime('now', $this->siteTimezone);
    $time_slots = [];
    
    // Generate potential slots and their status
    $current_slot_time = new DateTime('07:00');
    $end_time = new DateTime('17:30');
    while ($current_slot_time <= $end_time) {
        $time_key = $current_slot_time->format('H:i');
        $slot_datetime = DateTime::createFromFormat('Y-m-d H:i', $date_str . ' ' . $time_key, $this->siteTimezone);

        $is_past = $slot_datetime < $now;
        $is_booked = in_array($time_key, $booked_slots);
        
        $option = ['text' => $time_key, 'disabled' => FALSE];
        if ($is_booked) {
            $option = ['text' => "$time_key - Booked", 'disabled' => TRUE];
        } 
        elseif ($is_past) {
            $option = ['text' => "$time_key - Unavailable", 'disabled' => TRUE];
        }
        $time_slots[$time_key] = $option;
        
        $current_slot_time->modify('+30 minutes');
    }

    $element = [
        '#type' => 'select',
        '#title' => $this->t('Appointment Time'),
        '#required' => TRUE,
        '#empty_option' => $this->t('- Select a time -'),
        '#options' => [],
        '#disabled_values' => []
    ];

    // Build options with disabled attributes where necessary.
    foreach ($time_slots as $key => $option) {
        if ($option['disabled']) {
            array_push($element['#disabled_values'], $key);
        } else {
            $element['#options'][$key] = $option['text'];
        }
    }

    return $element;
  }

  /**
   * Helper function to query for existing appointments.
   */
  protected function getBookedTimeSlots(int $doctor_id, string $date_str): array {
    $start_of_day = DateTime::createFromFormat('Y-m-d H:i:s', "$date_str 00:00:00", $this->siteTimezone);
    $end_of_day = DateTime::createFromFormat('Y-m-d H:i:s', "$date_str 23:59:59", $this->siteTimezone);
    
    // Convert to UTC for the database query.
    $start_of_day->setTimezone(new DateTimeZone('UTC'));
    $end_of_day->setTimezone(new DateTimeZone('UTC'));

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'appointment')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_doctor', $doctor_id)
      ->condition('field_appointment_datetime', $start_of_day->format('Y-m-d\TH:i:s'), '>=')
      ->condition('field_appointment_datetime', $end_of_day->format('Y-m-d\TH:i:s'), '<=')
      ->accessCheck(TRUE);
    
    $nids = $query->execute();
    if (empty($nids)) {
      return [];
    }
    
    $appointments = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    $booked_slots = [];
    foreach ($appointments as $appointment) {
      $datetime_value = $appointment->get('field_appointment_datetime')->value;
      $date = new DateTime($datetime_value, new DateTimeZone('UTC'));
      $date->setTimezone($this->siteTimezone);
      $booked_slots[] = $date->format('H:i');
    }
    
    return $booked_slots;
  }

  /**
 * {@inheritdoc}
 */
public function validateForm(array &$form, FormStateInterface $form_state) {
  parent::validateForm($form, $form_state);

  $date_str = $form_state->getValue('appointment_date');
  $time_str = $form_state->getValue('appointment_time');
  $doctor_id = $form_state->getValue('doctor_id');

  if (empty($date_str) || empty($time_str) || empty($doctor_id)) return;
  
  try {
    $appointment_datetime = DateTime::createFromFormat('Y-m-d H:i', "$date_str $time_str", $this->siteTimezone);
    if (!$appointment_datetime) throw new \Exception('Invalid date/time format.');

    // Validate past bookings.
    if ($appointment_datetime < new DateTime('now', $this->siteTimezone)) {
        $form_state->setErrorByName('appointment_time', $this->t('You cannot book an appointment in the past.'));
    }

    // Validate weekday booking.
    if ((int) $appointment_datetime->format('N') >= 6) {
      $form_state->setErrorByName('appointment_date', $this->t('Appointments can only be booked on weekdays.'));
    }

    // Validate duplicate bookings 
    $appointment_datetime_utc = (clone $appointment_datetime)->setTimezone(new DateTimeZone('UTC'));
    
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'appointment')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_doctor', $doctor_id)
      ->condition('field_appointment_datetime', $appointment_datetime_utc->format('Y-m-d\TH:i:s'))
      ->accessCheck(TRUE);
    
    if ($query->count()->execute() > 0) {
      $form_state->setErrorByName('appointment_time', $this->t('This time slot is no longer available. Please select another time.'));
    }
  } 
  catch (\Exception $e) {
    $form_state->setErrorByName('appointment_date', $this->t('The provided date or time format is invalid.'));
  }
}

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
        $values = $form_state->getValues();
        $appointment_datetime = DateTime::createFromFormat('Y-m-d H:i', $values['appointment_date'] . ' ' . $values['appointment_time'], $this->siteTimezone);
        $appointment_datetime->setTimezone(new DateTimeZone('UTC'));

        $doctor_node = $this->entityTypeManager->getStorage('node')->load($values['doctor_id']);

        $appointment = $this->entityTypeManager->getStorage('node')->create([
            'type' => 'appointment',
            'title' => $this->t('Appointment for @name with @doctor', [
                '@name' => $values['your_name'],
                '@doctor' => $doctor_node ? $doctor_node->getTitle() : 'N/A',
            ]),
            'status' => NodeInterface::PUBLISHED,
            'field_doctor' => $values['doctor_id'],
            'field_appointment_datetime' => $appointment_datetime->format('Y-m-d\TH:i:s'),
            'field_patient_name' => $values['your_name'],
            'field_patient_email' => $values['your_email'],
            'field_appointment_reason' => $values['appointment_reason'],
        ]);
        $appointment->save();

        $this->messenger->addStatus($this->t('Thank you, @name! Your appointment for @time has been confirmed.', [
            '@name' => $values['your_name'],
            '@time' => $this->dateFormatter->format($appointment_datetime->getTimestamp(), 'long'),
        ]));
        
        $form_state->setRedirectUrl(Url::fromRoute('entity.node.canonical', ['node' => $values['doctor_id']]));
    } catch (\Exception $e) {
        $this->messenger->addError($this->t('There was an error saving your appointment. Please try again.'));
    }
  }

}