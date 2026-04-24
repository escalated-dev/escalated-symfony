<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Thrown by {@see SESInboundParser::parse()} when the webhook
 * receives an SNS subscription-confirmation envelope. The host app
 * must fetch {@see $subscribeUrl} out-of-band to activate the
 * subscription; the inbound controller catches this and returns
 * 202 Accepted so AWS stops retrying the confirmation POST.
 */
final class SESSubscriptionConfirmationException extends \RuntimeException
{
    public function __construct(
        public readonly string $topicArn,
        public readonly string $subscribeUrl,
        public readonly string $token,
    ) {
        parent::__construct(sprintf(
            'SES subscription confirmation for topic %s; GET %s to confirm',
            $topicArn,
            $subscribeUrl,
        ));
    }
}
