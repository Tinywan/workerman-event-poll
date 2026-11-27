<?php

declare(strict_types=1);

namespace Io\Poll {
    use RuntimeException;

    interface Handle {}

    enum Backend
    {
        case Auto;
        case Poll;
        case Epoll;
        case Kqueue;
        case EventPorts;
        case WSAPoll;

        /**
         * @return list<self>
         */
        public static function getAvailableBackends(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function supportsEdgeTriggering(): bool
        {
            return true;
        }
    }

    enum Event
    {
        case Read;
        case Write;
        case Error;
        case HangUp;
        case ReadHangUp;
        case OneShot;
        case EdgeTriggered;
    }

    class PollException extends RuntimeException {}

    class FailedPollOperationException extends PollException
    {
        public const int ERROR_NONE = 0;
        public const int ERROR_SYSTEM = 1;
        public const int ERROR_NOMEM = 2;
        public const int ERROR_INVALID = 3;
        public const int ERROR_EXISTS = 4;
        public const int ERROR_NOTFOUND = 5;
        public const int ERROR_TIMEOUT = 6;
        public const int ERROR_INTERRUPTED = 7;
        public const int ERROR_PERMISSION = 8;
        public const int ERROR_TOOBIG = 9;
        public const int ERROR_AGAIN = 10;
        public const int ERROR_NOSUPPORT = 11;
    }

    class FailedPollWaitException extends FailedPollOperationException {}

    final class Watcher
    {
        public function getHandle(): Handle
        {
            throw new PollException();
        }

        /**
         * @return list<Event>
         */
        public function getWatchedEvents(): array
        {
            return [];
        }

        /**
         * @return list<Event>
         */
        public function getTriggeredEvents(): array
        {
            return [];
        }

        public function getData(): mixed
        {
            return null;
        }

        public function hasTriggered(Event $event): bool
        {
            return false;
        }

        public function isActive(): bool
        {
            return false;
        }

        /**
         * @param list<Event> $events
         */
        public function modify(array $events, mixed $data = null): void {}

        /**
         * @param list<Event> $events
         */
        public function modifyEvents(array $events): void {}

        public function modifyData(mixed $data): void {}

        public function remove(): void {}
    }

    final class Context
    {
        public function __construct(Backend $backend = Backend::Auto) {}

        /**
         * @param list<Event> $events
         */
        public function add(Handle $handle, array $events, mixed $data = null): Watcher
        {
            throw new PollException();
        }

        /**
         * @return list<Watcher>
         */
        public function wait(?\Time\Duration $timeout = null, ?int $maxEvents = null): array
        {
            return [];
        }

        public function getBackend(): Backend
        {
            return Backend::Auto;
        }
    }
}

namespace {
    final class StreamPollHandle implements \Io\Poll\Handle
    {
        /**
         * @param resource $stream
         */
        public function __construct($stream) {}

        /**
         * @return resource
         */
        public function getStream()
        {
            throw new \RuntimeException();
        }

        public function isValid(): bool
        {
            return true;
        }
    }
}

namespace Time {
    final class Duration
    {
        public static function fromSeconds(int $seconds, int $nanoseconds = 0): self
        {
            return new self();
        }

        public static function fromNanoseconds(int $nanoseconds): self
        {
            return new self();
        }

        public static function fromMicroseconds(int $microseconds): self
        {
            return new self();
        }

        public static function fromMilliseconds(int $milliseconds): self
        {
            return new self();
        }

        public static function fromMinutes(int $minutes): self
        {
            return new self();
        }

        public static function fromHours(int $hours): self
        {
            return new self();
        }

        public static function fromIso8601DurationString(string $specification): self
        {
            return new self();
        }

        public function negate(): self
        {
            return new self();
        }

        public function absolute(): self
        {
            return new self();
        }

        public function add(self $duration): self
        {
            return new self();
        }

        public function sub(self $duration): self
        {
            return new self();
        }

        public function multiplyBy(int $factor): self
        {
            return new self();
        }

        public function divideBy(int $divisor): self
        {
            return new self();
        }

        public static function compare(self $a, self $b): int
        {
            return 0;
        }
    }
}
