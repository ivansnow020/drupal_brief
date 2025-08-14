<?php

namespace Drupal\appointment_booking\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use DateTime;
use DateTimeZone;
use Drupal\Core\Url;

/**
 * Implements the appointment booking form.
 */
class AppointmentForm extends FormBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs an AppointmentForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * The messenger service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MessengerInterface $messenger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   *
   * Dependency injection to get services.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('messenger')
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
      ];
    }

    // Datetime selection
    $form['appointment_date'] = [
        '#type' => 'date',
        '#title' => $this->t('Appointment Date'),
        '#required' => TRUE,
        // Prevent picking past dates
        '#attributes' => ['min' => (new DateTime())->format('Y-m-d')],
      ];

    // Generate 30-minute time slots for a select list.
    $time_slots = [];
    $start_time = new DateTime('07:00');
    $end_time = new DateTime('17:30');
    while ($start_time <= $end_time) {
        $time_key = $start_time->format('H:i');
        $time_slots[$time_key] = $time_key;
        $start_time->modify('+30 minutes');
    }

    $form['appointment_time'] = [
        '#type' => 'select',
        '#title' => $this->t('Appointment Time'),
        '#options' => $time_slots,
        '#required' => TRUE,
        '#empty_option' => $this->t('- Select a time -'),
    ];

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
 * {@inheritdoc}
 */
public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  
    // Get the separate date and time values.
    $date_str = $form_state->getValue('appointment_date');
    $time_str = $form_state->getValue('appointment_time');
  
    if (empty($date_str) || empty($time_str)) {
      return;
    }
  
    try {
      $timezone = new \DateTimeZone('CET');
      // Combine the date and time strings to create a full DateTime object.
      $appointment_datetime = new \DateTime($date_str . ' ' . $time_str, $timezone);
  
      // Validate that the day is a weekday (Monday=1, Sunday=7).
      $day_of_week = (int) $appointment_datetime->format('N');
      if ($day_of_week >= 6) {
        $form_state->setErrorByName('appointment_date', $this->t('Appointments can only be booked on weekdays.'));
      }
    } catch (\Exception $e) {
      $form_state->setErrorByName('appointment_date', $this->t('The provided date or time format is invalid.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    
    // Save / send the submission data here.

    $this->messenger->addStatus($this->t('Thank you! Your appointment request has been submitted.'));
    $url = Url::fromUri('internal:/doctors');
    $form_state->setRedirectUrl($url);
  }

}