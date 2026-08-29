<?php

namespace TackleTelegram;

use Closure;
use TackleRemote\Support\RemoteState;

/**
 * A state directory that tells someone when something happens in it.
 *
 * SessionLoop's onIdle hook only fires when the inbox is empty — that is what
 * "idle" means. So a transport hung off it sees nothing at all for the length
 * of a turn, and everything at once when the turn ends. Live, that reads badly:
 * the file edit lands, the browser hot-reloads with the change, and only then
 * does the chat begin explaining what it was about to do.
 *
 * The browser UI does not have this problem because it polls the state
 * directory on its own clock, independent of the loop. This is the same idea
 * from the other side: emit() is the moment something happened, so it is the
 * moment to push.
 */
class StreamingState extends RemoteState
{
    private ?Closure $onEmit = null;

    public function onEmit(Closure $callback): void
    {
        $this->onEmit = $callback;
    }

    public function emit(string $type, array $data = []): void
    {
        parent::emit($type, $data);

        ($this->onEmit)?->__invoke($type);
    }
}
