<?php

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

    'pipe-command' => \Notifiable\ReceiveEmail\StoreAndDispatch::class,

    /*
    |--------------------------------------------------------------------------
    | Email Filters
    |--------------------------------------------------------------------------
    |
    | Here you may specify the email filters that should be applied to incoming
    | emails. These filters will be applied in the order that they are listed
    | in this array. You may use the default filters or create your own.
    |
    */

    'email-filters' => [
        // \Notifiable\ReceiveEmail\Filters\SenderDomainWhitelistFilter::class,
        // \Notifiable\ReceiveEmail\Filters\SenderDomainBlacklistFilter::class,
        // \Notifiable\ReceiveEmail\Filters\SenderAddressWhitelistFilter::class,
        // \Notifiable\ReceiveEmail\Filters\SenderAddressBlacklistFilter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sender Domain Whitelist
    |--------------------------------------------------------------------------
    |
    | Here you may specify a list of domains that the mail server will allow
    | receiving emails from. If the sender's domain is in the list,
    | the email will be accepted.
    |
    */

    'sender-domain-whitelist' => [],

    /*
    |--------------------------------------------------------------------------
    | Sender Domain Blacklist
    |--------------------------------------------------------------------------
    |
    | Here you may specify a list of domains that the mail server will not
    | allow receiving emails from. If the sender's domain is in
    | the list, the email will be rejected.
    |
    */

    'sender-domain-blacklist' => [],

    /*
    |--------------------------------------------------------------------------
    | Sender Address Whitelist
    |--------------------------------------------------------------------------
    |
    | Here you may specify a list of email addresses that the mail server will
    | allow receiving emails from. If the sender's email address is in
    | the list, the email will be accepted.
    |
    */

    'sender-address-whitelist' => [],

    /*
    |--------------------------------------------------------------------------
    | Sender Address Blacklist
    |--------------------------------------------------------------------------
    |
    | Here you may specify a list of email addresses that the mail server will
    | not allow receiving emails from. If the sender's email address is
    | in the list, the email will be rejected.
    |
    */

    'sender-address-blacklist' => [],

];
