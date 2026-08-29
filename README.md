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

## Install

```bash
composer require jordandalton/tackle-telegram
```

Get a token from [@BotFather](https://t.me/botfather):

```env
TACKLE_TELEGRAM_TOKEN=123456:ABC...
```

Then find out which chat is allowed to drive it. Open your bot in Telegram, send
it anything, and:

```bash
php artisan tackle:telegram --pair
```

```
  8271428961  Jordan D @heliguy84  (private)
  TACKLE_TELEGRAM_CHATS=8271428961

  Allow Jordan D @heliguy84 to drive this project? (yes/no) [no]
```

Say yes and it writes the line into `.env` for you, appending rather than
replacing so pairing a second device does not revoke the first. Then start a
session:

```bash
php artisan tackle:telegram
```

`--pair` only listens and reports. It writes nothing and acts on nothing, so
anyone who finds your bot can make it print their id — and none of them can
make it do anything. The decision stays with you.

**Stop any running session first.** Telegram delivers each update exactly once,
to whoever asks for it first, so a session polling alongside `--pair` will take
turns swallowing your messages with no error anywhere to explain where they
went.

### In your dev script

`composer dev` runs its processes under `concurrently --kill-others`, which
tears the whole environment down the moment any one of them exits. Use
`--if-configured` so a machine without a Telegram setup idles instead of taking
the server, queue and Vite with it:

```json
"dev": [
    "Composer\\Config::disableProcessTimeout",
    "npx concurrently -c \"#93c5fd,#c4b5fd,#fdba74,#22d3ee\" \"php artisan serve\" \"php artisan queue:listen --tries=1\" \"npm run dev\" \"php artisan tackle:telegram --if-configured\" --names=server,queue,vite,telegram --kill-others"
]
```

Run on its own without a token it still fails loudly, which is what you want
when you meant to start it.

### Starting over

`/clear` forgets the conversation. `--session=name` keeps a separate one, with
its own history. Restarting the command **resumes** rather than resets — that is
what the "Resumed session" line means.

## Status

Working, and flown against a real bot on a real project: a task sent from a
phone edited a Vue component, narrated what it was doing as it went, and cost
about a penny.

38 tests, against an in-memory Telegram — the security model is not something to
verify by hand against a live chat.

`TACKLE_TELEGRAM_DEBUG=1` traces what the pump is doing, which is how the worst
bug in it was found: a turn being silently edited into a message from an earlier
one, so the files changed while the chat appeared dead.

## Related

- [laravel-tackle](https://github.com/JordanDalton/laravel-tackle) — the harness
- [laravel-tackle-remote](https://github.com/JordanDalton/laravel-tackle-remote) — the state protocol and session loop this reuses
- [tackle-mobile](https://github.com/JordanDalton/tackle-mobile) — the native client, which has the reachability problem this one does not
