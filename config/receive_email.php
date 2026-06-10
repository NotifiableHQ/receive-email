<?php

use Notifiable\ReceiveEmail\ApplyFilters;
use Notifiable\ReceiveEmail\StoreAndDispatch;

return [

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the disk that should be used
    | to store incoming emails from Postfix pipe.
    |
    */

    'storage-disk' => 'local',

    /*
    |--------------------------------------------------------------------------
    | Message Size Limit
    |--------------------------------------------------------------------------
    |
    | The maximum size of an incoming email in bytes. This value is written
    | to Postfix's message_size_limit parameter during setup. The default
    | of 26214400 bytes (25MB) is generous for most use cases.
    |
    */

    'message-size-limit' => 26214400,

    /*
    |--------------------------------------------------------------------------
    | Pipe Concurrency
    |--------------------------------------------------------------------------
    |
    | The maximum number of concurrent pipe processes Postfix will spawn
    | for email delivery. This maps to the maxproc field in master.cf.
    | Lower values protect the server under load; higher values increase
    | throughput. Start with 2-4 and tune based on server resources.
    |
    */

    'pipe-concurrency' => 4,

    /*
    |--------------------------------------------------------------------------
    | Mail Log Path
    |--------------------------------------------------------------------------
    |
    | The Postfix mail log read by the notifiable:import-mail-log command
    | to observe SMTP-time rejections. The app user needs read access
    | (on Ubuntu, membership in the adm group).
    |
    */

    'mail-log-path' => '/var/log/mail.log',

    /*
    |--------------------------------------------------------------------------
    | Mail Log Offset Path
    |--------------------------------------------------------------------------
    |
    | Where the importer persists its read position between runs so each
    | rejection is observed at least once. The position is updated after
    | every dispatched rejection and written atomically.
    |
    */

    'mail-log-offset-path' => storage_path('app/receive_email/mail-log-offset.json'),

    /*
    |--------------------------------------------------------------------------
    | Email Model Table
    |--------------------------------------------------------------------------
    |
    | Here you may specify the table name for the Email model.
    |
    */

    'email-table' => 'emails',

    /*
    |--------------------------------------------------------------------------
    | Sender Model Table
    |--------------------------------------------------------------------------
    |
    | Here you may specify the table name for the Sender model.
    |
    */

    'sender-table' => 'senders',

    /*
    |--------------------------------------------------------------------------
    | Pipe Command Class
    |--------------------------------------------------------------------------
    |
    | When Postfix receives an email this pipe command is executed, given
    | the parsed mail. Here you may customize the processing.
    |
    */

    'pipe-command' => StoreAndDispatch::class,

    /*
    |--------------------------------------------------------------------------
    | Pipe Filter Class
    |--------------------------------------------------------------------------
    |
    | When Postfix receives an email it goes through this filter first, given
    | the parsed mail. Here you may customize the processing.
    |
    */

    'pipe-filter' => ApplyFilters::class,

    /*
    |--------------------------------------------------------------------------
    | Email Filters
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom Pipe-time Filters that should be applied to
    | incoming emails after acceptance, in the order they are listed. Each
    | class must implement EmailFilterContract. The built-in sender list
    | filters are enforced at SMTP time (see the lists below) and are not
    | evaluated here.
    |
    */

    'email-filters' => [],

    /*
    |--------------------------------------------------------------------------
    | Sender Lists (Envelope Sender)
    |--------------------------------------------------------------------------
    |
    | These lists match the Envelope Sender — the SMTP `MAIL FROM` address,
    | the identity SPF verifies — and are compiled into Postfix access maps
    | by `php artisan notifiable:sync-postfix`, rejecting mail during the
    | SMTP transaction. When any whitelist has entries, senders not present
    | in a whitelist are rejected; with only blacklists, listed senders are
    | rejected and everyone else is accepted. Re-run the sync command (e.g.
    | from a deploy hook) whenever these lists change.
    |
    */

    'sender-domain-whitelist' => [],

    'sender-domain-blacklist' => [],

    'sender-address-whitelist' => [],

    'sender-address-blacklist' => [],

];
