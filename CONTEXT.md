# Receive Email

A Laravel package that turns a server into a receive-only mail server: Postfix accepts inbound mail and pipes it into the Laravel app, which filters, stores, and dispatches events. The server never sends, relays, or bounces mail.

## Language

**Envelope Sender**:
The address given in the SMTP `MAIL FROM` command. The only sender identity SPF validates, and the one available before a message body is transferred.
_Avoid_: return-path, bounce address

**Header Sender**:
The address in the message's `Sender:` header, falling back to `From:`. What a human sees in a mail client; trivially forgeable and only available after the message body arrives.
_Avoid_: sender (unqualified), from address

**SMTP-time Rejection**:
Refusing a message with a 5xx response during the SMTP transaction, before it is accepted. The sending server is responsible for notifying its sender; we never originate a bounce.
_Avoid_: blocking, bouncing

**Pipe-time Filter**:
A PHP filter (`EmailFilterContract`) that runs after a message has been accepted and piped into the Laravel app, with full parsed-header access.
_Avoid_: email filter (unqualified)

**Discard**:
Accepting a message at SMTP and then dropping it without ever generating a bounce, while dispatching `EmailRejected` so the application retains visibility. The only permissible fate for mail rejected after acceptance.
_Avoid_: reject (for post-acceptance mail), bounce

**Receive-only**:
The server accepts inbound mail and originates nothing — no sending, no relaying, no bounces. Enforced in Postfix (`default_transport = error`, `relay_transport = error`) and assumed by every other decision.
