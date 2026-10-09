<?php

namespace justinholtweb\my\helpers;

use Craft;
use craft\mail\Message;
use craft\web\View;
use Throwable;

/**
 * Renders a plugin template into an HTML and a plain-text email and sends it.
 *
 * Copied from craft-yarn, the family reference for scheduled email reports. `$template` names the
 * HTML template; the text version is the same name with `.txt` appended (`my/_emails/digest` →
 * `digest.twig` and `digest.txt.twig`). Both render in control-panel template mode, so they
 * resolve from the plugin's own `templates/` whatever the request is — web, console or queue.
 *
 * One message per recipient: the people on a summary list do not necessarily know each other, and
 * putting them all in one `To:` tells each of them who else gets it.
 */
final class Mailer
{
    /**
     * @param string[] $recipients
     * @param array<string, mixed> $variables
     * @return int How many recipients the mailer accepted the message for.
     */
    public static function send(array $recipients, string $subject, string $template, array $variables = []): int
    {
        $view = Craft::$app->getView();
        $html = $view->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP);
        $text = $view->renderTemplate($template . '.txt', $variables, View::TEMPLATE_MODE_CP);
        $sent = 0;

        foreach (array_unique($recipients) as $recipient) {
            $message = (new Message())
                ->setTo($recipient)
                ->setSubject($subject)
                ->setHtmlBody($html)
                ->setTextBody($text);

            try {
                if (Craft::$app->getMailer()->send($message)) {
                    $sent++;
                    continue;
                }

                Craft::warning("The mailer refused a message to $recipient: $subject", 'my');
            } catch (Throwable $e) {
                // One bad address or a transport hiccup should not cost every other recipient
                // their copy.
                Craft::warning("Could not email $recipient: " . $e->getMessage(), 'my');
            }
        }

        return $sent;
    }
}
