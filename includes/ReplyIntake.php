<?php

declare(strict_types=1);

namespace App;

/**
 * ReplyIntake — shared wiring for the reply-classification intake seam
 * (reply_lab.php manual page + api/ingest_reply.php ingestion endpoint).
 *
 * Built against the documented signatures of the sibling agent's classes:
 *   \App\Actions\ClassifyReplyAction::classify(string $subject, string $body,
 *       string $threadContext = ''): array
 *     → ['intent','needs_human','urgency','confidence','source','latency_ms']
 *   \App\ReplyRouter::route(array $verdict, ?int $leadId, ?string $email): array
 *
 * The sibling classes may not exist yet when this file runs; both builders
 * fail loudly with a clear message instead of a fatal error, and the router
 * supports either a static or an instance `route()` method.
 */
class ReplyIntake
{
    /**
     * Classify a reply and return a normalized verdict array.
     *
     * @throws \RuntimeException when the classifier is missing or misbehaves.
     */
    public static function classifyReply(string $subject, string $body, string $threadContext = ''): array
    {
        $fqcn = '\\App\\Actions\\ClassifyReplyAction';
        if (!class_exists($fqcn)) {
            throw new \RuntimeException(
                'ClassifyReplyAction is not available yet (includes/Actions/ClassifyReplyAction.php).'
            );
        }

        $instance = self::instantiate(new \ReflectionClass($fqcn));
        if (!method_exists($instance, 'classify')) {
            throw new \RuntimeException('ClassifyReplyAction::classify() is missing.');
        }

        $verdict = $instance->classify($subject, $body, $threadContext);
        if (!is_array($verdict)) {
            throw new \RuntimeException('ClassifyReplyAction::classify() did not return an array.');
        }

        return self::normalizeVerdict($verdict);
    }

    /**
     * Run the routing step on an already-computed verdict.
     *
     * @throws \RuntimeException when the router is missing or misbehaves.
     */
    public static function applyRouting(array $verdict, ?int $leadId, ?string $email): array
    {
        $fqcn = '\\App\\ReplyRouter';
        if (!class_exists($fqcn)) {
            throw new \RuntimeException(
                'ReplyRouter is not available yet (includes/ReplyRouter.php).'
            );
        }

        $ref = new \ReflectionClass($fqcn);
        if (!$ref->hasMethod('route')) {
            throw new \RuntimeException('ReplyRouter::route() is missing.');
        }

        $method = $ref->getMethod('route');
        $result = $method->isStatic()
            ? $fqcn::route($verdict, $leadId, $email)
            : self::instantiate($ref)->route($verdict, $leadId, $email);

        if (!is_array($result)) {
            throw new \RuntimeException('ReplyRouter::route() did not return an array.');
        }

        return $result;
    }

    /**
     * Normalize a verdict to a stable shape. Unknown/missing fields get
     * safe defaults (needs_human defaults true — fail toward human review).
     * Field types are preserved from the classifier: `urgency` is an int
     * 1-10 (per ClassifyReplyAction), not cast to string; the heuristic
     * `note` (documented in ClassifyReplyAction::classify()) is preserved.
     */
    public static function normalizeVerdict(array $verdict): array
    {
        return [
            'intent'      => isset($verdict['intent']) ? (string)$verdict['intent'] : 'unknown',
            'needs_human' => isset($verdict['needs_human']) ? (bool)$verdict['needs_human'] : true,
            'urgency'     => $verdict['urgency'] ?? 'normal',
            'confidence'  => isset($verdict['confidence']) ? (float)$verdict['confidence'] : 0.0,
            'source'      => isset($verdict['source']) ? (string)$verdict['source'] : 'unknown',
            'latency_ms'  => isset($verdict['latency_ms']) ? (int)$verdict['latency_ms'] : 0,
            'note'        => isset($verdict['note']) ? (string)$verdict['note'] : null,
        ];
    }

    /**
     * Append a JSONL intake record to logs/ingest_replies.jsonl.
     * Never throws — logging must not break the request.
     */
    public static function logIntake(array $entry): void
    {
        try {
            $dir = __DIR__ . '/../logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0750, true);
            }
            $entry['logged_at'] = date('c');
            @file_put_contents(
                $dir . '/ingest_replies.jsonl',
                json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $ignored) {
            // Logging is best-effort.
        }
    }

    /**
     * Reflection-based instantiation tolerant of either a no-arg constructor
     * or the codebase's Action-style constructor (\App\PDO + SmartLLMRouter).
     */
    private static function instantiate(\ReflectionClass $ref): object
    {
        $ctor = $ref->getConstructor();
        if ($ctor === null || $ctor->getNumberOfRequiredParameters() === 0) {
            return $ref->newInstance();
        }

        $pdo = \App\Database::getConnection();
        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($typeName === \App\PDO::class || $typeName === 'PDO') {
                $args[] = $pdo;
            } elseif ($typeName === \App\Routers\SmartLLMRouter::class) {
                $args[] = new \App\Routers\SmartLLMRouter($pdo);
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                throw new \RuntimeException(
                    'Cannot instantiate ' . $ref->getName()
                    . ': unresolvable constructor parameter $' . $param->getName() . '.'
                );
            }
        }

        return $ref->newInstanceArgs($args);
    }
}
