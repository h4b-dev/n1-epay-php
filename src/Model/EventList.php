<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;
use N1co\EPay\ResponseInfo;

/**
 * The answer of events->list(): events, newest first. Iterable and countable.
 *
 * @implements \IteratorAggregate<int, Event>
 */
final readonly class EventList implements \IteratorAggregate, \Countable
{
    private const KNOWN = ['data'];

    /**
     * @param list<Event>          $data
     * @param array<string, mixed> $extra Fields this SDK version does not know.
     */
    public function __construct(
        public array $data,
        public array $extra = [],
        public ?ResponseInfo $lastResponse = null,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or has another type
     */
    public static function fromArray(array $data, ?ResponseInfo $lastResponse = null): self
    {
        return self::read(Reader::of($data, 'event_list'), $lastResponse);
    }

    /** @internal */
    public static function read(Reader $r, ?ResponseInfo $lastResponse = null): self
    {
        $events = [];
        foreach ($r->list('data') as $index => $item) {
            $events[] = Event::read(Reader::of($item, \sprintf('%s.data[%d]', $r->path(), $index)));
        }

        return new self($events, $r->extra(self::KNOWN), $lastResponse);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['data' => array_map(static fn(Event $event): array => $event->toArray(), $this->data)] + $this->extra;
    }

    /**
     * @return \ArrayIterator<int, Event>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return \count($this->data);
    }
}
