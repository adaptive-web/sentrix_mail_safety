<?php

namespace Drupal\sentrix_mail_safety\Commands;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Provides a simple test-mail drush command.
 */
class SentrixMailSafetyCommands extends DrushCommands {

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Constructs the command service.
   */
  public function __construct(MailManagerInterface $mail_manager) {
    parent::__construct();
    $this->mailManager = $mail_manager;
  }

  /**
   * Sends a test email to the given recipient.
   *
   * @param string|null $recipient
   *   The email address to send the test message to.
   * @param array $options
   *   Command options.
   *
   * @command sentrix:mail-safety-test
   * @aliases sentrixmailtest
   * @option recipient The email address to send the test message to.
   * @usage drush sentrix:mail-safety-test someone@example.com
   * @usage drush sentrix:mail-safety-test --recipient=someone@example.com
   */
  public function mailSafetyTest($recipient = NULL, array $options = array('recipient' => NULL)) {
    $recipient = $recipient ?? $options['recipient'] ?? NULL;
    if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === FALSE) {
      throw new \InvalidArgumentException('You must supply a valid recipient email address, either as an argument or via --recipient=.');
    }

    $token = bin2hex(random_bytes(8));
    $subject = 'Sentrix Mail Safety test ' . $token;
    $body = 'This is a test email sent via the sentrix:mail-safety-test drush command. Token: ' . $token;

    $this->mailManager->mail(
      'sentrix_mail_safety',
      'verification',
      $recipient,
      LanguageInterface::LANGCODE_NOT_SPECIFIED,
      array(
        'subject' => $subject,
        'body' => $body,
      )
    );

    $this->output()->writeln('Test email sent to ' . $recipient . ' (' . $token . ').');
  }

}
