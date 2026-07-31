<?php

namespace Drupal\sentrix_mail_safety\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\sentrix_mail_safety\Exception\MailSafetyVerificationException;
use Drush\Commands\DrushCommands;

/**
 * Provides the controlled Sentrix Mail Safety verification command.
 */
class SentrixMailSafetyCommands extends DrushCommands {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs the command service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Connection $database, ModuleHandlerInterface $module_handler, MailManagerInterface $mail_manager, LanguageManagerInterface $language_manager) {
    parent::__construct();
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->moduleHandler = $module_handler;
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
  }

  /**
   * Verifies non-live Mail Safety capture or Live delivery.
   *
   * @param string|null $recipient
   *   An optional recipient address; Live requires an explicit non-.invalid address.
   * @param array $options
   *   Command options.
   *
   * @command sentrix:mail-safety-test
   * @aliases sentrix-mail-safety-test
   * @option recipient An optional recipient address.
   * @option allow-live Permit a real-recipient delivery check on literal Pantheon live.
   * @usage drush sentrix:mail-safety-test
   * @usage drush sentrix:mail-safety-test <recipient>
   * @usage drush sentrix:mail-safety-test --recipient=<recipient>
   * @usage drush sentrix:mail-safety-test <recipient> --allow-live
   */
  public function mailSafetyTest($recipient = NULL, array $options = array('recipient' => NULL, 'allow-live' => FALSE)) {
    $environment = $this->environment();
    if ($environment === 'unknown') {
      throw new MailSafetyVerificationException('Refusing to run the Mail Safety verification in an unknown environment.');
    }
    if ($environment === 'live' && !$this->is_affirmative($options['allow-live'] ?? FALSE)) {
      throw new MailSafetyVerificationException('Refusing to run the Mail Safety verification on Live without --allow-live.');
    }

    $token = bin2hex(random_bytes(16));
    $recipient = $this->resolve_recipient($recipient, $options, $token, $environment === 'live');
    $subject = 'Sentrix Mail Safety verification ' . $token;
    $body = 'Controlled Mail Safety verification. Token: ' . $token;
    if ($environment !== 'live') {
      $this->assert_mail_safety_configuration();
      $last_mail_id = $this->last_dashboard_mail_id();
    }

    $result = $this->mailManager->mail(
      'sentrix_mail_safety',
      'verification',
      $recipient,
      $this->languageManager->getDefaultLanguage()->getId(),
      array(
        'subject' => $subject,
        'body' => $body,
        'token' => $token,
      )
    );

    if ($environment === 'live') {
      if (($result['send'] ?? FALSE) !== TRUE || ($result['result'] ?? FALSE) !== TRUE) {
        throw new MailSafetyVerificationException('Drupal did not accept the Live verification message for delivery.');
      }

      $this->output()->writeln('Confirm the message in the recipient inbox. Token: ' . $token . '; subject: ' . $subject . '.');
      return;
    }

    $this->assert_dashboard_capture($last_mail_id, $recipient, $subject, $body, $token);
    $this->output()->writeln('Mail Safety blocked and captured the controlled message for ' . $recipient . ' (' . $token . ').');
  }

  /**
   * Ensures that Mail Safety will block delivery and retain dashboard evidence.
   */
  private function assert_mail_safety_configuration() {
    if (!$this->moduleHandler->moduleExists('mail_safety')) {
      throw new MailSafetyVerificationException('Mail Safety must be enabled before this verification can run.');
    }

    $settings = $this->configFactory->get('mail_safety.settings');
    if (
      $settings->get('enabled') !== TRUE
      || $settings->get('send_mail_to_dashboard') !== TRUE
      || $settings->get('send_mail_to_default_mail') !== FALSE
    ) {
      throw new MailSafetyVerificationException('Mail Safety must block delivery, capture to the dashboard, and not forward to a default recipient.');
    }
    if (!$this->database->schema()->tableExists('mail_safety_dashboard')) {
      throw new MailSafetyVerificationException('Mail Safety dashboard storage is unavailable.');
    }
  }

  /**
   * Returns a normalized supplied recipient or a tokenized non-live default.
   */
  private function resolve_recipient($positional_recipient, array $options, $token, $is_live) {
    $positional_recipient = $this->normalize_recipient($positional_recipient, 'positional recipient');
    $option_recipient = $this->normalize_recipient(
      $options['recipient'] ?? NULL,
      '--recipient option'
    );

    if (
      $positional_recipient !== NULL
      && $option_recipient !== NULL
      && $positional_recipient !== $option_recipient
    ) {
      throw new \InvalidArgumentException('The positional recipient and --recipient option must match when both are supplied.');
    }

    $recipient = $positional_recipient ?? $option_recipient;
    if ($recipient === NULL) {
      if ($is_live) {
        throw new \InvalidArgumentException('Live verification requires an explicit valid recipient that does not end in .invalid.');
      }
      $recipient = 'sentrix-mail-safety-' . $token . '@example.invalid';
    }

    $is_invalid_recipient = substr(strtolower($recipient), -8) === '.invalid';
    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === FALSE) {
      throw new \InvalidArgumentException('The recipient must be a valid email address.');
    }
    if ($is_live && $is_invalid_recipient) {
      throw new \InvalidArgumentException('Live verification requires a recipient that does not end in .invalid.');
    }

    return $recipient;
  }

  /**
   * Normalizes an optional recipient value.
   */
  private function normalize_recipient($recipient, $source) {
    if ($recipient === NULL) {
      return NULL;
    }
    if (!is_string($recipient)) {
      throw new \InvalidArgumentException('The ' . $source . ' must be a string.');
    }

    $recipient = trim($recipient);
    return $recipient === '' ? NULL : $recipient;
  }

  /**
   * Determines whether an option value is explicitly affirmative.
   */
  private function is_affirmative($value) {
    return is_scalar($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN) === TRUE;
  }

  /**
   * Returns the highest dashboard mail ID before this command sends mail.
   */
  private function last_dashboard_mail_id() {
    $query = $this->database->select('mail_safety_dashboard', 'dashboard');
    $query->fields('dashboard', array('mail_id'));
    $query->orderBy('mail_id', 'DESC');
    $query->range(0, 1);
    $mail_id = $query->execute()->fetchField();

    return $mail_id === FALSE ? 0 : (int) $mail_id;
  }

  /**
   * Proves a new dashboard row contains the exact controlled message.
   */
  private function assert_dashboard_capture($last_mail_id, $recipient, $subject, $body, $token) {
    $query = $this->database->select('mail_safety_dashboard', 'dashboard');
    $query->fields('dashboard', array('mail_id', 'mail'));
    $query->condition('mail_id', $last_mail_id, '>');
    $query->orderBy('mail_id', 'DESC');
    $records = $query->execute();

    while ($record = $records->fetchAssoc()) {
      if (!isset($record['mail']) || !is_string($record['mail'])) {
        continue;
      }

      $mail = @unserialize($record['mail'], array('allowed_classes' => FALSE));
      if (!is_array($mail) || !isset($mail['params']) || !is_array($mail['params'])) {
        continue;
      }

      if (
        ($mail['send'] ?? NULL) !== FALSE
        || ($mail['to'] ?? NULL) !== $recipient
        || ($mail['module'] ?? NULL) !== 'sentrix_mail_safety'
        || ($mail['key'] ?? NULL) !== 'verification'
        || ($mail['subject'] ?? NULL) !== $subject
        || ($mail['body'] ?? NULL) !== array($body)
        || ($mail['params']['token'] ?? NULL) !== $token
      ) {
        continue;
      }

      return;
    }

    throw new MailSafetyVerificationException('Mail Safety did not capture the exact controlled message in a new dashboard row.');
  }

  /**
   * Returns the current known deployment environment.
   */
  private function environment() {
    $environment = $_ENV['PANTHEON_ENVIRONMENT'] ?? getenv('PANTHEON_ENVIRONMENT');
    if (!is_string($environment) || trim($environment) === '') {
      $environment = getenv('PANTHEON_ENVIRONMENT');
    }
    if (is_string($environment) && trim($environment) !== '') {
      return $environment === 'live' ? 'live' : 'non-live';
    }

    $ddev = $_ENV['IS_DDEV_PROJECT'] ?? getenv('IS_DDEV_PROJECT');
    if (!is_string($ddev) || $ddev === '') {
      $ddev = getenv('IS_DDEV_PROJECT');
    }
    return $ddev === 'true' ? 'non-live' : 'unknown';
  }

}
