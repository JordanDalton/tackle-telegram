# tackle-telegram

Drive a [Laravel Tackle](https://github.com/JordanDalton/laravel-tackle) coding
session from Telegram.

```bash
php artisan tackle:telegram
```

Then message your bot. Send a task, watch it work, tap **Yes** when it asks
before running something destructive.

## Why Telegram, and not a hosted bot

**Outbound only.** `getUpdates` is a long poll your machine opens *to*
Telegram — no public URL, no tunnel, no hosted process, no port forwarding.

That is the whole reason this exists. A laptop behind NAT can be driven from a
train, because **Telegram's servers are the relay**, and they are free. Every
other route to remote coding — a phone on your LAN, a webhook needing a public
URL — has to solve reachability first. This one does not have the problem.

## What it took to build

Almost nothing, and that is the point.

Tackle Remote already separates the agent from the way a human reaches it:

| Piece | Job | Knows about browsers? |
|---|---|---|
| `SessionLoop` | Pops the inbox, drives the agent, appends events | No |
| `RemoteState` | inbox / events / question / answers, as files | No |
| `RemoteInteraction` | `InteractionPolicy` over that protocol | No |
| `server/router.php` | HTTP transport | Yes — and only this |

So this package adds **no new `InteractionPolicy`** and no agent code. It is a
pump between that state directory and a chat: drain events out, offer the
pending question as buttons, push replies back into the inbox. Every
`ConfirmAction`, every destructive `RunArtisan`, every `MutateDatabase` commit
routes to Telegram automatically, because they already routed through
`InteractionPolicy`.

## Security: read this part

**A bot token is far more discoverable than a pairing code on your LAN, and
anyone who can message this bot can run code on the machine hosting it.**

So `allowed_chats` is not a convenience — it is the entire security model. An
unlisted chat is not answered, not rate-limited, not asked to authenticate. Its
message is dropped before it can reach the agent. An empty allowlist means
nobody, and the command refuses to start rather than quietly accepting
everyone.

```env
TACKLE_TELEGRAM_TOKEN=123456:ABC...       # @BotFather
TACKLE_TELEGRAM_CHATS=987654321           # comma-separated chat ids
```

**Your code goes to Telegram.** The agent will echo file contents, stack
traces, and whatever else it reads into the chat. For your own projects that is
a choice you can make. For a client's, or anything with a compliance boundary,
it may simply be a no — and it is better to decide that now than to discover it
in a transcript.

## What it does

- **Streams into one message.** Assistant text arrives as deltas; posting each
  one would be unusable, so a turn accumulates into a single message that is
  edited in place. A new one starts when Telegram's size limit is reached,
  rather than truncating the answer.
- **Tool calls, statuses and errors** appear as they happen.
- **Approvals as inline buttons**, offered once. A tap answers only the
  question that is *currently* open — by the time you reach your phone the
  agent may have moved on, and answering a question it is no longer asking is
  worse than missing one.
- **`/clear`** resets the conversation.
- **Survives Telegram going away.** A transport that dies would take the agent
  with it, so a failed pump is reported into the session and the loop carries
  on.

## Status

Sketch. The transport is tested (13 tests, against an in-memory Telegram — the
security model is not something to verify by hand against a live chat), and
`tackle:telegram` is wired end to end. What has not happened yet is a real bot,
a real token, and a real session.

## Related

- [laravel-tackle](https://github.com/JordanDalton/laravel-tackle) — the harness
- [laravel-tackle-remote](https://github.com/JordanDalton/laravel-tackle-remote) — the state protocol and session loop this reuses
- [tackle-mobile](https://github.com/JordanDalton/tackle-mobile) — the native client, which has the reachability problem this one does not
