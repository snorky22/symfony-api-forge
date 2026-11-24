<?php

namespace Snorky22\ApiForge\Dev\Webhook;

use function Symfony\Component\String\u;

/**
 * Writes the code of hand-written webhooks (see Snorky22\ApiForge\Core\Webhook): a request parser made of constants,
 * from a signature scheme of SCHEMES, and a consumer with one method per event, completed later with new events.
 */
final class WebhookMaker
{
    /**
     * The signature schemes, enriched with experience: the parser constants of each, and the events of the provider
     * it comes from (suggested when asking for events). "custom" asks for the constants.
     *
     * @var array<string, array{label: string, docs: ?string, constants: array<string, mixed>, events: list<string>}>
     */
    public const SCHEMES = [
        'paddle' => [
            'label' => 'Paddle-Signature: ts=…;h1=…, HMAC-SHA256 of "timestamp:body"; answered with 200 (Paddle)',
            'docs' => 'https://developer.paddle.com/webhooks/about/signature-verification',
            'constants' => [
                'SIGNATURE_HEADER' => 'Paddle-Signature',
                'SIGNATURE_CONTENT' => '{timestamp}:{body}',
                'SIGNATURE_PARTS' => ['separator' => ';', 'timestamp' => 'ts', 'signature' => 'h1'],
                // Paddle's SDKs: 5 seconds; raise it if the server clock drifts
                'SIGNATURE_TOLERANCE' => 5,
                'EVENT_NAME_PATH' => 'event_type',
                'EVENT_ID_PATH' => 'event_id',
                // Paddle retries a delivery answered with another status than 200
                'SUCCESS_STATUS' => 200,
            ],
            'events' => [
                'transaction.created', 'transaction.ready', 'transaction.paid', 'transaction.completed',
                'transaction.updated', 'transaction.payment_failed', 'transaction.past_due', 'transaction.canceled',
                'subscription.created', 'subscription.activated', 'subscription.trialing', 'subscription.updated',
                'subscription.past_due', 'subscription.paused', 'subscription.resumed', 'subscription.canceled',
                'customer.created', 'customer.updated', 'adjustment.created', 'adjustment.updated',
            ],
        ],
        'lemonsqueezy' => [
            'label' => 'X-Signature: HMAC-SHA256 of the body, event in meta.event_name (Lemon Squeezy)',
            'docs' => null,
            'constants' => [
                'SIGNATURE_HEADER' => 'X-Signature',
                'EVENT_NAME_PATH' => 'meta.event_name',
            ],
            'events' => [
                'order_created', 'order_refunded', 'customer_updated', 'subscription_created', 'subscription_updated',
                'subscription_cancelled', 'subscription_resumed', 'subscription_expired', 'subscription_paused',
                'subscription_unpaused', 'subscription_payment_success', 'subscription_payment_failed',
                'subscription_payment_recovered', 'subscription_payment_refunded', 'license_key_created',
                'license_key_updated', 'affiliate_activated',
            ],
        ],
        'custom' => [
            'label' => 'another scheme: answer the questions (header, signed content, event name…)',
            'docs' => null,
            'constants' => [],
            'events' => [],
        ],
    ];

    /**
     * The parser constants, in the order they are written; the defaults of AbstractSignedRequestParser are left out.
     */
    private const CONSTANTS = [
        'SIGNATURE_HEADER' => null,
        'SIGNATURE_ALGORITHM' => 'sha256',
        'SIGNATURE_ENCODING' => 'hex',
        'SIGNATURE_PREFIX' => '',
        'SIGNATURE_CONTENT' => '{body}',
        'SIGNATURE_PARTS' => null,
        'SIGNATURE_TOLERANCE' => null,
        'EVENT_NAME_PATH' => null,
        'EVENT_NAME_HEADER' => null,
        'EVENT_ID_PATH' => null,
        'EVENT_ID_HEADER' => null,
        'SUCCESS_STATUS' => 202,
    ];

    /**
     * Routing type of an API, the last segment of the URL /webhook/{type}: "Paddle" gives "paddle", "LemonSqueezy"
     * gives "lemon_squeezy".
     */
    public function defaultType(string $api): string
    {
        return u(self::shortName($api))->snake()->toString();
    }

    /**
     * Environment variable of the signing secret: "paddle" gives "PADDLE_WEBHOOK_SECRET".
     */
    public function secretVariable(string $type): string
    {
        return u($type)->snake()->upper()->append('_WEBHOOK_SECRET')->toString();
    }

    /**
     * Name of the consumer method of an event: "transaction.completed" gives "onTransactionCompleted",
     * "order_created" gives "onOrderCreated".
     */
    public function method(string $event): string
    {
        return 'on'.u($event)->replaceMatches('/[^A-Za-z0-9]+/', ' ')->camel()->title()->toString();
    }

    /**
     * @param string               $class     fully qualified class name of the parser
     * @param array<string, mixed> $constants parser constants (see CONSTANTS)
     */
    public function parser(string $class, string $type, array $constants, ?string $docs = null): string
    {
        if (!\is_string($constants['SIGNATURE_HEADER'] ?? null) || '' === $constants['SIGNATURE_HEADER']) {
            throw new \InvalidArgumentException('The signature header is required.');
        }
        if (null === ($constants['EVENT_NAME_PATH'] ?? null) && null === ($constants['EVENT_NAME_HEADER'] ?? null)) {
            throw new \InvalidArgumentException('The event name is required: a path in the payload, or a header.');
        }
        [$namespace, $short] = self::split($class);
        $lines = [];
        foreach (self::CONSTANTS as $name => $default) {
            if (\array_key_exists($name, $constants) && $constants[$name] !== $default) {
                $lines[] = \sprintf('    protected const %s = %s;', $name, self::export($constants[$name]));
            }
        }
        $doc = [\sprintf(' * Webhooks "%s": checks the signature of each delivery, and gives its event to the consumer.', $type)];
        if (null !== $docs) {
            $doc[] = ' *';
            $doc[] = ' * @see '.$docs;
        }

        return \sprintf(<<<'PHP'
            <?php

            namespace %s;

            use Snorky22\ApiForge\Core\Webhook\AbstractSignedRequestParser;

            /**
            %s
             */
            final class %s extends AbstractSignedRequestParser
            {
            %s
            }

            PHP, $namespace, implode("\n", $doc), $short, implode("\n", $lines));
    }

    /**
     * @param string       $class  fully qualified class name of the consumer
     * @param list<string> $events
     */
    public function consumer(string $class, string $type, array $events): string
    {
        [$namespace, $short] = self::split($class);

        return \sprintf(<<<'PHP'
            <?php

            namespace %s;

            use Snorky22\ApiForge\Core\Webhook\AbstractWebhookConsumer;
            use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
            use Symfony\Component\RemoteEvent\RemoteEvent;

            /**
             * The events "%s" the application handles (deliveries to POST /webhook/%s). Each method gets the payload of
             * the delivery; $event->getId() is the event id, to skip an event delivered twice.
             *
             * TODO: type each payload with the keys you use; the shape of a REST resource of the API can be imported with
             * a "@phpstan-import-type <Type> from <ResourceClass>" line in this docblock.
             */
            #[AsRemoteEventConsumer('%s')]
            final class %s extends AbstractWebhookConsumer
            {
                protected const HANDLERS = [
            %s
                ];

            %s    protected function onUnknownEvent(RemoteEvent $event): void
                {
                    // An event the provider sends but HANDLERS doesn't list: ignored (log it here to notice it)
                }
            }

            PHP, $namespace, $type, $type, $type, $short, $this->handlerLines($methods = $this->methodNames($events, [])), $this->methods($methods));
    }

    /**
     * The events of an existing consumer: the string keys of its HANDLERS (a key written as a constant is not known).
     *
     * @return list<string>
     */
    public function eventsOf(string $code): array
    {
        if (!preg_match('/protected const HANDLERS = \[/', $code)) {
            throw new \InvalidArgumentException('No HANDLERS constant found in the consumer: add the events by hand.');
        }
        $tokens = token_get_all($code);
        $events = [];
        $depth = null;
        $key = null;
        foreach ($tokens as $i => $token) {
            if (null === $depth) {
                // Start: the "[" after "HANDLERS ="
                if (\is_array($token) && \T_STRING === $token[0] && 'HANDLERS' === $token[1]) {
                    $depth = 0;
                }
                continue;
            }
            if ('[' === $token) {
                ++$depth;
            } elseif (']' === $token && 0 === --$depth) {
                break;
            } elseif (1 === $depth && \is_array($token) && \T_CONSTANT_ENCAPSED_STRING === $token[0]) {
                $key = self::literal($token[1]);
            } elseif (1 === $depth && \is_array($token) && \T_DOUBLE_ARROW === $token[0]) {
                if (null !== $key) {
                    $events[] = $key;
                }
                $key = null;
            } elseif (1 === $depth && ',' === $token) {
                $key = null;
            } elseif (1 === $depth && \is_array($token) && !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                $key = null;
            }
        }

        return $events;
    }

    /**
     * Adds events to an existing consumer: their HANDLERS entries and empty methods, named so as not to clash with
     * the methods of the class. The rest is kept as it is.
     *
     * @param list<string> $events
     */
    public function addEvents(string $code, array $events): string
    {
        $events = array_values(array_diff(array_unique($events), $this->eventsOf($code)));
        if ([] === $events) {
            return $code;
        }
        preg_match_all('/function\s+(\w+)\s*\(/', $code, $m);
        $methods = $this->methodNames($events, $m[1]);
        $code = (string) preg_replace_callback('/(protected const HANDLERS = \[.*?)(\n    \];)/s', function (array $m) use ($methods): string {
            $entries = rtrim($m[1]);
            // A last entry written without its trailing comma
            if (!str_ends_with($entries, '[') && !str_ends_with($entries, ',')) {
                $entries .= ',';
            }

            return $entries."\n".$this->handlerLines($methods).$m[2];
        }, $code, 1);
        $code = str_replace("\r\n", "\n", $code);
        $written = $this->methods($methods);
        $at = strpos($code, '    protected function onUnknownEvent(');
        if (false === $at) {
            $at = (int) strrpos($code, '}');
            $written = "\n".rtrim($written)."\n";
        }

        return substr_replace($code, $written, $at, 0);
    }

    /**
     * The routing configuration of a webhook type, yours to edit.
     */
    public function routing(string $type, string $parser, ?string $hint = null): string
    {
        $variable = $this->secretVariable($type);

        return \sprintf(<<<'YAML'
            # Webhooks "%1$s", written by api-forge:make:webhook: yours to edit. Deliveries arrive at POST /webhook/%1$s.
            # The signing secret comes from the environment, e.g. in .env.local: %2$s=...%4$s
            # Empty by default, so that the application boots without it; every delivery is then rejected.

            parameters:
                env(%2$s): ''
            framework:
                webhook:
                    routing:
                        %1$s:
                            service: %3$s
                            secret: '%%env(%2$s)%%'

            YAML, $type, $variable, $parser, null === $hint ? '' : "\n# ".$hint);
    }

    /**
     * A minimal payload of an event, for api-forge:webhook:send: the event name (and a test id) where the parser
     * reads them, and an empty "data".
     *
     * @param array<string, mixed> $constants
     */
    public function samplePayload(array $constants, string $event): string
    {
        $payload = [];
        foreach (['EVENT_ID_PATH' => 'evt_test_1', 'EVENT_NAME_PATH' => $event] as $constant => $value) {
            if (\is_string($path = $constants[$constant] ?? null)) {
                $payload = self::withPath($payload, explode('.', $path), $value);
            }
        }
        $payload['data'] ??= new \stdClass();

        return json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $array
     * @param list<string>         $keys
     *
     * @return array<string, mixed>
     */
    private static function withPath(array $array, array $keys, string $value): array
    {
        $key = array_shift($keys);
        if (null === $key) {
            return $array;
        }
        $array[$key] = [] === $keys ? $value : self::withPath(\is_array($array[$key] ?? null) ? $array[$key] : [], $keys, $value);

        return $array;
    }

    /**
     * The method of each event, made unique: among them, against the methods of the class (PHP ignores the case of
     * method names) and onUnknownEvent(). "a.b" and "a_b" give onAB() and onAB2().
     *
     * @param list<string> $events
     * @param list<string> $taken  methods of the class
     *
     * @return array<string, string> event => method
     */
    private function methodNames(array $events, array $taken): array
    {
        $used = array_flip(array_map('strtolower', [...$taken, 'onUnknownEvent']));
        $methods = [];
        foreach ($events as $event) {
            $method = $base = $this->method($event);
            for ($i = 2; isset($used[strtolower($method)]); ++$i) {
                $method = $base.$i;
            }
            $used[strtolower($method)] = true;
            $methods[$event] = $method;
        }

        return $methods;
    }

    /**
     * The value of a PHP string literal ('…' or "…" without variables).
     */
    private static function literal(string $token): string
    {
        $inner = substr($token, 1, -1);

        return "'" === $token[0] ? strtr($inner, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($inner);
    }

    /**
     * @param array<string, string> $methods event => method
     */
    private function handlerLines(array $methods): string
    {
        return implode("\n", array_map(static fn (string $event, string $method): string => \sprintf('        %s => \'%s\',', self::export($event), $method), array_keys($methods), $methods));
    }

    /**
     * @param array<string, string> $methods event => method
     */
    private function methods(array $methods): string
    {
        $code = '';
        foreach ($methods as $event => $method) {
            $code .= \sprintf(<<<'PHP'
                    /**
                     * "%s"
                     *
                     * @param array<string, mixed> $payload
                     */
                    protected function %s(array $payload, RemoteEvent $event): void
                    {
                        // TODO: what the application does
                    }


                PHP, $event, $method);
        }

        return $code;
    }

    private static function export(mixed $value): string
    {
        if (\is_array($value)) {
            return '['.implode(', ', array_map(static fn (int|string $key, mixed $item): string => self::export($key).' => '.self::export($item), array_keys($value), $value)).']';
        }

        return \is_string($value) ? "'".addcslashes($value, "'\\")."'" : var_export($value, true);
    }

    /**
     * @return array{string, string} namespace, short name
     */
    private static function split(string $class): array
    {
        $class = trim($class, '\\');
        $at = (int) strrpos($class, '\\');

        return [substr($class, 0, $at), substr($class, $at + 1)];
    }

    private static function shortName(string $name): string
    {
        $parts = explode('\\', trim($name, '\\'));

        return end($parts);
    }
}
