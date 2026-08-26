<?php

namespace App\Inbox;

use App\Enums\Channel;
use App\Inbox\Contracts\ChannelAdapter;
use RuntimeException;

/**
 * Looks up the adapter for a channel. The core resolves adapters through
 * here rather than type-hinting a concrete one, so no code above this line
 * ever names a channel implementation.
 */
class ChannelRegistry
{
    /**
     * @param  array<string, ChannelAdapter>  $adapters  keyed by channel value
     */
    public function __construct(private readonly array $adapters) {}

    public function for(Channel $channel): ChannelAdapter
    {
        return $this->adapters[$channel->value]
            ?? throw new RuntimeException("No adapter is registered for the {$channel->value} channel.");
    }

    public function supports(Channel $channel): bool
    {
        return isset($this->adapters[$channel->value]);
    }

    /**
     * Every channel the installation can actually talk on.
     *
     * @return list<Channel>
     */
    public function channels(): array
    {
        return array_map(
            fn (ChannelAdapter $adapter): Channel => $adapter->channel(),
            array_values($this->adapters),
        );
    }
}
