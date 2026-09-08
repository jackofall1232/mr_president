<?php
/**
 * Engine-level exception carrying a stable machine-readable code.
 *
 * @package MrPresident\Engine
 */

declare(strict_types=1);

namespace MrPresident\Engine;

use RuntimeException;
use Throwable;

/**
 * The only exception type the engine throws.
 *
 * PHP reserves `Exception::getCode()` for integer codes, so the engine carries its own
 * string code alongside it and exposes it through `code()`. The plugin layer maps these
 * codes onto HTTP statuses; nothing in the engine inspects the message text.
 */
class EngineException extends RuntimeException
{
    /** Scenario id is unknown or malformed. */
    const UNKNOWN_SCENARIO = 'unknown_scenario';

    /** A decision was submitted while no event is awaiting one. */
    const NO_ACTIVE_EVENT = 'no_active_event';

    /** The active event already has a resolved choice. */
    const EVENT_ALREADY_RESOLVED = 'event_already_resolved';

    /** The submitted choice id does not belong to the active event. */
    const UNKNOWN_CHOICE = 'unknown_choice';

    /** The turn cannot advance because the active event is unresolved. */
    const DECISION_REQUIRED = 'decision_required';

    /** Event id is unknown or malformed. */
    const UNKNOWN_EVENT = 'unknown_event';

    /** Content on disk (or a persisted state document) failed validation. */
    const INVALID_CONTENT = 'invalid_content';

    /**
     * Machine-readable code, e.g. `unknown_choice`.
     *
     * @var string
     */
    private string $engineCode;

    /**
     * @param string         $code     Machine-readable code; see the class constants.
     * @param string         $message  Human-readable, developer-facing message.
     * @param Throwable|null $previous Optional previous throwable.
     */
    public function __construct(string $code, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->engineCode = $code;
    }

    /**
     * The machine-readable code for this failure.
     *
     * @return string
     */
    public function code(): string
    {
        return $this->engineCode;
    }

    /**
     * Convenience constructor for content validation failures.
     *
     * Always names the offending file and key, per spec section 7.
     *
     * @param string $file   Content file the problem was found in (path relative to data/).
     * @param string $key    Key, id or path that is missing or invalid.
     * @param string $reason Short explanation.
     *
     * @return EngineException
     */
    public static function invalidContent(string $file, string $key, string $reason): EngineException
    {
        return new self(
            self::INVALID_CONTENT,
            sprintf('Invalid content in "%s" at "%s": %s', $file, $key, $reason)
        );
    }
}
