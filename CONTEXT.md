# Receive Email

A Laravel package that turns a server into a receive-only mail server: Postfix accepts inbound mail and pipes it into the Laravel app, which filters, stores, and dispatches events. The server never sends, relays, or bounces mail.

## Language

**Envelope Sender**:
The address given in the SMTP `MAIL FROM` command. The only sender identity SPF validates, and the one available before a message body is transferred.
_Avoid_: return-path, bounce address

**Header Sender**:
The address in the message's `Sender:` header, falling back to `From:`. What a human sees in a mail client; trivially forgeable and only available after the message body arrives.
_Avoid_: sender (unqualified), from address

**Envelope Recipient**:
An address given in an SMTP `RCPT TO` command. The actual delivery target of a message, independent of the `To:`/`Cc:` headers a human sees. A single accepted message may have several.
_Avoid_: recipient (unqualified), to address

**SMTP-time Rejection**:
Refusing a message with a 5xx response during the SMTP transaction, before it is accepted. The sending server is responsible for notifying its sender; we never originate a bounce.
_Avoid_: blocking, bouncing

**Pipe-time Filter**:
A PHP filter (`EmailFilterContract`) that runs after a message has been accepted and piped into the Laravel app, with full parsed-header access.
_Avoid_: email filter (unqualified)

**Discard**:
Accepting a message at SMTP and then dropping it without ever generating a bounce — dispatching `EmailRejected` so the application retains visibility. The fate of mail rejected by a Pipe-time Filter.
_Avoid_: reject (for post-acceptance mail), bounce

**Malformed Mail**:
Accepted mail whose headers cannot be parsed. It is kept, never discarded or bounced: the raw message is stored with its envelope metadata and announced to the application as a distinct event. Invisible to Pipe-time Filters, which require parsed headers.
_Avoid_: invalid mail, unparseable mail (as a fate — it describes a condition, not a loss)

**Receive-only**:
The server accepts inbound mail and originates nothing — no sending, no relaying, no bounces. Enforced in Postfix (`default_transport = error`, `relay_transport = error`) and assumed by every other decision.
